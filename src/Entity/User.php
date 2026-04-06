<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Represents an authenticated user of the payments platform.
 *
 * A User may own one or more Accounts. Authentication is handled by Symfony
 * Security using the email address as the unique identifier and a bcrypt/argon2
 * hashed password stored in the password field.
 *
 * All Account rows are cascade-deleted when their owning User is removed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** UUID v7 primary key (RFC 4122 string), generated in the constructor. */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Unique email address used as the Symfony security identifier. */
    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    /** Display name of the user (not used for authentication). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /**
     * Hashed password stored by Symfony's password hasher.
     * Plain-text passwords must never be stored here.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $password;

    /**
     * List of Symfony security roles (e.g. ["ROLE_USER", "ROLE_ADMIN"]).
     * ROLE_USER is always appended by getRoles() even when this array is empty.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Platform-level access: ACTIVE users may use accounts; BLOCKED users cannot initiate or receive transfers. */
    #[ORM\Column(enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::ACTIVE;

    /** Timestamp set once on construction; never updated. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Accounts owned by this user.
     * Cascades persist and remove; orphaned accounts are deleted automatically.
     *
     * @var Collection<int, Account>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Account::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $accounts;

    /**
     * Creates a new User.
     *
     * @param string $email    Unique email address used for authentication.
     * @param string $password Pre-hashed password (use UserPasswordHasherInterface before passing).
     * @param string $name     Optional display name.
     */
    public function __construct(string $email, string $password, string $name = '')
    {
        $this->id        = Uuid::v7()->toRfc4122();
        $this->email     = $email;
        $this->name      = $name;
        $this->password  = $password;
        $this->status    = UserStatus::ACTIVE;
        $this->createdAt = new \DateTimeImmutable();
        $this->accounts  = new ArrayCollection();
    }

    /**
     * Returns the unique identifier for Symfony Security (the email address).
     *
     * {@inheritdoc}
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Clears any temporary plain-text credentials from memory after authentication.
     *
     * This implementation stores no sensitive plain-text data, so the method is a no-op.
     *
     * {@inheritdoc}
     */
    public function eraseCredentials(): void
    {
        // no temporary sensitive data stored
    }

    /**
     * Returns the UUID v7 primary key (available immediately after construction).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /** Returns the unique email address of this user. */
    public function getEmail(): string
    {
        return $this->email;
    }

    /** @param string $email New unique email address. */
    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /** Returns the display name of this user. */
    public function getName(): string
    {
        return $this->name;
    }

    /** @param string $name New display name. */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns the hashed password stored for this user.
     *
     * {@inheritdoc}
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Replaces the stored password hash.
     *
     * Always pass a value produced by UserPasswordHasherInterface::hashPassword();
     * never store a plain-text password.
     *
     * @param string $password Hashed password string.
     */
    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Returns the full set of Symfony security roles for this user.
     *
     * ROLE_USER is always included regardless of what is stored in the database,
     * satisfying Symfony's requirement that every user has at least one role.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles   = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * Replaces the stored role list.
     *
     * @param list<string> $roles Array of Symfony role strings (e.g. ["ROLE_ADMIN"]).
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->status === UserStatus::BLOCKED;
    }

    /** Returns the UTC timestamp at which this user was created. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns all accounts owned by this user.
     *
     * @return Collection<int, Account>
     */
    public function getAccounts(): Collection
    {
        return $this->accounts;
    }

    /**
     * Associates an Account with this user if it is not already linked.
     *
     * Also sets the inverse side of the relationship so that $account->getUser()
     * returns this User without requiring an explicit flush.
     *
     * @param Account $account The account to attach.
     */
    public function addAccount(Account $account): static
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
            $account->setUser($this);
        }

        return $this;
    }

    /**
     * Removes an Account from this user's collection.
     *
     * Because orphanRemoval is enabled on the OneToMany mapping, Doctrine will
     * schedule the detached Account for deletion on the next flush.
     *
     * @param Account $account The account to detach and delete.
     */
    public function removeAccount(Account $account): static
    {
        // orphanRemoval:true on the OneToMany will schedule the Account for deletion
        $this->accounts->removeElement($account);

        return $this;
    }
}
