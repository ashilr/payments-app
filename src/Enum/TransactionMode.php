<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Describes the operational mode of a Transaction.
 *
 * Currently only TRANSFER is supported. This enum is intentionally kept open
 * for extension — future modes such as DIRECT_DEBIT, SCHEDULED, or BATCH
 * can be added here and used to drive mode-specific business rules without
 * changing existing logic.
 */
enum TransactionMode: string
{
    case TRANSFER = 'TRANSFER';
}
