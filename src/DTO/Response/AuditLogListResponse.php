<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\AuditLog;

/**
 * Serializable resource object for a paginated list of audit log entries.
 *
 * Returned inside ApiResponse::data by GET /api/v1/transfer/audit-logs.
 *
 * Shape:
 * {
 *   "type": "audit-log-list",
 *   "attributes": {
 *     "count": 5,
 *     "items": [ { ... }, ... ]
 *   }
 * }
 */
final class AuditLogListResponse implements \JsonSerializable
{
    public const TYPE = 'audit-log-list';

    /**
     * @param int                    $count Total number of items in this response.
     * @param AuditLogItemResponse[] $items The individual audit log entries.
     */
    public function __construct(
        public readonly int $count,
        public readonly array $items,
    ) {}

    /**
     * Factory: build from an array of AuditLog entities.
     *
     * @param AuditLog[] $logs
     */
    public static function fromEntities(array $logs): self
    {
        $items = array_map(
            static fn (AuditLog $log): AuditLogItemResponse => AuditLogItemResponse::fromEntity($log),
            $logs,
        );

        return new self(count: count($items), items: $items);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type'       => self::TYPE,
            'attributes' => [
                'count' => $this->count,
                'items' => array_map(
                    static fn (AuditLogItemResponse $item): array => $item->jsonSerialize(),
                    $this->items,
                ),
            ],
        ];
    }
}
