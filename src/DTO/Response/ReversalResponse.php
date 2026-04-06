<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Transaction;

/**
 * Serializable resource object for a successful reversal.
 *
 * Returned inside ApiResponse::data by POST /api/v1/reversal/{transactionId}.
 *
 * Shape:
 * {
 *   "type": "reversal",
 *   "attributes": {
 *     "reversalTransactionId":  "...",
 *     "originalTransactionId":  "...",
 *     "status":                 "SUCCESS",
 *     "amount":                 "250.00",
 *     "reason":                 "...",
 *     "idempotencyKey":         "..." | null
 *   }
 * }
 */
final class ReversalResponse implements \JsonSerializable
{
    public const TYPE = 'reversal';

    /**
     * @param string      $reversalTransactionId UUID v7 of the new reversal Transaction.
     * @param string|null $originalTransactionId UUID v7 of the original transfer being reversed.
     * @param string      $status                Enum value — always "SUCCESS" on 2xx responses.
     * @param string      $amount                Decimal string amount reversed (e.g. "250.00").
     * @param string|null $reason                Operator-supplied reason for the reversal.
     * @param string|null $idempotencyKey        The client-supplied deduplication key, if any.
     */
    public function __construct(
        public readonly string $reversalTransactionId,
        public readonly ?string $originalTransactionId,
        public readonly string $status,
        public readonly string $amount,
        public readonly ?string $reason,
        public readonly ?string $idempotencyKey,
    ) {}

    /** Factory: build from the committed reversal Transaction entity. */
    public static function fromTransaction(Transaction $reversal): self
    {
        return new self(
            reversalTransactionId: $reversal->getId(),
            originalTransactionId: $reversal->getReferenceTransaction()?->getId(),
            status:                $reversal->getStatus()->value,
            amount:                $reversal->getAmount(),
            reason:                $reversal->getReversalReason(),
            idempotencyKey:        $reversal->getIdempotencyKey(),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type'       => self::TYPE,
            'attributes' => [
                'reversalTransactionId' => $this->reversalTransactionId,
                'originalTransactionId' => $this->originalTransactionId,
                'status'                => $this->status,
                'amount'                => $this->amount,
                'reason'                => $this->reason,
                'idempotencyKey'        => $this->idempotencyKey,
            ],
        ];
    }
}
