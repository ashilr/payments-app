<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Response\ApiResponse;
use App\DTO\Response\ReversalResponse;
use App\Service\ReversalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP API for reversing a committed transfer (compensating debit/credit).
 *
 * Identifies the original movement by transaction UUID in the path — not by account number.
 */
#[Route('/api/v1')]
final class ReversalController extends AbstractController
{
    public function __construct(
        private readonly ReversalService $reversalService,
    ) {}

    /**
     * POST /api/v1/reversal/{transactionId}
     *
     * Headers:
     *   Idempotency-Key: <unique-key>   (optional but recommended)
     *
     * Body:
     *   { "reason": "Customer requested refund" }
     *
     * Responses:
     *   201 Created      — new reversal committed
     *   200 OK           — idempotent replay; existing reversal returned unchanged
     *   400 Bad Request  — missing or empty reason
     *
     * @throws \Throwable When reversal rules fail or persistence errors occur (handled by {@see \App\EventSubscriber\ApiExceptionSubscriber}).
     */
    #[Route('/reversal/{transactionId}', name: 'api_reversal_create', methods: ['POST'])]
    public function create(string $transactionId, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body   = json_decode($request->getContent(), true) ?? [];
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';

        if ($reason === '') {
            return $this->json(
                ApiResponse::error('A reversal reason must be provided.'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');

        $reversal = $this->reversalService->reverse(
            $transactionId,
            $reason,
            $idempotencyKey,
        );

        $statusCode = $reversal->getStatus()->value === 'SUCCESS'
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;

        return $this->json(
            ApiResponse::success(ReversalResponse::fromTransaction($reversal)),
            $statusCode,
        );
    }
}
