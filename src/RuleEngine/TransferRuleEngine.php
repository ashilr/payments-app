<?php

declare(strict_types=1);

namespace App\RuleEngine;

use App\Entity\Account;
use App\RuleEngine\TransferRules\TransferRuleInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Runs every registered TransferRuleInterface in priority order (highest first).
 *
 * Rules self-register via #[AutoconfigureTag('transfer.rule', ['priority' => N])].
 * Execution order: DifferentAccounts(50) → AccountNotBlocked(40) →
 *                  SufficientBalance(30) → FraudDetection(20) → TransferLimit(10)
 */
final class TransferRuleEngine
{
    /** @param iterable<TransferRuleInterface> $rules */
    public function __construct(
        #[TaggedIterator('transfer.rule')]
        private readonly iterable $rules,
    ) {}

    /**
     * Runs every registered rule in priority order (highest priority first).
     *
     * Execution stops at the first rule that throws. All exceptions are allowed
     * to propagate — callers (TransferService, ReversalService) are responsible
     * for catching and handling them within their own DB transaction boundary.
     *
     * @throws \InvalidArgumentException  From DifferentAccountsRule (priority 50).
     * @throws AccountBlockedException    From AccountNotBlockedRule  (priority 40).
     * @throws \DomainException           From SufficientBalanceRule  (priority 30),
     *                                    TransferLimitRule           (priority 10).
     * @throws FraudAlertException        From FraudDetectionRule     (priority 20).
     */
    public function apply(Account $fromAccount, Account $toAccount, string $amount): void
    {
        foreach ($this->rules as $rule) {
            $rule->check($fromAccount, $toAccount, $amount);
        }
    }
}
