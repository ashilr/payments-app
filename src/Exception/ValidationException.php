<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when incoming request data fails validation.
 * Carries a map of field → first error message so the subscriber
 * can render a structured JSON 400 response.
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors  field name → human-readable message
     */
    public function __construct(
        private readonly array $errors,
    ) {
        parent::__construct('Validation failed.');
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
