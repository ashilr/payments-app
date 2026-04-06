<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use App\Enum\TransactionStatus;
use App\Enum\TransactionMode;

/**
 * Records a single fund movement between two accounts.
 *
 * Every call to TransferService or ReversalService produces exactly one
 * Transaction row. The lifecycle is always:
 *
 *   PENDING → SUCCESS  (on commit)
 *   PENDING → FAILED   (never persisted; the DB transaction is rolled back)
 *
 * A Transaction may represent either an original transfer or its reversal.
 * When isReversal is true, referenceTransaction points to the original transfer
 * and the money flows in the opposite direction (toAccount → fromAccount).
 *
 * The primary key is a UUID v7 string generated in the constructor so that the
 * ID is available before the entity is flushed, enabling idempotency checks and
 * audit log entries to reference it within the same unit of work.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(name: 'idx_transaction_from_account', columns: ['from_account_id'])]
#[ORM\Index(name: 'idx_transaction_to_account', columns: ['to_account_id'])]
#[ORM\Index(name: 'idx_transaction_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'UNIQ_transaction_idempotency_key', columns: ['idempotency_key'])]
class Transaction
{
    /**
     * UUID v7 stored as CHAR(36). Generated in the constructor so the ID is
     * available before the entity is flushed to the database.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** The account from which funds are debited. */
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'outgoingTransactions')]
    #[ORM\JoinColumn(name: 'from_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Account $fromAccount;

    /** The account to which funds are credited. */
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'incomingTransactions')]
    #[ORM\JoinColumn(name: 'to_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Account $toAccount;

    /**
     * Transfer amount as a decimal string with two decimal places (e.g. "250.00").
     * Stored as DECIMAL(15,2) in the database; never cast to float.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    /**
     * Lifecycle status of the transaction.
     * Newly created transactions start as PENDING and transition to SUCCESS
     * via markCompleted() once all balance changes are committed.
     */
    #[ORM\Column(enumType: TransactionStatus::class)]
    private TransactionStatus $status = TransactionStatus::PENDING;

    /**
     * Human-readable explanation of why the transaction failed.
     * Null for PENDING and SUCCESS transactions.
     */
    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $failureReason = null;

    /** Distinguishes a regular TRANSFER from other future modes (e.g. REVERSAL). */
    #[ORM\Column(enumType: TransactionMode::class)]
    private TransactionMode $mode = TransactionMode::TRANSFER;

    /**
     * Client-supplied deduplication key.
     * When present, a second request carrying the same key returns the original
     * Transaction without reprocessing the transfer.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

    /**
     * For reversal transactions, points to the original transfer being undone.
     * Null for regular transfers.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reference_transaction_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Transaction $referenceTransaction = null;

    /**
     * True when this transaction was created by ReversalService to undo a prior transfer.
     * A reversal cannot itself be reversed (enforced by ReversalService).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isReversal = false;

    /**
     * Free-text reason provided by the operator when creating a reversal.
     * Null for regular transfers.
     */
    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $reversalReason = null;

    /** Timestamp set once on construction; never updated. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, LedgerEntry> The two ledger lines produced when this transaction succeeds. */
    #[ORM\OneToMany(mappedBy: 'transaction', targetEntity: LedgerEntry::class)]
    private Collection $ledgerEntries;

    /**
     * Creates a new Transaction in PENDING status with a freshly generated UUID v7.
     *
     * @param Account          $fromAccount          Account to debit.
     * @param Account          $toAccount            Account to credit.
     * @param string           $amount               Decimal string amount (e.g. "100.00").
     * @param TransactionMode  $mode                 Defaults to TRANSFER.
     * @param string|null      $idempotencyKey       Optional client deduplication key.
     * @param bool             $isReversal           True when created by ReversalService.
     * @param Transaction|null $referenceTransaction The original transfer being reversed; null for regular transfers.
     * @param string|null      $reversalReason       Operator-supplied reason (required for reversals).
     */
    public function __construct(
        Account $fromAccount,
        Account $toAccount,
        string $amount,
        TransactionMode $mode = TransactionMode::TRANSFER,
        ?string $idempotencyKey = null,
        bool $isReversal = false,
        ?Transaction $referenceTransaction = null,
        ?string $reversalReason = null,
    ) {
        $this->id                   = Uuid::v7()->toRfc4122();
        $this->fromAccount          = $fromAccount;
        $this->toAccount            = $toAccount;
        $this->amount               = $amount;
        $this->mode                 = $mode;
        $this->idempotencyKey       = $idempotencyKey;
        $this->isReversal           = $isReversal;
        $this->referenceTransaction = $referenceTransaction;
        $this->reversalReason       = $reversalReason;
        $this->createdAt            = new \DateTimeImmutable();
        $this->ledgerEntries        = new ArrayCollection();
    }

    /**
     * Returns the UUID v7 primary key (CHAR(36) format).
     *
     * The ID is available immediately after construction without waiting for
     * a database flush.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the client-supplied idempotency key, or null if none was provided.
     *
     * Used by TransferService and ReversalService to detect duplicate requests
     * and return the cached result instead of reprocessing.
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** Returns the account that was debited in this transaction. */
    public function getFromAccount(): Account
    {
        return $this->fromAccount;
    }

    /** Returns the account that was credited in this transaction. */
    public function getToAccount(): Account
    {
        return $this->toAccount;
    }

    /**
     * Returns the transferred amount as a decimal string (e.g. "250.00").
     *
     * Always use bcmath for arithmetic on this value — never cast to float.
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /** Returns the current lifecycle status (PENDING, SUCCESS, or FAILED). */
    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    /**
     * Replaces the status directly.
     *
     * Prefer the semantic helpers markCompleted() and markFailed() over calling
     * this method directly — they keep status and failureReason in sync.
     *
     * @param TransactionStatus $status New status value.
     */
    public function setStatus(TransactionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Returns the failure reason, or null for PENDING / SUCCESS transactions.
     *
     * Set by markFailed() and stored in the database for audit purposes even
     * though the surrounding DB transaction is rolled back.
     */
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * Overrides the failure reason directly.
     *
     * @param string|null $failureReason Human-readable explanation, or null to clear.
     */
    public function setFailureReason(?string $failureReason): self
    {
        $this->failureReason = $failureReason;

        return $this;
    }

    /** Returns the transaction mode (currently always TRANSFER). */
    public function getMode(): TransactionMode
    {
        return $this->mode;
    }

    /** Returns the timestamp at which this transaction was created. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Transitions the transaction to SUCCESS status.
     *
     * Called by TransferService and ReversalService immediately before
     * flushing all ORM changes and committing the database transaction.
     */
    public function markCompleted(): self
    {
        $this->status = TransactionStatus::SUCCESS;

        return $this;
    }

    /**
     * Transitions the transaction to FAILED status and records the reason.
     *
     * Note: when a transfer fails, the surrounding DB transaction is rolled back,
     * so this entity is never actually persisted. The method exists for completeness
     * and potential use in future partial-failure scenarios.
     *
     * @param string $reason Human-readable description of what went wrong.
     */
    public function markFailed(string $reason): self
    {
        $this->status        = TransactionStatus::FAILED;
        $this->failureReason = $reason;

        return $this;
    }

    /**
     * For reversal transactions, returns the original transfer that was undone.
     * Returns null for regular (non-reversal) transfers.
     */
    public function getReferenceTransaction(): ?Transaction
    {
        return $this->referenceTransaction;
    }

    /**
     * Returns true when this transaction was created to reverse a prior transfer.
     *
     * Reversal transactions cannot themselves be reversed (enforced by ReversalService).
     */
    public function isReversal(): bool
    {
        return $this->isReversal;
    }

    /**
     * Returns the operator-supplied reason for the reversal, or null for regular transfers.
     *
     * ReversalService validates that this value is non-empty before proceeding.
     */
    public function getReversalReason(): ?string
    {
        return $this->reversalReason;
    }

    /**
     * Returns the ledger entries produced when this transaction succeeded.
     *
     * A completed transfer always produces exactly two entries: one DEBIT for the
     * sender and one CREDIT for the receiver.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function getLedgerEntries(): Collection
    {
        return $this->ledgerEntries;
    }
}
