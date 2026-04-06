<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Represents the direction of a ledger entry in the double-entry bookkeeping model.
 *
 * Every successful transfer produces exactly two LedgerEntry rows:
 *   DEBIT  — decreases the sender's balance (money leaves the account)
 *   CREDIT — increases the receiver's balance (money enters the account)
 *
 * The sum of all DEBIT amounts must always equal the sum of all CREDIT amounts
 * for any given transaction, ensuring the ledger remains balanced.
 */
enum LedgerEntryType: string
{
    case DEBIT  = 'DEBIT';
    case CREDIT = 'CREDIT';
}
