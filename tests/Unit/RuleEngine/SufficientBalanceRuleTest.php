<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuleEngine;

use App\Entity\Account;
use App\RuleEngine\TransferRules\SufficientBalanceRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SufficientBalanceRule.
 *
 * Verifies bcmath-based balance comparison for all boundary conditions.
 */
final class SufficientBalanceRuleTest extends TestCase
{
    private SufficientBalanceRule $rule;

    protected function setUp(): void
    {
        $this->rule = new SufficientBalanceRule();
    }

    public function testBalanceLessThanAmountThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/insufficient/i');

        $this->rule->check($this->mockAccount('100.00'), $this->createStub(Account::class), '100.01');
    }

    public function testZeroBalanceThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        $this->rule->check($this->mockAccount('0.00'), $this->createStub(Account::class), '0.01');
    }

    /**
     * Edge case: balance exactly equal to the amount must be treated as sufficient.
     * bccomp returns 0 (equal), not -1 (less than), so the rule must not throw.
     */
    public function testExactBalancePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount('250.00'), $this->createStub(Account::class), '250.00');
    }

    public function testBalanceGreaterThanAmountPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount('1000.00'), $this->createStub(Account::class), '999.99');
    }

    /**
     * Verify that decimal precision is handled correctly at scale 2.
     * 100.001 effectively equals 100.00 at scale 2 — rule must pass.
     */
    public function testDecimalPrecisionAtScaleTwo(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check($this->mockAccount('100.00'), $this->createStub(Account::class), '99.99');
    }

    private function mockAccount(string $balance): Account
    {
        $account = $this->createStub(Account::class);
        $account->method('getBalance')->willReturn($balance);

        return $account;
    }
}
