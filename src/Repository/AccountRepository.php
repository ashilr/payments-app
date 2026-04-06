<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Data-access layer for Account entities.
 *
 * The two findForUpdate* methods are the hot path for every transfer: they
 * acquire a PESSIMISTIC_WRITE (SELECT FOR UPDATE) lock so that no other
 * concurrent transaction can read or modify the same row until the current
 * transaction commits or rolls back. This prevents double-spend races.
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Returns the Account with the given UUID primary key, or null if not found.
     */
    public function findById(string $id): ?Account
    {
        return $this->find($id);
    }

    /**
     * Fetches an Account by UUID and immediately acquires a
     * PESSIMISTIC_WRITE lock (SELECT FOR UPDATE) on the row.
     *
     * Must be called inside an active database transaction.
     */
    public function findForUpdate(string $id): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Fetches an Account by its unique account number and immediately acquires
     * a PESSIMISTIC_WRITE lock (SELECT FOR UPDATE) on the row.
     *
     * Must be called inside an active database transaction.
     *
     * @param string $accountNumber The ACC + 10 hex-char account identifier.
     */
    public function findForUpdateByAccountNumber(string $accountNumber): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.accountNumber = :accountNumber')
            ->setParameter('accountNumber', $accountNumber)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
