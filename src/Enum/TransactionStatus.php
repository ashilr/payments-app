<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle status of a Transaction.
 *
 * Transitions follow a strict one-way path:
 *
 *   PENDING → SUCCESS  (on EntityManager::flush() + Connection::commit())
 *   PENDING → FAILED   (conceptually; in practice the DB transaction is rolled
 *                        back before flush, so a FAILED row is never persisted)
 *
 * A Transaction is created in PENDING status and moved to SUCCESS by
 * Transaction::markCompleted() immediately before the ORM flush. If anything
 * throws before the commit, the entire unit of work is discarded.
 */
enum TransactionStatus: string
{
    case PENDING = 'PENDING';
    case SUCCESS = 'SUCCESS';
    case FAILED  = 'FAILED';
}
