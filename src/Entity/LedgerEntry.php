<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\LedgerEntryType;
use Symfony\Component\Uid\Uuid;

/**
 * An immutable double-entry bookkeeping line for a single account.
 *
 * Every successful Transaction produces exactly two LedgerEntry rows:
 *
 *   • One DEBIT  entry on the sender's account (balance decreases).
 *   • One CREDIT entry on the receiver's account (balance increases).
 *
 * The balanceAfter field captures a running snapshot of the account balance
 * at the moment the entry was written, making it possible to reconstruct the
 * full balance history for any account without recalculating from scratch.
 *
 * Entries are never updated or deleted — deletions cascade from the database
 * level only when the parent Account is removed (RESTRICT on Transaction FK).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_entries')]
#[ORM\Index(name: 'idx_ledger_account_id', columns: ['account_id'])]
#[ORM\Index(name: 'idx_ledger_transaction_id', columns: ['transaction_id'])]
#[ORM\Index(name: 'idx_ledger_account_timeline', columns: ['account_id', 'created_at'])]
class LedgerEntry
{
    /** UUID v7 primary key (RFC 4122 string), generated in the constructor. */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** The account this ledger line belongs to. */
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'ledgerEntries')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    /** The transaction that caused this ledger movement. */
    #[ORM\ManyToOne(targetEntity: Transaction::class, inversedBy: 'ledgerEntries')]
    #[ORM\JoinColumn(name: 'transaction_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Transaction $transaction;

    /** Indicates whether funds were removed (DEBIT) or added (CREDIT) to the account. */
    #[ORM\Column(enumType: LedgerEntryType::class)]
    private LedgerEntryType $type;

    /**
     * The absolute amount moved in this entry as a decimal string (e.g. "250.00").
     * Always positive regardless of direction — the type field encodes the direction.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    /**
     * The account balance immediately after this entry was applied.
     * Provides a point-in-time snapshot for audit and reconciliation queries.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $balanceAfter;

    /** Timestamp set once on construction; never updated. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates an immutable ledger line.
     *
     * The caller (TransferService / ReversalService) is responsible for computing
     * $balanceAfter using bcmath arithmetic before constructing this entry.
     *
     * @param Account         $account      The account being debited or credited.
     * @param Transaction     $transaction  The transaction that triggered this movement.
     * @param LedgerEntryType $type         DEBIT (funds leave) or CREDIT (funds arrive).
     * @param string          $amount       Positive decimal string, e.g. "250.00".
     * @param string          $balanceAfter Account balance after this entry is applied, e.g. "750.00".
     */
    public function __construct(
        Account     $account,
        Transaction $transaction,
        LedgerEntryType $type,
        string      $amount,
        string      $balanceAfter,
    ) {
        $this->id           = Uuid::v7()->toRfc4122();
        $this->account      = $account;
        $this->transaction  = $transaction;
        $this->type         = $type;
        $this->amount       = $amount;
        $this->balanceAfter = $balanceAfter;
        $this->createdAt    = new \DateTimeImmutable();
    }

    /** Returns the UUID v7 primary key (available immediately after construction). */
    public function getId(): string
    {
        return $this->id;
    }

    /** Returns the account this ledger line is associated with. */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /** Returns the transaction that caused this ledger movement. */
    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * Returns the entry type.
     *
     * DEBIT means funds left the account; CREDIT means funds arrived.
     */
    public function getType(): LedgerEntryType
    {
        return $this->type;
    }

    /**
     * Returns the absolute amount moved as a decimal string (e.g. "250.00").
     *
     * Always positive — the direction is expressed by the type field.
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * Returns the account balance immediately after this entry was applied.
     *
     * Useful for reconstructing the account statement without summing all
     * prior entries from the beginning of time.
     */
    public function getBalanceAfter(): string
    {
        return $this->balanceAfter;
    }

    /** Returns the UTC timestamp at which this ledger entry was created. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
