<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Enum\LedgerEntryType;
use App\Enum\TransactionMode;
use App\Enum\TransactionStatus;
use App\Exception\AccountBlockedException;
use App\Repository\AccountRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reverses a previously successful fund transfer.
 *
 * A reversal moves the same amount back in the opposite direction: the original
 * receiver is debited and the original sender is credited. This produces a new
 * Transaction row (marked as a reversal) and two new LedgerEntry rows, restoring
 * both account balances to their pre-transfer state.
 *
 * The service follows the same atomicity and idempotency guarantees as TransferService:
 *   - All changes are wrapped in a single ACID database transaction.
 *   - Pessimistic write locks (SELECT FOR UPDATE) prevent concurrent balance corruption.
 *   - An Idempotency-Key prevents duplicate reversals on retried requests.
 *   - REVERSAL_FAILED audit entries are written via raw DBAL after a rollback so
 *     they are always persisted regardless of whether the DB transaction succeeded.
 *
 * Constraints enforced before any money moves:
 *   - Only transactions with status SUCCESS can be reversed.
 *   - A reversal transaction cannot itself be reversed.
 *   - Each transaction may be reversed at most once.
 *   - Both the original sender and receiver must be unblocked at reversal time.
 */
class ReversalService
{
    /**
     * @param EntityManagerInterface $em                   ORM entry point for flushing and direct connection access.
     * @param AccountRepository      $accountRepository    Provides pessimistic-lock account lookups.
     * @param TransactionRepository  $transactionRepository Lookups for the original transaction, idempotency key, and existing reversals.
     * @param LedgerEntryRepository  $ledgerRepository     Persists double-entry bookkeeping lines for the reversal.
     * @param AuditLogService        $auditService         Writes audit entries inside and outside DB transactions.
     * @param LoggerInterface        $logger               PSR-3 logger for structured error reporting.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepository,
        private readonly TransactionRepository  $transactionRepository,
        private readonly LedgerEntryRepository  $ledgerRepository,
        private readonly AuditLogService        $auditService,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Reverses a successful fund transfer and restores the original balances.
     *
     * The reversal direction is always original-receiver → original-sender
     * (i.e. the entity that received the money gives it back).
     *
     * If $idempotencyKey is provided and a Transaction with that key already
     * exists, the existing reversal Transaction is returned immediately without
     * any database writes — making the operation safe to retry.
     *
     * @param string      $transactionId  UUID of the original Transaction to reverse.
     * @param string      $reason         Non-empty human-readable explanation for the reversal
     *                                    (stored on the reversal Transaction and in the audit log).
     * @param string|null $idempotencyKey Optional client-supplied deduplication key.
     *
     * @return Transaction The committed SUCCESS reversal Transaction, or the existing reversal
     *                     Transaction when the idempotency key was already processed.
     *
     * @throws \InvalidArgumentException  When $reason is empty, or the original transaction cannot be found.
     * @throws \DomainException           When the original transaction is not in SUCCESS status, is itself a
     *                                    reversal, or has already been reversed.
     * @throws AccountBlockedException    (subclass of \DomainException) When either account involved is blocked.
     * @throws \RuntimeException          When one or both accounts cannot be loaded from the database.
     */
    public function reverse(
        string $transactionId,
        string $reason,
        ?string $idempotencyKey = null,
    ): Transaction {
        if ($reason === '') {
            throw new \InvalidArgumentException('A reversal reason must be provided.');
        }

        // Step 1: Begin database transaction
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        $reversalTx = null;

        try {
            // Step 2: Idempotency — return existing reversal if same key was already used
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = $this->transactionRepository->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    $connection->rollBack();
                    return $existing;
                }
            }

            // Step 3: Fetch and validate the original transaction
            $original = $this->transactionRepository->find($transactionId);

            if ($original === null) {
                throw new \InvalidArgumentException("Transaction '{$transactionId}' not found.");
            }

            if ($original->getStatus() !== TransactionStatus::SUCCESS) {
                throw new \DomainException('Only successful transactions can be reversed.');
            }

            if ($original->isReversal()) {
                throw new \DomainException('Reversal transactions cannot themselves be reversed.');
            }

            $existingReversal = $this->transactionRepository->findReversalForTransaction($transactionId);
            if ($existingReversal !== null) {
                throw new \DomainException('This transaction has already been reversed.');
            }

            // Step 4: Lock both accounts with PESSIMISTIC_WRITE
            // Reversal direction: original-TO debited, original-FROM credited
            $debitAccount  = $this->accountRepository->findForUpdate($original->getToAccount()->getId());
            $creditAccount = $this->accountRepository->findForUpdate($original->getFromAccount()->getId());

            if ($debitAccount === null || $creditAccount === null) {
                throw new \RuntimeException('One or both accounts could not be found.');
            }

            if ($debitAccount->isBlocked()) {
                throw new AccountBlockedException(
                    $debitAccount->getId(),
                    "Account {$debitAccount->getAccountNumber()} is blocked.",
                );
            }

            if ($creditAccount->isBlocked()) {
                throw new AccountBlockedException(
                    $creditAccount->getId(),
                    "Account {$creditAccount->getAccountNumber()} is blocked.",
                );
            }

            $amount = $original->getAmount();

            // Step 5: Create reversal Transaction (TO → FROM, same amount)
            $reversalTx = new Transaction(
                $debitAccount,
                $creditAccount,
                $amount,
                TransactionMode::TRANSFER,
                $idempotencyKey,
                true,
                $original,
                $reason,
            );
            $this->transactionRepository->save($reversalTx);

            // Step 6 & 7: Debit original receiver, credit original sender
            $newDebitBalance  = bcsub($debitAccount->getBalance(), $amount, 2);
            $debitAccount->setBalance($newDebitBalance);

            $newCreditBalance = bcadd($creditAccount->getBalance(), $amount, 2);
            $creditAccount->setBalance($newCreditBalance);

            // Step 8: Double-entry ledger entries
            $this->ledgerRepository->save(new LedgerEntry(
                $debitAccount,
                $reversalTx,
                LedgerEntryType::DEBIT,
                $amount,
                $newDebitBalance,
            ));

            $this->ledgerRepository->save(new LedgerEntry(
                $creditAccount,
                $reversalTx,
                LedgerEntryType::CREDIT,
                $amount,
                $newCreditBalance,
            ));

            // Step 9: Mark reversal SUCCESS
            $reversalTx->markCompleted();

            // Step 10: Audit log (inside the DB transaction — rolled back if flush fails)
            $this->auditService->log(
                'REVERSAL_SUCCESS',
                [
                    'originalTransactionId' => $original->getId(),
                    'reversalTransactionId' => $reversalTx->getId(),
                    'from'   => $debitAccount->getId(),
                    'to'     => $creditAccount->getId(),
                    'amount' => $amount,
                    'reason' => $reason,
                ],
                'Account',
                $debitAccount->getId(),
            );

            // Step 11: Flush all ORM changes and commit
            $this->em->flush();
            $connection->commit();

            return $reversalTx;

        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            // Written after rollback via raw DBAL (auto-commit) so it always persists
            $this->auditService->log(
                'REVERSAL_FAILED',
                [
                    'originalTransactionId' => $transactionId,
                    'reversalTransactionId' => $reversalTx?->getId(),
                    'reason' => $reason,
                    'error'  => $e->getMessage(),
                ],
                'Account',
                null,
            );

            $this->logger->error('Reversal failed', [
                'transactionId' => $transactionId,
                'reason'        => $reason,
                'error'         => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
