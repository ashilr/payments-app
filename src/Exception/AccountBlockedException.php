<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by AccountNotBlockedRule when either the sender or receiver account
 * has the is_blocked flag set.
 *
 * Extends DomainException so that ApiExceptionSubscriber maps it to HTTP 422.
 * Carries the offending account's surrogate ID so that TransferService can
 * write an ACCOUNT_BLOCKED audit entry with the correct context after rollback.
 */
final class AccountBlockedException extends \DomainException
{
    /**
     * @param string $accountId UUID of the blocked Account.
     * @param string $message   Human-readable description for the error response.
     */
    public function __construct(
        private readonly string $accountId,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Returns the UUID of the account that triggered the block. */
    public function getAccountId(): string
    {
        return $this->accountId;
    }
}
