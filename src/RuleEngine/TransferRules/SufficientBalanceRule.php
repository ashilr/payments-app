<?php

declare(strict_types=1);

namespace App\RuleEngine\TransferRules;

use App\Entity\Account;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ensures the sender holds enough funds to cover the requested transfer amount.
 *
 * Runs at priority 30 — after blocked-account checks so that fraud / compliance
 * events are audited before a balance shortfall is detected.
 *
 * The comparison uses bcmath at scale 2 to avoid floating-point rounding errors
 * on decimal balances. A balance equal to the transfer amount is treated as
 * sufficient (the entire account balance may be transferred out).
 */
#[AutoconfigureTag('transfer.rule', ['priority' => 30])]
final class SufficientBalanceRule implements TransferRuleInterface
{
    /**
     * @throws \DomainException When the sender's balance is strictly less than the requested amount.
     */
    public function check(Account $fromAccount, Account $toAccount, string $amount): void
    {
        if (bccomp($fromAccount->getBalance(), $amount, 2) < 0) {
            throw new \DomainException('Insufficient balance.');
        }
    }
}
