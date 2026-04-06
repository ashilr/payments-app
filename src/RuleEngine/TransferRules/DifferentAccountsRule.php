<?php

declare(strict_types=1);

namespace App\RuleEngine\TransferRules;

use App\Entity\Account;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ensures the sender and receiver are two distinct accounts.
 *
 * This is the first guard in the rule chain (priority 50) — there is no
 * meaningful business interpretation of "transferring to yourself", so the
 * request is rejected immediately before any balance or block checks run.
 *
 * The same check is also performed at the validation layer
 * (TransferRequestValidator) on the account UUIDs, so this rule
 * acts as a defence-in-depth backstop at the domain level.
 */
#[AutoconfigureTag('transfer.rule', ['priority' => 50])]
final class DifferentAccountsRule implements TransferRuleInterface
{
    /**
     * @throws \InvalidArgumentException When fromAccount and toAccount share the same account number.
     */
    public function check(Account $fromAccount, Account $toAccount, string $amount): void
    {
        if ($fromAccount->getAccountNumber() === $toAccount->getAccountNumber()) {
            throw new \InvalidArgumentException('Cannot transfer to the same account.');
        }
    }
}
