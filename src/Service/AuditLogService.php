<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\MaskingUtil;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes structured audit log rows directly via DBAL, bypassing the ORM unit of work.
 *
 * Using raw DBAL instead of the EntityManager is a deliberate design choice:
 *
 *   1. Rows written INSIDE an active DB transaction are rolled back together with all
 *      other ORM changes when a transfer or reversal fails.
 *
 *   2. Rows written AFTER a rollback (e.g. TRANSFER_FAILED, FRAUD_ALERT) use the same
 *      connection in auto-commit mode and are therefore always persisted — failure events
 *      are never silently lost.
 *
 * Sensitive fields in the metadata array (amount, balance, ip) are automatically masked
 * via MaskingUtil before being stored, reducing exposure in the event of a data breach.
 *
 * Supported event identifiers:
 *   TRANSFER_SUCCESS, TRANSFER_FAILED, ACCOUNT_BLOCKED, FRAUD_ALERT,
 *   REVERSAL_SUCCESS, REVERSAL_FAILED,
 *   COMPLIANCE_CHECK, AML_TRIGGERED, KYC_FAILED
 */
class AuditLogService
{
    /**
     * @param Connection      $connection DBAL connection used for direct INSERT statements.
     * @param LoggerInterface $logger     PSR-3 logger; mirrors every audit event to the application log.
     */
    public function __construct(
        private readonly Connection     $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Inserts a single audit log row into the audit_logs table.
     *
     * Well-known fields (transactionId, from, to, amount) are extracted from $context
     * by convention so that they are stored in indexed columns and remain queryable.
     * The full $context array (with sensitive fields masked) is also stored as JSON.
     *
     * This method never throws intentionally — any DBAL exception propagates naturally.
     *
     * @param string               $event      Event type identifier (e.g. "TRANSFER_SUCCESS").
     * @param array<string, mixed> $context    Arbitrary metadata. Recognised keys: transactionId,
     *                                         from, to, amount. Sensitive keys are auto-masked.
     * @param string|null          $entityType Domain entity type for indexed lookup (e.g. "Account").
     * @param string|null          $entityId   Primary entity UUID (e.g. Account id), or null.
     * @param string|null          $userId     Authenticated user UUID, or null.
     * @param string|null          $ipAddress  Client IP address, or null.
     */
    public function log(
        string $event,
        array $context = [],
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $userId = null,
        ?string $ipAddress = null,
    ): void {
        $maskedContext = MaskingUtil::maskMetadata($context);

        $this->connection->insert('audit_logs', [
            'id'              => Uuid::v7()->toRfc4122(),
            'event'           => $event,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'transaction_id'  => isset($context['transactionId']) ? (string) $context['transactionId'] : null,
            'from_account_id' => isset($context['from']) ? (string) $context['from'] : null,
            'to_account_id'   => isset($context['to']) ? (string) $context['to'] : null,
            'amount'          => isset($context['amount']) ? (string) $context['amount'] : null,
            'context'         => $maskedContext !== [] ? json_encode($maskedContext, JSON_THROW_ON_ERROR) : null,
            'user_id'         => $userId,
            'ip_address'      => $ipAddress,
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->logger->info('[audit] ' . $event, ['entityType' => $entityType, 'entityId' => $entityId] + $context);
    }
}
