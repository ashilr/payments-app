<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\AuditLog;
use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\LedgerEntryType;
use App\Enum\TransactionMode;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // ── Users ──────────────────────────────────────────────────────────────
        $alice   = $this->makeUser($manager, 'alice@example.com',   'Alice Johnson',  'password123');
        $bob     = $this->makeUser($manager, 'bob@example.com',     'Bob Smith',      'password123');
        $charlie = $this->makeUser($manager, 'charlie@example.com', 'Charlie Brown',  'password123');
        $dave    = $this->makeUser($manager, 'dave@example.com',    'Dave Wilson',    'password123');

        // ── Accounts ───────────────────────────────────────────────────────────
        // Balances reflect the state *after* the historical transactions below.
        $aliceAccount   = new Account($alice, AccountType::SAVINGS);
        $aliceAccount->setBalance('1000.00');   // started 1250.00, sent 250.00 to Bob

        $bobAccount     = new Account($bob, AccountType::CURRENT);
        $bobAccount->setBalance('1125.00');     // started 875.00, received 250.00 from Alice

        $charlieAccount = new Account($charlie, AccountType::SAVINGS);
        $charlieAccount->setBalance('300.00');
        $charlieAccount->setBlocked(true);      // blocked — cannot send or receive

        $daveAccount    = new Account($dave, AccountType::SALARY);
        $daveAccount->setBalance('500.00');

        $aliceAccount->setIfscCode('HDFC0001234');
        $aliceAccount->setBeneficiaryName('Alice Holder');
        $bobAccount->setIfscCode('SBIN0001234');
        $bobAccount->setBeneficiaryName('Bob Holder');
        $charlieAccount->setIfscCode('ICIC0001234');
        $charlieAccount->setBeneficiaryName('Charlie Holder');
        $daveAccount->setIfscCode('AXIS0001234');
        $daveAccount->setBeneficiaryName('Dave Holder');

        $manager->persist($aliceAccount);
        $manager->persist($bobAccount);
        $manager->persist($charlieAccount);
        $manager->persist($daveAccount);

        // ── Transactions ───────────────────────────────────────────────────────
        // 1. Alice → Bob  250.00  (SUCCESS, with idempotency key)
        $successfulTransfer = new Transaction(
            $aliceAccount,
            $bobAccount,
            '250.00',
            TransactionMode::TRANSFER,
            'idem-alice-bob-001',
        );
        $successfulTransfer->markCompleted();

        // 2. Charlie → Alice  500.00  (FAILED — sender is blocked)
        $failedTransfer = new Transaction(
            $charlieAccount,
            $aliceAccount,
            '500.00',
            TransactionMode::TRANSFER,
            'idem-charlie-alice-001',
        );
        $failedTransfer->markFailed('Sender account is blocked.');

        // 3. Dave → Alice  100.00  (SUCCESS, with idempotency key)
        $daveTransfer = new Transaction(
            $daveAccount,
            $aliceAccount,
            '100.00',
            TransactionMode::TRANSFER,
            'idem-dave-alice-001',
        );
        $daveTransfer->markCompleted();

        $manager->persist($successfulTransfer);
        $manager->persist($failedTransfer);
        $manager->persist($daveTransfer);

        // ── Ledger entries ─────────────────────────────────────────────────────
        // Alice → Bob
        $manager->persist(new LedgerEntry($aliceAccount, $successfulTransfer, LedgerEntryType::DEBIT,  '250.00', '1000.00'));
        $manager->persist(new LedgerEntry($bobAccount,   $successfulTransfer, LedgerEntryType::CREDIT, '250.00', '1125.00'));

        // Dave → Alice
        $manager->persist(new LedgerEntry($daveAccount,  $daveTransfer, LedgerEntryType::DEBIT,  '100.00', '400.00'));
        $manager->persist(new LedgerEntry($aliceAccount, $daveTransfer, LedgerEntryType::CREDIT, '100.00', '1100.00'));

        $manager->flush();

        // ── Audit logs ─────────────────────────────────────────────────────────
        // Flush first so account/transaction IDs are available.

        $manager->persist(new AuditLog(
            'TRANSFER_SUCCESS',
            $successfulTransfer->getId(),
            $aliceAccount->getId(),
            $bobAccount->getId(),
            '250.00',
            ['transactionId' => $successfulTransfer->getId(), 'from' => $aliceAccount->getId(), 'to' => $bobAccount->getId(), 'amount' => '250.00'],
        ));

        $manager->persist(new AuditLog(
            'ACCOUNT_BLOCKED',
            null,
            $charlieAccount->getId(),
            null,
            null,
            ['accountId' => $charlieAccount->getId(), 'reason' => 'sender account is blocked'],
        ));

        $manager->persist(new AuditLog(
            'TRANSFER_FAILED',
            $failedTransfer->getId(),
            $charlieAccount->getId(),
            $aliceAccount->getId(),
            '500.00',
            ['transactionId' => $failedTransfer->getId(), 'from' => $charlieAccount->getId(), 'to' => $aliceAccount->getId(), 'amount' => '500.00', 'error' => 'Sender account is blocked.'],
        ));

        $manager->persist(new AuditLog(
            'TRANSFER_SUCCESS',
            $daveTransfer->getId(),
            $daveAccount->getId(),
            $aliceAccount->getId(),
            '100.00',
            ['transactionId' => $daveTransfer->getId(), 'from' => $daveAccount->getId(), 'to' => $aliceAccount->getId(), 'amount' => '100.00'],
        ));

        $manager->flush();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeUser(ObjectManager $manager, string $email, string $name, string $plainPassword): User
    {
        $user = new User($email, '', $name);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $manager->persist($user);

        return $user;
    }
}
