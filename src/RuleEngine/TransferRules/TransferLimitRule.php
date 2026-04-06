<?php

declare(strict_types=1);

namespace App\RuleEngine\TransferRules;

use App\Entity\Account;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Per-transaction limits keyed by account type.
 *
 * SAVINGS  — everyday personal account, conservative limit
 * CURRENT  — business account, high-volume transfers allowed
 * SALARY   — payroll disbursements, moderate limit
 * FIXED    — term-deposit account, minimal liquidity by design
 */
#[AutoconfigureTag('transfer.rule', ['priority' => 10])]
final class TransferLimitRule implements TransferRuleInterface
{
    private const LIMITS = [
        'SAVINGS' => '50000.00',
        'CURRENT' => '500000.00',
        'SALARY'  => '100000.00',
        'FIXED'   => '10000.00',
    ];

    public function check(Account $fromAccount, Account $toAccount, string $amount): void
    {
        $type  = $fromAccount->getAccountType();
        $limit = self::LIMITS[$type->value]
            ?? throw new \DomainException(
                sprintf('No transfer limit configured for account type "%s".', $type->value)
            );

        if (bccomp($amount, $limit, 2) === 1) {
            throw new \DomainException(sprintf(
                'Transfer of %s exceeds the %s account limit of %s.',
                $amount,
                $type->value,
                $limit,
            ));
        }
    }
}
