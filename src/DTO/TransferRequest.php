<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Validated, type-safe transfer request payload for {@see \App\Controller\Api\TransferController::create}.
 *
 * Populated only after {@see \App\Validator\TransferRequestValidator::validate} succeeds.
 *
 * @property-read string $fromAccountId Sender account UUID (`accounts.id`).
 * @property-read string $toAccountId   Receiver account UUID (`accounts.id`).
 * @property-read string $amount        Positive decimal string (e.g. `"100.00"`), scale 2.
 */
final class TransferRequest
{
    public function __construct(
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly string $amount,
    ) {}
}
