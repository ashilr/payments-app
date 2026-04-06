<?php

declare(strict_types=1);

namespace App\RuleEngine\TransferRules;

use App\Entity\Account;

interface TransferRuleInterface
{
    /**
     * Evaluate the rule for the given transfer context.
     * Throw a domain-appropriate exception if the rule is violated.
     */
    public function check(Account $fromAccount, Account $toAccount, string $amount): void;
}
