<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuleEngine;

use App\Entity\Account;
use App\Enum\AccountType;
use App\RuleEngine\TransferRules\TransferLimitRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransferLimitRule.
 *
 * Verifies that per-type caps are enforced correctly at and around each limit.
 *
 * Limits under test:
 *   SAVINGS  50 000.00
 *   CURRENT 500 000.00
 *   SALARY  100 000.00
 *   FIXED    10 000.00
 */
final class TransferLimitRuleTest extends TestCase
{
    private TransferLimitRule $rule;

    protected function setUp(): void
    {
        $this->rule = new TransferLimitRule();
    }

    // ── SAVINGS ───────────────────────────────────────────────────────────────

    public function testSavingsLimitExceededThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/limit/i');

        $this->rule->check($this->mockAccount(AccountType::SAVINGS), $this->createStub(Account::class), '50000.01');
    }

    public function testSavingsAtExactLimitPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount(AccountType::SAVINGS), $this->createStub(Account::class), '50000.00');
    }

    public function testSavingsBelowLimitPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount(AccountType::SAVINGS), $this->createStub(Account::class), '49999.99');
    }

    // ── CURRENT ───────────────────────────────────────────────────────────────

    public function testCurrentLimitExceededThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        $this->rule->check($this->mockAccount(AccountType::CURRENT), $this->createStub(Account::class), '500000.01');
    }

    public function testCurrentAtExactLimitPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount(AccountType::CURRENT), $this->createStub(Account::class), '500000.00');
    }

    // ── SALARY ────────────────────────────────────────────────────────────────

    public function testSalaryLimitExceededThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        $this->rule->check($this->mockAccount(AccountType::SALARY), $this->createStub(Account::class), '100000.01');
    }

    public function testSalaryAtExactLimitPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount(AccountType::SALARY), $this->createStub(Account::class), '100000.00');
    }

    // ── FIXED ─────────────────────────────────────────────────────────────────

    public function testFixedLimitExceededThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        $this->rule->check($this->mockAccount(AccountType::FIXED), $this->createStub(Account::class), '10000.01');
    }

    public function testFixedAtExactLimitPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount(AccountType::FIXED), $this->createStub(Account::class), '10000.00');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function mockAccount(AccountType $type): Account
    {
        $account = $this->createStub(Account::class);
        $account->method('getAccountType')->willReturn($type);

        return $account;
    }
}
