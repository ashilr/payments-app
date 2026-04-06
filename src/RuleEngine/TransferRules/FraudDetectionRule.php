<?php

declare(strict_types=1);

namespace App\RuleEngine\TransferRules;

use App\Entity\Account;
use App\Exception\FraudAlertException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Applies heuristic fraud-detection checks to every transfer request.
 *
 * Runs at priority 20 — after balance validation so genuine insufficient-funds
 * errors are surfaced first, but before the per-type transfer-limit check.
 *
 * Current heuristics:
 *
 *   1. Cross-currency transfers — accounts denominated in different currencies
 *      are not supported and indicate a misconfigured or malicious request.
 *
 *   2. Large single-transaction threshold — any transfer above 100 000.00
 *      (regardless of account type) is flagged. This is a baseline safeguard;
 *      a production system would replace or supplement this with a dedicated
 *      risk-scoring service.
 *
 * Both conditions throw FraudAlertException (a subclass of DomainException),
 * which is caught by TransferService and written to the audit log as
 * FRAUD_ALERT even after the DB transaction is rolled back.
 */
#[AutoconfigureTag('transfer.rule', ['priority' => 20])]
final class FraudDetectionRule implements TransferRuleInterface
{
    /** Single-transaction amount above which a transfer is flagged as suspicious. */
    private const FRAUD_AMOUNT_THRESHOLD = '100000.00';

    /**
     * @throws FraudAlertException When the transfer is flagged as potentially fraudulent.
     */
    public function check(Account $fromAccount, Account $toAccount, string $amount): void
    {
        if ($fromAccount->getCurrency() !== $toAccount->getCurrency()) {
            throw new FraudAlertException(
                $fromAccount->getId(),
                $toAccount->getId(),
                $amount,
                'Cross-currency transfers are not supported.',
            );
        }

        if (bccomp($amount, self::FRAUD_AMOUNT_THRESHOLD, 2) === 1) {
            throw new FraudAlertException(
                $fromAccount->getId(),
                $toAccount->getId(),
                $amount,
                'Transfer flagged by fraud detection.',
            );
        }
    }
}
