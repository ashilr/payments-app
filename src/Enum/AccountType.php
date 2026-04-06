<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Classifies the purpose and behaviour of an Account.
 *
 * The account type determines which per-transaction transfer limit applies
 * (see TransferLimitRule) and may be used in future to drive interest rates,
 * withdrawal restrictions, or product-specific business rules.
 *
 * SAVINGS  — everyday personal savings account; conservative transfer cap (50 000)
 * CURRENT  — business/current account; high-volume transfers allowed (500 000)
 * SALARY   — payroll disbursement account; moderate cap (100 000)
 * FIXED    — term-deposit account; minimal liquidity by design (10 000)
 */
enum AccountType: string
{
    case SAVINGS = 'SAVINGS';
    case CURRENT = 'CURRENT';
    case SALARY  = 'SALARY';
    case FIXED   = 'FIXED';
}
