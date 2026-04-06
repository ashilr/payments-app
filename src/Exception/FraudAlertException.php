<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by FraudDetectionRule when a transfer is flagged as potentially fraudulent.
 *
 * Extends DomainException so that ApiExceptionSubscriber maps it to HTTP 422.
 * Carries the full transfer context (from/to account IDs and amount) so that
 * TransferService can write a FRAUD_ALERT audit entry with all relevant details
 * after the DB transaction is rolled back.
 *
 * Current triggers:
 *   - Cross-currency transfers (accounts with different currency codes)
 *   - Single transfer amount exceeding the 100 000.00 threshold
 */
final class FraudAlertException extends \DomainException
{
    /**
     * @param string $fromAccountId UUID of the sender account.
     * @param string $toAccountId   UUID of the receiver account.
     * @param string $amount        The transfer amount that triggered the alert.
     * @param string $message       Human-readable description of the fraud signal.
     */
    public function __construct(
        private readonly string $fromAccountId,
        private readonly string $toAccountId,
        private readonly string $amount,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Returns the UUID of the sender account involved in the alert. */
    public function getFromAccountId(): string
    {
        return $this->fromAccountId;
    }

    /** Returns the UUID of the receiver account involved in the alert. */
    public function getToAccountId(): string
    {
        return $this->toAccountId;
    }

    /** Returns the transfer amount that triggered the fraud alert. */
    public function getAmount(): string
    {
        return $this->amount;
    }
}
