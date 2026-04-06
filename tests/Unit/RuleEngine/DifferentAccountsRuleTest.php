<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuleEngine;

use App\Entity\Account;
use App\RuleEngine\TransferRules\DifferentAccountsRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DifferentAccountsRule.
 *
 * Account objects are mocked — no database or kernel required.
 */
final class DifferentAccountsRuleTest extends TestCase
{
    private DifferentAccountsRule $rule;

    protected function setUp(): void
    {
        $this->rule = new DifferentAccountsRule();
    }

    public function testSameAccountNumberThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/same account/i');

        $account = $this->mockAccount('ACCAA00000001');

        $this->rule->check($account, $account, '100.00');
    }

    public function testDifferentAccountNumbersPassWithoutException(): void
    {
        $this->expectNotToPerformAssertions();

        $from = $this->mockAccount('ACCAA00000001');
        $to   = $this->mockAccount('ACCBB00000002');

        $this->rule->check($from, $to, '100.00');
    }

    private function mockAccount(string $accountNumber): Account
    {
        $account = $this->createStub(Account::class);
        $account->method('getAccountNumber')->willReturn($accountNumber);

        return $account;
    }
}
