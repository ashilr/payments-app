<?php

declare(strict_types=1);

namespace App\RuleEngine\TransferRules;

use App\Entity\Account;
use App\Enum\UserStatus;
use App\Exception\AccountBlockedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Rejects transfers that involve a blocked account (either sender or receiver).
 *
 * Runs at priority 40 — after the same-account check but before the balance
 * check — so that blocked-account events are audited correctly even when the
 * sender has insufficient funds.
 *
 * A blocked account represents a compliance or fraud hold. Blocking the
 * receiver prevents funds from being funnelled into a flagged account.
 * A user with status BLOCKED is treated the same way for any account they own.
 * Both cases throw AccountBlockedException, which is a subclass of
 * DomainException and carries the offending account ID so the audit service
 * can log it with the correct context.
 */
#[AutoconfigureTag('transfer.rule', ['priority' => 40])]
final class AccountNotBlockedRule implements TransferRuleInterface
{
    /**
     * @throws AccountBlockedException When the sender or receiver account has is_blocked = true.
     */
    public function check(Account $fromAccount, Account $toAccount, string $amount): void
    {
        if ($fromAccount->isBlocked()) {
            throw new AccountBlockedException(
                $fromAccount->getId(),
                'Sender account is blocked.',
            );
        }

        if ($toAccount->isBlocked()) {
            throw new AccountBlockedException(
                $toAccount->getId(),
                'Receiver account is blocked.',
            );
        }

        if ($fromAccount->getUser()->getStatus() === UserStatus::BLOCKED) {
            throw new AccountBlockedException(
                $fromAccount->getId(),
                'Sender user is blocked.',
            );
        }

        if ($toAccount->getUser()->getStatus() === UserStatus::BLOCKED) {
            throw new AccountBlockedException(
                $toAccount->getId(),
                'Receiver user is blocked.',
            );
        }
    }
}
