<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Exception\ValidationException;
use App\Validator\TransferRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransferRequestValidator.
 *
 * All tests run in complete isolation — no kernel boot, no database, no HTTP
 * stack. The validator is instantiated directly and exercised with raw arrays
 * to verify that every rule fires correctly.
 */
final class TransferRequestValidatorTest extends TestCase
{
    private TransferRequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TransferRequestValidator();
    }

    // ── Valid input ────────────────────────────────────────────────────────────

    public function testValidInputReturnsTransferRequestDto(): void
    {
        $dto = $this->validator->validate($this->validPayload([
            'amount' => '250.00',
        ]));

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $dto->fromAccountId);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $dto->toAccountId);
        $this->assertSame('250.00', $dto->amount);
    }

    public function testIntegerAmountIsAccepted(): void
    {
        $dto = $this->validator->validate($this->validPayload(['amount' => '100']));

        $this->assertSame('100', $dto->amount);
    }

    public function testSingleDecimalPlaceIsAccepted(): void
    {
        $dto = $this->validator->validate($this->validPayload(['amount' => '99.9']));

        $this->assertSame('99.9', $dto->amount);
    }

    public function testUuidIsNormalizedToLowercase(): void
    {
        $dto = $this->validator->validate([
            'from_account_id' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
            'to_account_id'   => '11111111-2222-3333-4444-555555555555',
            'amount'          => '1.00',
        ]);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $dto->fromAccountId);
    }

    // ── Missing / blank fields ─────────────────────────────────────────────────

    public function testMissingFromAccountIdAddsFieldError(): void
    {
        $p = $this->validPayload();
        unset($p['from_account_id']);
        $this->assertValidationError($p, 'from_account_id');
    }

    public function testMissingToAccountIdAddsFieldError(): void
    {
        $p = $this->validPayload();
        unset($p['to_account_id']);
        $this->assertValidationError($p, 'to_account_id');
    }

    public function testMissingAmountAddsFieldError(): void
    {
        $p = $this->validPayload();
        unset($p['amount']);
        $this->assertValidationError($p, 'amount');
    }

    public function testEmptyBodyReturnsAllFieldErrors(): void
    {
        try {
            $this->validator->validate([]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('from_account_id', $errors);
            $this->assertArrayHasKey('to_account_id',   $errors);
            $this->assertArrayHasKey('amount',          $errors);
        }
    }

    // ── UUID validation ────────────────────────────────────────────────────────

    public function testSameAccountIdAddsToAccountError(): void
    {
        $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $this->assertValidationError(
            $this->validPayload([
                'from_account_id' => $id,
                'to_account_id'   => $id,
            ]),
            'to_account_id',
        );
    }

    public function testInvalidUuidFormatIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload([
                'from_account_id' => 'not-a-uuid',
            ]),
            'from_account_id',
        );
    }

    public function testUuidWithoutHyphensIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload([
                'from_account_id' => '550e8400e29b41d4a716446655440000',
            ]),
            'from_account_id',
        );
    }

    // ── Amount validation ──────────────────────────────────────────────────────

    public function testZeroAmountIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload(['amount' => '0.00']),
            'amount',
        );
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload(['amount' => '-50.00']),
            'amount',
        );
    }

    public function testAmountWithThreeDecimalPlacesIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload(['amount' => '10.001']),
            'amount',
        );
    }

    public function testNonNumericAmountIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload(['amount' => 'one-hundred']),
            'amount',
        );
    }

    public function testBlankAmountStringIsRejected(): void
    {
        $this->assertValidationError(
            $this->validPayload(['amount' => '']),
            'amount',
        );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'from_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'to_account_id'   => '11111111-2222-3333-4444-555555555555',
            'amount'          => '100.00',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertValidationError(array $data, string $field): void
    {
        try {
            $this->validator->validate($data);
            $this->fail("Expected ValidationException for field '{$field}' was not thrown.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey(
                $field,
                $e->getErrors(),
                "Expected a validation error for field '{$field}'.",
            );
        }
    }
}
