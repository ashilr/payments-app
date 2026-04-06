<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\DTO\Response\ApiResponse;
use App\Exception\ValidationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts every exception thrown on /api/* routes into a consistent JSON response.
 *
 * All responses go through ApiResponse so the envelope shape is identical whether
 * the call succeeded or failed:
 *
 *   { "success": false, "message": "...", "data": null, "errors": { ... } | null }
 *
 * Status-code mapping:
 *   ValidationException          → 400  errors map populated
 *   InvalidArgumentException     → 400  errors null
 *   DomainException              → 422  errors null
 *   RuntimeException             → 422  errors null
 *   HttpExceptionInterface       → exception's own status code
 *   Anything else (debug=false)  → 500  generic message, no trace
 *   Anything else (debug=true)   → 500  full exception detail for development
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $event->setResponse($this->buildResponse($event->getThrowable()));
    }

    private function buildResponse(\Throwable $exception): JsonResponse
    {
        // Field-level validation — includes the per-field errors map
        if ($exception instanceof ValidationException) {
            return new JsonResponse(
                ApiResponse::error('Validation failed.', $exception->getErrors()),
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Bad caller input — wrong account number, empty reason, etc.
        if ($exception instanceof \InvalidArgumentException) {
            return new JsonResponse(
                ApiResponse::error($exception->getMessage()),
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Framework HTTP exceptions — must be checked BEFORE RuntimeException because
        // HttpException (and its subclasses: NotFoundHttpException, MethodNotAllowedHttpException,
        // etc.) extends \RuntimeException. Checking RuntimeException first would swallow these
        // and return 422 instead of the correct 404 / 405 / etc. status code.
        if ($exception instanceof HttpExceptionInterface) {
            $message = $exception->getMessage()
                ?: Response::$statusTexts[$exception->getStatusCode()]
                ?? 'HTTP error';

            return new JsonResponse(
                ApiResponse::error($message),
                $exception->getStatusCode(),
                $exception->getHeaders(),
            );
        }

        // Business-rule violation — blocked account, insufficient balance, fraud alert, etc.
        if ($exception instanceof \DomainException) {
            return new JsonResponse(
                ApiResponse::error($exception->getMessage()),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Application-level failure — account not found, lock conflict, etc.
        if ($exception instanceof \RuntimeException) {
            return new JsonResponse(
                ApiResponse::error($exception->getMessage()),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Unexpected errors — expose detail only in debug mode
        return new JsonResponse(
            $this->unexpectedErrorPayload($exception),
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    /**
     * Builds the payload for unhandled exceptions.
     *
     * Production returns a safe generic message; debug mode includes the full
     * exception class, file location, and a trimmed stack trace to aid diagnosis.
     *
     * @return array<string, mixed>
     */
    private function unexpectedErrorPayload(\Throwable $exception): array
    {
        if (!$this->debug) {
            return ApiResponse::error('An unexpected error occurred.')->jsonSerialize();
        }

        return [
            'success'   => false,
            'message'   => $exception->getMessage(),
            'data'      => null,
            'errors'    => null,
            'exception' => $exception::class,
            'file'      => $exception->getFile() . ':' . $exception->getLine(),
            'trace'     => array_map(
                static fn (array $frame): string => sprintf(
                    '%s%s%s(%s)',
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'] ?? '',
                    isset($frame['file']) ? basename($frame['file']) . ':' . ($frame['line'] ?? '?') : 'internal',
                ),
                array_slice($exception->getTrace(), 0, 10),
            ),
        ];
    }
}
