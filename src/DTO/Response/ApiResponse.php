<?php

declare(strict_types=1);

namespace App\DTO\Response;

/**
 * Unified API response envelope used for every endpoint in the application.
 *
 * Success shape:
 * {
 *   "success": true,
 *   "data": {
 *     "type":       "transfer",
 *     "attributes": { ... }
 *   }
 * }
 *
 * Error shape:
 * {
 *   "success": false,
 *   "message": "Human-readable reason.",
 *   "data":    null,
 *   "errors":  { "field": "msg" } | null
 * }
 */
final class ApiResponse implements \JsonSerializable
{
    /**
     * @param bool                                       $success Whether the operation succeeded.
     * @param \JsonSerializable|array<string,mixed>|null $data    Resource object (type + attributes) on success.
     * @param string|null                                $message Human-readable error reason; null on success.
     * @param array<string,string>|null                  $errors  Field-level validation map; null when not applicable.
     */
    public function __construct(
        public readonly bool $success,
        public readonly \JsonSerializable|array|null $data = null,
        public readonly ?string $message = null,
        public readonly ?array $errors = null,
    ) {}

    /**
     * Factory for successful responses.
     *
     * @param \JsonSerializable|array<string,mixed>|null $data Serializable resource object to embed under "data".
     */
    public static function success(\JsonSerializable|array|null $data = null): self
    {
        return new self(success: true, data: $data);
    }

    /**
     * Factory for error responses.
     *
     * @param string                    $message Human-readable error summary.
     * @param array<string,string>|null $errors  Optional field-level validation error map.
     */
    public static function error(string $message, ?array $errors = null): self
    {
        return new self(success: false, data: null, message: $message, errors: $errors);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        if ($this->success) {
            $payload = ['success' => true];

            if ($this->data !== null) {
                $payload['data'] = $this->data;
            }

            return $payload;
        }

        $payload = [
            'success' => false,
            'message' => $this->message,
        ];

        if ($this->errors !== null) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }
}
