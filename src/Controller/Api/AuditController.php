<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Response\ApiResponse;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Exposes the structured audit trail for any domain entity.
 *
 * All audit entries for a given entity can be retrieved via:
 *   GET /api/v1/audit/{entityType}/{entityId}
 *
 * Supported entity types: Account, Transaction (any string stored during logging).
 */
#[Route('/api/v1/audit')]
final class AuditController extends AbstractController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    /**
     * GET /api/v1/audit/{entityType}/{entityId}
     *
     * Returns the full audit trail for the given entity, ordered by most recent first.
     *
     * Path parameters:
     *   entityType — Domain entity type (e.g. "Account", "Transaction")
     *   entityId   — UUID of the entity (e.g. Account primary key)
     *
     * Query parameters:
     *   limit — Maximum results to return (1–100, default 50)
     *
     * Response (200):
     *   {
     *     "success": true,
     *     "data": {
     *       "type": "audit-trail",
     *       "attributes": {
     *         "entityType": "Account",
     *         "entityId": "a1111111-1111-7111-8111-111111111111",
     *         "count": 3,
     *         "items": [
     *           {
     *             "id": "019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3",
     *             "action": "TRANSFER_SUCCESS",
     *             "entityType": "Account",
     *             "entityId": "a1111111-1111-7111-8111-111111111111",
     *             "metadata": { ... },
     *             "userId": null,
     *             "ipAddress": "192.168.1.1",
     *             "createdAt": "2026-04-06T10:15:30+00:00"
     *           }
     *         ]
     *       }
     *     }
     *   }
     */
    #[Route('/{entityType}/{entityId}', name: 'api_audit_trail', methods: ['GET'], requirements: ['entityId' => Requirement::UUID])]
    public function trail(string $entityType, string $entityId, Request $request): JsonResponse
    {
        $limit = max(1, min($request->query->getInt('limit', 50), 100));

        $logs = $this->auditLogRepository->findByEntity($entityType, $entityId, $limit);

        $items = array_map(
            static fn (AuditLog $log): array => [
                'id'         => $log->getId(),
                'action'     => $log->getEvent(),
                'entityType' => $log->getEntityType(),
                'entityId'   => $log->getEntityId(),
                'metadata'   => $log->getContext(),
                'userId'     => $log->getUserId(),
                'ipAddress'  => $log->getIpAddress(),
                'createdAt'  => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $logs,
        );

        return $this->json(
            ApiResponse::success([
                'type'       => 'audit-trail',
                'attributes' => [
                    'entityType' => $entityType,
                    'entityId'   => $entityId,
                    'count'      => count($items),
                    'items'      => $items,
                ],
            ]),
        );
    }
}
