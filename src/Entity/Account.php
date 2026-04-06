<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a financial account owned by a User.
 *
 * An Account holds a decimal balance (stored as a string to preserve precision),
 * has a type (SAVINGS, CURRENT, SALARY), and can be blocked to prevent all
 * inbound and outbound transfers.
 *
 * The account number is generated once at construction time in the format
 * "ACC" followed by 10 uppercase hex characters (e.g. ACCAB12CD34EF) and is
 * never changed afterwards.
 *
 * Monetary values use bcmath string arithmetic throughout the codebase —
 * the balance field must never be cast to float.
 *
 * An optional IFSC code (Indian Financial System Code, 11 characters) identifies the
 * bank branch for regulatory reporting and future external settlement (NEFT/RTGS).
 * Internal transfers resolve accounts by UUID (`from_account_id` / `to_account_id` on the transfer API).
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(name: 'idx_account_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_account_number', columns: ['account_number'])]
class Account
{
    /** UUID v7 primary key (RFC 4122 string), generated in the constructor. */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** The User who owns this account. */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'accounts')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Unique account identifier in the format ACC + 10 uppercase hex chars.
     * Generated once in the constructor using cryptographically random bytes.
     * Example: ACCAB12CD34EF
     */
    #[ORM\Column(type: Types::STRING, length: 13)]
    private string $accountNumber;

    /** Classification that determines transfer limits and business rules. */
    #[ORM\Column(enumType: AccountType::class)]
    private AccountType $accountType;

    /**
     * Current balance as a decimal string with two places of precision.
     * Use bcmath functions for all arithmetic — never cast this to float.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, options: ['default' => '0.00'])]
    private string $balance = '0.00';

    /** ISO 4217 currency code (e.g. "INR", "USD"). Defaults to INR. */
    #[ORM\Column(type: Types::STRING, length: 3, options: ['default' => 'INR'])]
    private string $currency = 'INR';

    /**
     * Indian Financial System Code (11 characters: bank + branch routing).
     * Nullable when unknown or for non-INR accounts; optional for in-app transfers.
     */
    #[ORM\Column(name: 'ifsc_code', type: Types::STRING, length: 11, nullable: true)]
    private ?string $ifscCode = null;

    /**
     * Registered beneficiary / account-holder name used to verify payee details on inbound transfers.
     */
    #[ORM\Column(name: 'beneficiary_name', type: Types::STRING, length: 255)]
    private string $beneficiaryName = '';

    /**
     * When true, neither inbound nor outbound transfers are permitted.
     * The AccountNotBlockedRule enforces this before any transfer is processed.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isBlocked = false;

    /** Timestamp set once on construction; never updated. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Transaction> Transfers initiated by this account. */
    #[ORM\OneToMany(mappedBy: 'fromAccount', targetEntity: Transaction::class)]
    private Collection $outgoingTransactions;

    /** @var Collection<int, Transaction> Transfers received by this account. */
    #[ORM\OneToMany(mappedBy: 'toAccount', targetEntity: Transaction::class)]
    private Collection $incomingTransactions;

    /** @var Collection<int, LedgerEntry> Double-entry ledger lines for this account. */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: LedgerEntry::class)]
    private Collection $ledgerEntries;

    /**
     * Creates a new Account and generates a unique account number.
     *
     * @param User        $user        The owning User entity (must already be persisted or cascaded).
     * @param AccountType $accountType Classification used by the rule engine to enforce limits.
     * @param string      $currency    ISO 4217 code; defaults to "INR".
     */
    public function __construct(
        User $user,
        AccountType $accountType = AccountType::SAVINGS,
        string $currency = 'INR',
    ) {
        $this->id                   = Uuid::v7()->toRfc4122();
        $this->user                 = $user;
        $this->accountType          = $accountType;
        $this->currency             = $currency;
        $this->accountNumber        = 'ACC' . strtoupper(bin2hex(random_bytes(5)));
        $this->createdAt            = new \DateTimeImmutable();
        $this->outgoingTransactions = new ArrayCollection();
        $this->incomingTransactions = new ArrayCollection();
        $this->ledgerEntries        = new ArrayCollection();
        $this->beneficiaryName      = '';
    }

    /**
     * Returns the UUID v7 primary key (available immediately after construction).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the unique account number (e.g. "ACCAB12CD34EF").
     *
     * The number is immutable after construction and matches ACC[A-F0-9]{10}.
     */
    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    /** Returns the account type (SAVINGS, CURRENT, or SALARY). */
    public function getAccountType(): AccountType
    {
        return $this->accountType;
    }

    /** @param AccountType $accountType New classification for this account. */
    public function setAccountType(AccountType $accountType): static
    {
        $this->accountType = $accountType;

        return $this;
    }

    /** Returns the User who owns this account. */
    public function getUser(): User
    {
        return $this->user;
    }

    /** @param User $user Replacement owner (use with caution in production). */
    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Returns the current balance as a decimal string (e.g. "1250.00").
     *
     * Always use bcmath for comparisons and arithmetic on this value.
     */
    public function getBalance(): string
    {
        return $this->balance;
    }

    /**
     * Replaces the balance with a new value computed by the caller.
     *
     * TransferService and ReversalService are the only classes that should
     * call this method — they do so inside a database transaction with
     * pessimistic write locks to prevent concurrent updates.
     *
     * @param string $balance Decimal string with up to two decimal places (e.g. "850.00").
     */
    public function setBalance(string $balance): static
    {
        $this->balance = $balance;

        return $this;
    }

    /** Returns the ISO 4217 currency code (e.g. "INR"). */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** Returns the IFSC branch code, or null when not set. */
    public function getIfscCode(): ?string
    {
        return $this->ifscCode;
    }

    /**
     * Sets the IFSC code (11 characters, normalised to uppercase) or clears it when null/empty.
     *
     * @throws \InvalidArgumentException When a non-empty value does not match the RBI IFSC pattern.
     */
    public function setIfscCode(?string $ifscCode): static
    {
        if ($ifscCode === null || $ifscCode === '') {
            $this->ifscCode = null;

            return $this;
        }

        $normalized = strtoupper(trim($ifscCode));
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $normalized)) {
            throw new \InvalidArgumentException('IFSC must be 11 characters: 4 bank letters, 0, then 6 alphanumeric branch code.');
        }

        $this->ifscCode = $normalized;

        return $this;
    }

    /** Registered beneficiary name (payee verification on transfers). */
    public function getBeneficiaryName(): string
    {
        return $this->beneficiaryName;
    }

    /**
     * Sets the beneficiary / account-holder display name (trimmed, 1–255 characters).
     *
     * @throws \InvalidArgumentException When empty after trim or longer than 255 characters.
     */
    public function setBeneficiaryName(string $beneficiaryName): static
    {
        $trimmed = trim($beneficiaryName);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Beneficiary name must not be blank.');
        }
        if (mb_strlen($trimmed) > 255) {
            throw new \InvalidArgumentException('Beneficiary name must not exceed 255 characters.');
        }

        $this->beneficiaryName = $trimmed;

        return $this;
    }

    /** @param string $currency ISO 4217 code (e.g. "USD"). */
    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Returns true when the account is blocked.
     *
     * Blocked accounts cannot send or receive funds. The AccountNotBlockedRule
     * checks this flag before any transfer is allowed to proceed.
     */
    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    /**
     * Sets the blocked flag on this account.
     *
     * @param bool $isBlocked Pass true to block or false to unblock.
     */
    public function setBlocked(bool $isBlocked): static
    {
        $this->isBlocked = $isBlocked;

        return $this;
    }

    /** Returns the UTC timestamp at which this account was created. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns all transfers that originated from this account.
     *
     * @return Collection<int, Transaction>
     */
    public function getOutgoingTransactions(): Collection
    {
        return $this->outgoingTransactions;
    }

    /**
     * Returns all transfers received by this account.
     *
     * @return Collection<int, Transaction>
     */
    public function getIncomingTransactions(): Collection
    {
        return $this->incomingTransactions;
    }

    /**
     * Returns the double-entry ledger lines associated with this account.
     *
     * Each completed transfer produces one DEBIT entry for the sender and one
     * CREDIT entry for the receiver, creating an auditable balance history.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function getLedgerEntries(): Collection
    {
        return $this->ledgerEntries;
    }
}
