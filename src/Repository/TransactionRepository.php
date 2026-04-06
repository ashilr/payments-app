<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Data-access layer for Transaction entities.
 *
 * Provides the idempotency-key lookup used by TransferService and ReversalService
 * to detect duplicate requests before any balance changes are applied.
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Schedules the Transaction for insertion on the next ORM flush.
     *
     * Does not flush — callers are responsible for calling
     * EntityManager::flush() inside their own transaction boundary.
     */
    public function save(Transaction $transaction): void
    {
        $this->getEntityManager()->persist($transaction);
    }

    /**
     * Looks up a Transaction by its client-supplied idempotency key.
     *
     * Returns the existing Transaction when a duplicate request is detected,
     * allowing callers to return the cached result without re-processing.
     *
     * @param string $key The exact Idempotency-Key header value supplied by the client.
     */
    public function findByIdempotencyKey(string $key): ?Transaction
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    /**
     * Finds the reversal Transaction that references the given original transaction ID.
     *
     * Returns null when the original transaction has not yet been reversed,
     * which is the expected state before a reversal is created.
     *
     * @param string $transactionId UUID of the original Transaction.
     */
    public function findReversalForTransaction(string $transactionId): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->where('t.referenceTransaction = :id')
            ->andWhere('t.isReversal = true')
            ->setParameter('id', $transactionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the most recent transactions involving the given account (either as
     * sender or receiver), ordered by creation time descending.
     *
     * @param string $accountId UUID of the account.
     * @param int    $limit     Maximum number of results to return (default 10).
     * @return Transaction[]
     */
    public function findRecentByAccount(string $accountId, int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.fromAccount = :id OR t.toAccount = :id')
            ->setParameter('id', $accountId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
