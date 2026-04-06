<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Data-access layer for LedgerEntry entities.
 *
 * Every successful transfer or reversal produces exactly two LedgerEntry rows —
 * one DEBIT and one CREDIT — forming an immutable double-entry bookkeeping trail.
 * This repository's sole responsibility is to persist those entries within the
 * surrounding DB transaction managed by TransferService or ReversalService.
 */
class LedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    /**
     * Schedules the LedgerEntry for insertion on the next ORM flush.
     *
     * Does not flush — callers are responsible for calling
     * EntityManager::flush() inside their own transaction boundary.
     */
    public function save(LedgerEntry $entry): void
    {
        $this->getEntityManager()->persist($entry);
    }
}
