<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Transaction;

/**
 * Serializable resource object for a successful fund transfer.
 *
 * Returned inside ApiResponse::data by POST /api/v1/transfer.
 *
 * Shape:
 * {
 *   "type": "transfer",
 *   "attributes": {
 *     "transactionId":  "...",
 *     "status":         "SUCCESS",
 *     "idempotencyKey": "..." | null
 *   }
 * }
 */
final class TransferResponse implements \JsonSerializable
{
    public const TYPE = 'transfer';

    /**
     * @param string      $transactionId  UUID v7 of the committed Transaction.
     * @param string      $status         Enum value — always "SUCCESS" on 2xx responses.
     * @param string|null $idempotencyKey The client-supplied deduplication key, if any.
     */
    public function __construct(
        public readonly string $transactionId,
        public readonly string $status,
        public readonly ?string $idempotencyKey,
    ) {}

    /** Factory: build from a persisted Transaction entity. */
    public static function fromTransaction(Transaction $transaction): self
    {
        return new self(
            transactionId:  $transaction->getId(),
            status:         $transaction->getStatus()->value,
            idempotencyKey: $transaction->getIdempotencyKey(),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type'       => self::TYPE,
            'attributes' => [
                'transactionId'  => $this->transactionId,
                'status'         => $this->status,
                'idempotencyKey' => $this->idempotencyKey,
            ],
        ];
    }
}
