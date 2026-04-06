<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An immutable record of a notable system event related to a transaction or account.
 *
 * AuditLog rows are written by AuditLogService using raw DBAL (bypassing the ORM
 * unit of work) so that failure events can be persisted even after the surrounding
 * database transaction has been rolled back.
 *
 * Supported event types:
 *   - TRANSFER_SUCCESS   — a fund transfer completed successfully.
 *   - TRANSFER_FAILED    — a fund transfer was rejected or threw an exception.
 *   - ACCOUNT_BLOCKED    — a transfer was rejected because an account is blocked.
 *   - FRAUD_ALERT        — the fraud-detection rule flagged the transfer.
 *   - REVERSAL_SUCCESS   — a reversal completed successfully.
 *   - REVERSAL_FAILED    — a reversal was rejected or threw an exception.
 *   - COMPLIANCE_CHECK   — a compliance check was performed (AML/KYC).
 *   - AML_TRIGGERED      — anti-money-laundering rule fired.
 *   - KYC_FAILED         — KYC verification failed for an account.
 *
 * All fields except event and createdAt are nullable because not every event
 * type involves all participants (e.g. ACCOUNT_BLOCKED has no toAccountId).
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_event', columns: ['event'])]
#[ORM\Index(name: 'idx_audit_transaction', columns: ['transaction_id'])]
#[ORM\Index(name: 'idx_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_entity', columns: ['entity_type', 'entity_id'])]
class AuditLog
{
    /** UUID v7 primary key (RFC 4122 string), generated in the constructor. */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /**
     * The event type identifier (e.g. "TRANSFER_SUCCESS").
     * Must be one of the values documented on this class.
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $event;

    /**
     * The domain entity type this log entry is scoped to (e.g. "Account", "Transaction").
     * Used with entityId to efficiently fetch all audit history for a specific resource.
     * Null for legacy entries written before this field was introduced.
     */
    #[ORM\Column(name: 'entity_type', type: Types::STRING, length: 100, nullable: true)]
    private ?string $entityType;

    /**
     * UUID of the primary entity involved in this event (e.g. Account id).
     * Combined with entityType to form a composite lookup key.
     */
    #[ORM\Column(name: 'entity_id', type: Types::GUID, nullable: true)]
    private ?string $entityId;

    /**
     * UUID v7 of the Transaction related to this event.
     * Null for events that do not involve a specific transaction (e.g. ACCOUNT_BLOCKED
     * when the block is detected before any transaction is created).
     */
    #[ORM\Column(name: 'transaction_id', type: Types::GUID, nullable: true)]
    private ?string $transactionId;

    /**
     * Account UUID of the sender (denormalised; no FK so DBAL inserts survive rollbacks).
     */
    #[ORM\Column(name: 'from_account_id', type: Types::GUID, nullable: true)]
    private ?string $fromAccountId;

    /**
     * Account UUID of the receiver (denormalised; no FK).
     */
    #[ORM\Column(name: 'to_account_id', type: Types::GUID, nullable: true)]
    private ?string $toAccountId;

    /**
     * The monetary amount involved in the event as a decimal string (e.g. "250.00").
     * Null for non-monetary events (e.g. ACCOUNT_BLOCKED).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $amount;

    /**
     * Arbitrary key-value metadata relevant to the event, serialised as JSON.
     * Sensitive fields (e.g. amounts) are masked by MaskingUtil before storage.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context;

    /**
     * User UUID of the authenticated actor, if available.
     */
    #[ORM\Column(name: 'user_id', type: Types::GUID, nullable: true)]
    private ?string $userId;

    /**
     * IP address of the client that triggered this event (IPv4 or IPv6).
     * Null when the event originates from a background process.
     */
    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress;

    /** Timestamp set once on construction; never updated. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates an immutable audit log entry.
     *
     * @param string                     $event         Event type identifier (e.g. "TRANSFER_SUCCESS").
     * @param string|null                $transactionId UUID of the related Transaction, or null.
     * @param string|null                $fromAccountId Sender account UUID, or null.
     * @param string|null                $toAccountId   Receiver account UUID, or null.
     * @param string|null                $amount        Decimal string amount (e.g. "250.00"), or null.
     * @param array<string,mixed>|null   $context       Additional event metadata, or null.
     * @param string|null                $entityType    Domain entity type (e.g. "Account"), or null.
     * @param string|null                $entityId      Primary entity UUID (e.g. Account), or null.
     * @param string|null                $userId        Authenticated user UUID, or null.
     * @param string|null                $ipAddress     Client IP address, or null.
     */
    public function __construct(
        string $event,
        ?string $transactionId = null,
        ?string $fromAccountId = null,
        ?string $toAccountId = null,
        ?string $amount = null,
        ?array $context = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $userId = null,
        ?string $ipAddress = null,
    ) {
        $this->id            = Uuid::v7()->toRfc4122();
        $this->event         = $event;
        $this->transactionId = $transactionId;
        $this->fromAccountId = $fromAccountId;
        $this->toAccountId   = $toAccountId;
        $this->amount        = $amount;
        $this->context       = $context;
        $this->entityType    = $entityType;
        $this->entityId      = $entityId;
        $this->userId        = $userId;
        $this->ipAddress     = $ipAddress;
        $this->createdAt     = new \DateTimeImmutable();
    }

    /** Returns the UUID v7 primary key (available immediately after construction). */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the event type identifier (e.g. "TRANSFER_SUCCESS").
     *
     * See the class-level docblock for the full list of recognised event types.
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Returns the UUID of the related Transaction, or null when no transaction
     * was involved in (or created for) this event.
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /** Returns the sender account UUID, or null when not applicable. */
    public function getFromAccountId(): ?string
    {
        return $this->fromAccountId;
    }

    /** Returns the receiver account UUID, or null when not applicable. */
    public function getToAccountId(): ?string
    {
        return $this->toAccountId;
    }

    /**
     * Returns the amount involved in the event as a decimal string (e.g. "250.00"),
     * or null for non-monetary events.
     */
    public function getAmount(): ?string
    {
        return $this->amount;
    }

    /**
     * Returns the arbitrary event metadata as an associative array, or null when
     * no additional context was recorded.
     *
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /**
     * Returns the domain entity type this entry is scoped to (e.g. "Account"), or null
     * for legacy entries written before this field was introduced.
     */
    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    /** Returns the primary entity UUID, or null when not applicable. */
    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /** Returns the authenticated user UUID, or null. */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Returns the IP address of the client that triggered this event (IPv4 or IPv6),
     * or null when the event originates from a background or system process.
     */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** Returns the UTC timestamp at which this audit entry was created. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
