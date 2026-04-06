<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\AuditLog;

/**
 * Serializable representation of a single audit log entry.
 *
 * Used as the element type inside AuditLogListResponse.
 */
final class AuditLogItemResponse implements \JsonSerializable
{
    /**
     * @param string                   $id            UUID primary key of the audit row.
     * @param string                   $event         Event type (e.g. "TRANSFER_SUCCESS").
     * @param string|null              $transactionId UUID of the related Transaction, or null.
     * @param string|null              $fromAccountId Sender account UUID, or null.
     * @param string|null              $toAccountId   Receiver account UUID, or null.
     * @param string|null              $amount        Decimal amount involved, or null.
     * @param array<string,mixed>|null $context       Arbitrary event metadata, or null.
     * @param string                   $createdAt     ISO 8601 timestamp (ATOM format).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly ?string $transactionId,
        public readonly ?string $fromAccountId,
        public readonly ?string $toAccountId,
        public readonly ?string $amount,
        public readonly ?array $context,
        public readonly string $createdAt,
    ) {}

    /** Factory: build from an AuditLog entity. */
    public static function fromEntity(AuditLog $log): self
    {
        return new self(
            id:            $log->getId(),
            event:         $log->getEvent(),
            transactionId: $log->getTransactionId(),
            fromAccountId: $log->getFromAccountId(),
            toAccountId:   $log->getToAccountId(),
            amount:        $log->getAmount(),
            context:       $log->getContext(),
            createdAt:     $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'event'         => $this->event,
            'transactionId' => $this->transactionId,
            'fromAccountId' => $this->fromAccountId,
            'toAccountId'   => $this->toAccountId,
            'amount'        => $this->amount,
            'context'       => $this->context,
            'createdAt'     => $this->createdAt,
        ];
    }
}
