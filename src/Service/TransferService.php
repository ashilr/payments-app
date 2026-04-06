<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Enum\LedgerEntryType;
use App\Enum\TransactionMode;
use App\Exception\AccountBlockedException;
use App\Exception\FraudAlertException;
use App\Repository\AccountRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\TransactionRepository;
use App\RuleEngine\TransferRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates an atomic fund transfer between two accounts.
 *
 * Every transfer is executed inside a single ACID database transaction.
 * The execution pipeline is:
 *
 *   1. Open a DB transaction.
 *   2. Short-circuit on a duplicate Idempotency-Key (return the cached result).
 *   3. Acquire PESSIMISTIC_WRITE locks on both accounts (SELECT FOR UPDATE)
 *      to prevent concurrent balance modifications.
 *   4. Run all business rules via TransferRuleEngine (different accounts →
 *      not blocked → sufficient balance → fraud detection → transfer limits).
 *   5. Persist the Transaction entity (PENDING status).
 *   6. Debit the sender and credit the receiver using bcmath string arithmetic
 *      to avoid floating-point rounding errors.
 *   7. Persist two LedgerEntry rows (one DEBIT, one CREDIT).
 *   8. Mark the Transaction SUCCESS and write a TRANSFER_SUCCESS audit entry.
 *   9. Flush all ORM changes and COMMIT.
 *
 * On any failure the DB transaction is rolled back, typed exceptions are
 * given specific audit events (ACCOUNT_BLOCKED, FRAUD_ALERT), and a
 * TRANSFER_FAILED entry is always written via raw DBAL in auto-commit mode
 * so it persists independently of the rollback.
 */
class TransferService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepository,
        private readonly TransactionRepository  $transactionRepository,
        private readonly LedgerEntryRepository  $ledgerRepository,
        private readonly TransferRuleEngine     $ruleEngine,
        private readonly AuditLogService        $auditService,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Transfers funds from one account to another within a single ACID transaction.
     *
     * All monetary arithmetic uses bcmath at scale 2 so that decimal precision is
     * preserved without floating-point rounding errors.
     *
     * If $idempotencyKey is provided and a Transaction with that key already exists,
     * the existing Transaction is returned immediately without any database writes —
     * making the operation safe to retry on network failures.
     *
     * @param string      $fromAccountId  Sender account UUID (primary key).
     * @param string      $toAccountId    Receiver account UUID (must differ from sender).
     * @param string      $amount         Positive decimal string with up to two decimal places (e.g. "250.00").
     * @param string|null $idempotencyKey Optional client-supplied deduplication key.
     *
     * @return Transaction The committed SUCCESS Transaction, or the original Transaction when the
     *                     idempotency key was already processed.
     *
     * @throws \RuntimeException        When either account id cannot be resolved to an Account row.
     * @throws \DomainException         When a business rule is violated: sender or receiver is blocked,
     *                                  insufficient balance, per-type transfer limit exceeded, or a
     *                                  fraud-detection heuristic fires.
     * @throws AccountBlockedException  (subclass of \DomainException) When either account is flagged as blocked.
     * @throws FraudAlertException      (subclass of \DomainException) When the fraud-detection rule fires.
     */
    public function transfer(
        string $fromAccountId,
        string $toAccountId,
        string $amount,
        ?string $idempotencyKey = null,
    ): Transaction {
        // Step 1: Begin database transaction
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        $transaction = null;
        $fromAccount = null;
        $toAccount   = null;

        try {
            // Step 2: Check idempotency key — return cached result if duplicate
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = $this->transactionRepository->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    $connection->rollBack();
                    return $existing;
                }
            }

            // Step 3: Lock sender and receiver with PESSIMISTIC_WRITE (SELECT FOR UPDATE)
            $fromAccount = $this->accountRepository->findForUpdate($fromAccountId);
            $toAccount   = $this->accountRepository->findForUpdate($toAccountId);

            if ($fromAccount === null || $toAccount === null) {
                throw new \RuntimeException('Account not found.');
            }

            // Steps 4–7: Run all business rules through the rule engine
            $this->ruleEngine->apply($fromAccount, $toAccount, $amount);

            // Step 8: Create Transaction entity
            $transaction = new Transaction(
                $fromAccount,
                $toAccount,
                $amount,
                TransactionMode::TRANSFER,
                $idempotencyKey,
            );
            $this->transactionRepository->save($transaction);

            // Step 9: Debit sender account
            $newFromBalance = bcsub($fromAccount->getBalance(), $amount, 2);
            $fromAccount->setBalance($newFromBalance);

            // Step 10: Credit receiver account
            $newToBalance = bcadd($toAccount->getBalance(), $amount, 2);
            $toAccount->setBalance($newToBalance);

            // Step 11: Create two LedgerEntry records
            $this->ledgerRepository->save(new LedgerEntry(
                $fromAccount,
                $transaction,
                LedgerEntryType::DEBIT,
                $amount,
                $newFromBalance,
            ));

            $this->ledgerRepository->save(new LedgerEntry(
                $toAccount,
                $transaction,
                LedgerEntryType::CREDIT,
                $amount,
                $newToBalance,
            ));

            // Step 12: Mark transaction SUCCESS
            $transaction->markCompleted();

            // Step 13: Write audit log (inside transaction — rolled back on flush failure)
            $this->auditService->log(
                'TRANSFER_SUCCESS',
                [
                    'transactionId' => $transaction->getId(),
                    'from'          => $fromAccount->getId(),
                    'to'            => $toAccount->getId(),
                    'amount'        => $amount,
                ],
                'Account',
                $fromAccount->getId(),
            );

            // Step 14: Flush all ORM changes and commit
            $this->em->flush();
            $connection->commit();

            return $transaction;

        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            // Typed exceptions carry the audit context — log specific events after rollback
            if ($e instanceof AccountBlockedException) {
                $this->auditService->log(
                    'ACCOUNT_BLOCKED',
                    ['accountId' => $e->getAccountId(), 'reason' => $e->getMessage()],
                    'Account',
                    $e->getAccountId(),
                );
            } elseif ($e instanceof FraudAlertException) {
                $this->auditService->log(
                    'FRAUD_ALERT',
                    [
                        'from'   => $e->getFromAccountId(),
                        'to'     => $e->getToAccountId(),
                        'amount' => $e->getAmount(),
                        'reason' => $e->getMessage(),
                    ],
                    'Account',
                    $e->getFromAccountId(),
                );
            }

            // Always written after rollback via DBAL auto-commit so it always persists
            $this->auditService->log(
                'TRANSFER_FAILED',
                [
                    'transactionId' => $transaction?->getId(),
                    'from'          => $fromAccount?->getId(),
                    'to'            => $toAccount?->getId(),
                    'amount'        => $amount,
                    'error'         => $e->getMessage(),
                ],
                'Account',
                $fromAccount?->getId(),
            );

            $this->logger->error('Transfer failed', [
                'error'  => $e->getMessage(),
                'from'   => $fromAccountId,
                'to'     => $toAccountId,
                'amount' => $amount,
            ]);

            throw $e;
        }
    }
}
