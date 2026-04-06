<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Response\ApiResponse;
use App\DTO\Response\AuditLogListResponse;
use App\DTO\Response\TransferResponse;
use App\Repository\AuditLogRepository;
use App\Service\TransferService;
use App\Validator\TransferRequestValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferService          $transferService,
        private readonly TransferRequestValidator $validator,
        private readonly AuditLogRepository       $auditLogRepository,
    ) {}

    /**
     * POST /api/v1/transfer
     *
     * Headers:
     *   Idempotency-Key: <unique-key>   (optional)
     *
     * Body:
     *   { "from_account_id", "to_account_id", "amount" }
     *
     * Responses:
     *   201 Created  — new transfer committed
     *   200 OK       — idempotent replay; existing transaction returned unchanged
     *
     * @throws \App\Exception\ValidationException      When JSON body fails validation (handled by {@see \App\EventSubscriber\ApiExceptionSubscriber} → 400).
     * @throws \Throwable                                Business / persistence errors (handled by {@see \App\EventSubscriber\ApiExceptionSubscriber}).
     */
    #[Route('/transfer', name: 'api_transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $dto            = $this->validator->validate($body);
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        $transaction = $this->transferService->transfer(
            $dto->fromAccountId,
            $dto->toAccountId,
            $dto->amount,
            $idempotencyKey,
        );

        $statusCode = $transaction->getStatus()->value === 'SUCCESS'
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;

        return $this->json(
            ApiResponse::success(TransferResponse::fromTransaction($transaction)),
            $statusCode,
        );
    }

    /**
     * GET /api/v1/transfer/audit-logs
     *
     * Query params: transactionId, accountId (account UUID), event, limit
     *
     * Response:
     *   200 OK — paginated list of audit log entries (up to 100 per request)
     *
     * @return JsonResponse HTTP 200 with `type: audit-log-list`
     */
    #[Route('/transfer/audit-logs', name: 'api_transfer_audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request): JsonResponse
    {
        $transactionId = $request->query->get('transactionId');
        $event         = $request->query->get('event');
        $accountIdRaw  = $request->query->get('accountId');
        $accountId     = \is_string($accountIdRaw) && $accountIdRaw !== '' ? $accountIdRaw : null;
        $limit         = max(1, min($request->query->getInt('limit', 20), 100));

        $logs = $this->auditLogRepository->findRecent(
            is_string($transactionId) ? $transactionId : null,
            $accountId,
            is_string($event) ? $event : null,
            $limit,
        );

        return $this->json(
            ApiResponse::success(AuditLogListResponse::fromEntities($logs)),
        );
    }
}
