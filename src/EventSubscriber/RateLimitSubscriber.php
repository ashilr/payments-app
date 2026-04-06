<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\DTO\Response\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Applies a sliding-window rate limit to POST /api/v1/transfer.
 *
 * The limit and window are driven entirely by environment variables
 * (RATE_LIMIT_TRANSFER_MAX_REQUESTS and RATE_LIMIT_TRANSFER_INTERVAL),
 * so they can be tuned per environment without any code changes.
 *
 * Rate-limit key strategy
 * ───────────────────────
 * Requests are keyed by the client's IP address. This protects against
 * anonymous burst abuse from a single origin without requiring the caller
 * to be authenticated.
 *
 * A per-account-number key (read from the request body) is also supported
 * and more precise for authenticated scenarios — see the inline comment in
 * onKernelRequest() for how to enable it.
 *
 * Response headers
 * ────────────────
 * On every transfer request, the following informational headers are added
 * regardless of whether the limit was reached:
 *
 *   X-RateLimit-Limit     : configured maximum requests per window
 *   X-RateLimit-Remaining : tokens left in the current window
 *   X-RateLimit-Reset     : Unix timestamp when the window resets
 *
 * When the limit is exceeded the response is HTTP 429 with:
 *   Retry-After           : seconds until the next request is accepted
 */
final class RateLimitSubscriber implements EventSubscriberInterface
{
    /**
     * Request attribute used to share the RateLimit result between the
     * onKernelRequest and onKernelResponse listeners without a class property
     * (which would break under concurrent requests in async runtimes).
     */
    private const RATE_LIMIT_ATTR = '_transfer_rate_limit';

    /**
     * @param RateLimiterFactory $transferApiLimiter Auto-wired by Symfony from the
     *                                               "transfer_api" limiter defined in
     *                                               config/packages/rate_limiter.yaml.
     */
    public function __construct(
        private readonly RateLimiterFactory $transferApiLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Run early on the request cycle — before security and the controller —
            // so we can short-circuit without instantiating any service.
            KernelEvents::REQUEST  => ['onKernelRequest',  20],
            // Run last on responses so our headers are always appended.
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    /**
     * Consumes one token from the rate limiter for POST /api/v1/transfer.
     * Sets a 429 response immediately when the limit is exceeded.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getMethod() !== 'POST'
            || $request->getPathInfo() !== '/api/v1/transfer'
        ) {
            return;
        }

        // ── Rate-limit key ─────────────────────────────────────────────────────
        // Default: client IP. This is suitable for unauthenticated APIs.
        //
        // To key by sender account number instead (more precise for authenticated
        // scenarios), replace the line below with:
        //
        //   $body = json_decode($request->getContent(), true) ?? [];
        //   $key  = (string) ($body['from_account_id'] ?? $request->getClientIp());
        //
        $key = $request->getClientIp() ?? 'unknown';

        $limiter    = $this->transferApiLimiter->create($key);
        $rateLimit  = $limiter->consume(1);

        // Stash the result so onKernelResponse can attach the headers.
        $request->attributes->set(self::RATE_LIMIT_ATTR, $rateLimit);

        if ($rateLimit->isAccepted()) {
            return;
        }

        $retryAfter = max(0, $rateLimit->getRetryAfter()->getTimestamp() - time());

        $event->setResponse(new JsonResponse(
            ApiResponse::error('Too many requests. Please slow down and try again.'),
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => $retryAfter],
        ));
    }

    /**
     * Appends rate-limit informational headers to every transfer response
     * (both accepted and rejected), giving clients visibility into their quota.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $rateLimit = $event->getRequest()->attributes->get(self::RATE_LIMIT_ATTR);

        if ($rateLimit === null) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('X-RateLimit-Limit',     (string) $rateLimit->getLimit());
        $response->headers->set('X-RateLimit-Remaining', (string) $rateLimit->getRemainingTokens());
        $response->headers->set('X-RateLimit-Reset',     (string) $rateLimit->getRetryAfter()->getTimestamp());
    }
}
