<?php

declare(strict_types=1);

namespace App\Validator;

use App\DTO\TransferRequest;
use App\Exception\ValidationException;

/**
 * Validates raw transfer request data and builds a typed TransferRequest DTO.
 *
 * Required JSON fields:
 *   from_account_id, to_account_id — UUIDs (RFC 4122 string); must differ
 *   amount — positive decimal, max 2 decimal places
 */
final class TransferRequestValidator
{
    private const AMOUNT_PATTERN = '/^\d+(\.\d{1,2})?$/';
    private const UUID_PATTERN   = '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/';

    /**
     * @param array<string, mixed> $data  Decoded JSON body from the request
     *
     * @throws ValidationException when one or more fields are invalid
     */
    public function validate(array $data): TransferRequest
    {
        $errors = [];

        $fromAccountId = $this->extractUuid($data, 'from_account_id', $errors);
        $toAccountId   = $this->extractUuid($data, 'to_account_id', $errors);

        if (
            $fromAccountId !== null
            && $toAccountId !== null
            && $fromAccountId === $toAccountId
        ) {
            $errors['to_account_id'] = 'to_account_id must differ from from_account_id.';
        }

        $amount = $this->extractAmount($data, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var string $fromAccountId */
        /** @var string $toAccountId */
        /** @var string $amount */
        return new TransferRequest($fromAccountId, $toAccountId, $amount);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $errors
     */
    private function extractUuid(array $data, string $field, array &$errors): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            $errors[$field] = sprintf('%s must not be blank.', $field);
            return null;
        }

        $value = strtolower(trim((string) $data[$field]));

        if (!preg_match(self::UUID_PATTERN, $value)) {
            $errors[$field] = sprintf('%s must be a valid UUID (e.g. 550e8400-e29b-41d4-a716-446655440000).', $field);
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $errors
     */
    private function extractAmount(array $data, array &$errors): ?string
    {
        if (!array_key_exists('amount', $data) || $data['amount'] === null || $data['amount'] === '') {
            $errors['amount'] = 'Amount must not be blank.';
            return null;
        }

        $amount = (string) $data['amount'];

        if (!preg_match(self::AMOUNT_PATTERN, $amount)) {
            $errors['amount'] = 'Amount must be a valid decimal with up to 2 decimal places (e.g. "100.00").';
            return null;
        }

        if (bccomp($amount, '0.00', 2) <= 0) {
            $errors['amount'] = 'Amount must be greater than 0.';
            return null;
        }

        return $amount;
    }
}
