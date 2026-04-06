<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuleEngine;

use App\Entity\Account;
use App\Exception\FraudAlertException;
use App\RuleEngine\TransferRules\FraudDetectionRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FraudDetectionRule.
 *
 * Covers both fraud triggers:
 *   1. Amount exceeds 100 000.00 threshold
 *   2. Cross-currency transfer (different currency codes)
 */
final class FraudDetectionRuleTest extends TestCase
{
    private const ID_1  = 'b0000001-0000-7000-8000-000000000001';
    private const ID_2  = 'b0000002-0000-7000-8000-000000000002';
    private const ID_10 = 'b0000010-0000-7000-8000-000000000010';
    private const ID_20 = 'b0000020-0000-7000-8000-000000000020';

    private FraudDetectionRule $rule;

    protected function setUp(): void
    {
        $this->rule = new FraudDetectionRule();
    }

    // ── Amount threshold ──────────────────────────────────────────────────────

    public function testAmountExceedingThresholdThrowsFraudAlertException(): void
    {
        $this->expectException(FraudAlertException::class);
        $this->expectExceptionMessageMatches('/fraud/i');

        $this->rule->check(
            $this->mockAccount(self::ID_1, currency: 'INR'),
            $this->mockAccount(self::ID_2, currency: 'INR'),
            '100000.01',
        );
    }

    public function testAmountAtExactThresholdPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check(
            $this->mockAccount(self::ID_1, currency: 'INR'),
            $this->mockAccount(self::ID_2, currency: 'INR'),
            '100000.00',
        );
    }

    public function testAmountBelowThresholdPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check(
            $this->mockAccount(self::ID_1, currency: 'INR'),
            $this->mockAccount(self::ID_2, currency: 'INR'),
            '99999.99',
        );
    }

    public function testFraudExceptionCarriesCorrectContext(): void
    {
        try {
            $this->rule->check(
                $this->mockAccount(self::ID_10, currency: 'INR'),
                $this->mockAccount(self::ID_20, currency: 'INR'),
                '100001.00',
            );
            $this->fail('Expected FraudAlertException.');
        } catch (FraudAlertException $e) {
            $this->assertSame(self::ID_10, $e->getFromAccountId());
            $this->assertSame(self::ID_20, $e->getToAccountId());
            $this->assertSame('100001.00', $e->getAmount());
        }
    }

    // ── Cross-currency ────────────────────────────────────────────────────────

    public function testCrossCurrencyTransferThrowsFraudAlertException(): void
    {
        $this->expectException(FraudAlertException::class);
        $this->expectExceptionMessageMatches('/cross-currency/i');

        $this->rule->check(
            $this->mockAccount(self::ID_1, currency: 'INR'),
            $this->mockAccount(self::ID_2, currency: 'USD'),
            '500.00',
        );
    }

    public function testSameCurrencyWithSmallAmountPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->rule->check(
            $this->mockAccount(self::ID_1, currency: 'INR'),
            $this->mockAccount(self::ID_2, currency: 'INR'),
            '0.01',
        );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function mockAccount(string $id, string $currency): Account
    {
        $account = $this->createStub(Account::class);
        $account->method('getId')->willReturn($id);
        $account->method('getCurrency')->willReturn($currency);

        return $account;
    }
}
