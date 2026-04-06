<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuleEngine;

use App\Entity\Account;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\AccountBlockedException;
use App\RuleEngine\TransferRules\AccountNotBlockedRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccountNotBlockedRule.
 */
final class AccountNotBlockedRuleTest extends TestCase
{
    private const ID_1 = 'a0000001-0000-7000-8000-000000000001';
    private const ID_2 = 'a0000002-0000-7000-8000-000000000002';
    private const ID_7 = 'a0000007-0000-7000-8000-000000000007';
    private const ID_8 = 'a0000008-0000-7000-8000-000000000008';

    private AccountNotBlockedRule $rule;

    protected function setUp(): void
    {
        $this->rule = new AccountNotBlockedRule();
    }

    public function testBlockedSenderThrowsAccountBlockedException(): void
    {
        $this->expectException(AccountBlockedException::class);
        $this->expectExceptionMessageMatches('/blocked/i');

        $this->rule->check(
            $this->mockAccount(self::ID_1, blocked: true, userBlocked: false),
            $this->mockAccount(self::ID_2, blocked: false, userBlocked: false),
            '100.00',
        );
    }

    public function testBlockedReceiverThrowsAccountBlockedException(): void
    {
        $this->expectException(AccountBlockedException::class);
        $this->expectExceptionMessageMatches('/blocked/i');

        $this->rule->check(
            $this->mockAccount(self::ID_1, blocked: false, userBlocked: false),
            $this->mockAccount(self::ID_2, blocked: true, userBlocked: false),
            '100.00',
        );
    }

    public function testBlockedSenderExceptionCarriesCorrectAccountId(): void
    {
        try {
            $this->rule->check(
                $this->mockAccount(self::ID_7, blocked: true, userBlocked: false),
                $this->mockAccount(self::ID_8, blocked: false, userBlocked: false),
                '50.00',
            );
            $this->fail('Expected AccountBlockedException.');
        } catch (AccountBlockedException $e) {
            $this->assertSame(self::ID_7, $e->getAccountId());
        }
    }

    public function testBothUnblockedPassesWithoutException(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check(
            $this->mockAccount(self::ID_1, blocked: false, userBlocked: false),
            $this->mockAccount(self::ID_2, blocked: false, userBlocked: false),
            '100.00',
        );
    }

    public function testBlockedSenderUserThrowsAccountBlockedException(): void
    {
        $this->expectException(AccountBlockedException::class);
        $this->expectExceptionMessageMatches('/user is blocked/i');

        $this->rule->check(
            $this->mockAccount(self::ID_1, blocked: false, userBlocked: true),
            $this->mockAccount(self::ID_2, blocked: false, userBlocked: false),
            '100.00',
        );
    }

    public function testBlockedReceiverUserThrowsAccountBlockedException(): void
    {
        $this->expectException(AccountBlockedException::class);
        $this->expectExceptionMessageMatches('/user is blocked/i');

        $this->rule->check(
            $this->mockAccount(self::ID_1, blocked: false, userBlocked: false),
            $this->mockAccount(self::ID_2, blocked: false, userBlocked: true),
            '100.00',
        );
    }

    private function mockAccount(string $id, bool $blocked, bool $userBlocked): Account
    {
        $user = $this->createStub(User::class);
        $user->method('getStatus')->willReturn($userBlocked ? UserStatus::BLOCKED : UserStatus::ACTIVE);

        $account = $this->createStub(Account::class);
        $account->method('getId')->willReturn($id);
        $account->method('isBlocked')->willReturn($blocked);
        $account->method('getUser')->willReturn($user);

        return $account;
    }
}
