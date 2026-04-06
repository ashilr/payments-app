<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Data-access layer for AuditLog entities.
 *
 * AuditLog rows are written by AuditLogService via raw DBAL (not this repository)
 * so that failure events persist even after a DB transaction rollback. This
 * repository is used only for reading audit entries via the API.
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Returns the most recent audit entries matching the given optional filters.
     *
     * All filters are applied with AND logic. Omitting a filter parameter
     * (passing null / 0) means that dimension is not constrained.
     *
     * @param string|null $transactionId Filter by the related transaction UUID.
     * @param string|null $accountId     Filter by sender or receiver account UUID.
     * @param string|null $event         Filter by event type (e.g. "TRANSFER_SUCCESS").
     * @param int         $limit         Maximum number of results (1–100, default 20).
     * @return list<AuditLog>
     */
    public function findRecent(
        ?string $transactionId = null,
        ?string $accountId     = null,
        ?string $event         = null,
        int     $limit         = 20,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($transactionId !== null && $transactionId !== '') {
            $qb->andWhere('a.transactionId = :transactionId')
                ->setParameter('transactionId', $transactionId);
        }

        if ($accountId !== null && $accountId !== '') {
            $qb->andWhere('a.fromAccountId = :accountId OR a.toAccountId = :accountId')
                ->setParameter('accountId', $accountId);
        }

        if ($event !== null && $event !== '') {
            $qb->andWhere('a.event = :event')
                ->setParameter('event', $event);
        }

        /** @var list<AuditLog> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * Returns all audit entries for a specific entity, ordered by most recent first.
     *
     * Uses the composite index on (entity_type, entity_id) for efficient lookups.
     * This is the primary method used by the audit trail API endpoint.
     *
     * @param string $entityType Domain entity type (e.g. "Account", "Transaction").
     * @param string $entityId   UUID of the entity (e.g. Account id).
     * @param int    $limit      Maximum number of results (default 50).
     * @return list<AuditLog>
     */
    public function findByEntity(string $entityType, string $entityId, int $limit = 50): array
    {
        /** @var list<AuditLog> $results */
        $results = $this->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }
}
