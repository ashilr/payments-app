<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Utility for masking sensitive string values before they are stored in audit logs.
 *
 * Masking preserves enough characters for human identification while obscuring
 * the bulk of the value to reduce the impact of a data breach.
 *
 * Example:  1234567890  →  12XXXXXX90
 * Example:  ACCAB12CD34 →  AC******34
 * Example:  250.00      →  25XX.00   (amount-aware: decimal part preserved)
 */
final class MaskingUtil
{
    /**
     * Masks the middle characters of a string, preserving the first two and last two.
     *
     * Rules:
     *   - Strings shorter than 5 characters are masked entirely with asterisks.
     *   - Strings of 5 characters or more have their inner characters replaced
     *     with 'X' characters, keeping the first 2 and last 2 visible.
     *
     * @param string $value The raw sensitive value to mask.
     * @return string The masked value of the same length.
     */
    public static function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length < 5) {
            return str_repeat('*', $length);
        }

        $visible = 2;
        $prefix  = mb_substr($value, 0, $visible);
        $suffix  = mb_substr($value, -$visible);
        $masked  = str_repeat('X', $length - ($visible * 2));

        return $prefix . $masked . $suffix;
    }

    /**
     * Masks a decimal amount string, preserving the fractional part for auditability.
     *
     * The integer part is masked, the decimal separator and decimal digits are kept.
     *
     * Example: "1250.75" → "12XX.75"
     * Example: "50.00"   → "50.00"   (≤ 4 integer digits left intact for low amounts)
     *
     * @param string $amount Decimal amount string (e.g. "1250.75").
     * @return string Masked amount string.
     */
    public static function maskAmount(string $amount): string
    {
        if (!str_contains($amount, '.')) {
            return self::mask($amount);
        }

        [$integer, $decimal] = explode('.', $amount, 2);

        return self::mask($integer) . '.' . $decimal;
    }

    /**
     * Applies masking to a specific key within a metadata array, returning a new array.
     * Keys not present in $sensitiveKeys are passed through unchanged.
     *
     * @param array<string, mixed>  $metadata      The metadata array to process.
     * @param list<string>          $sensitiveKeys Keys whose values should be masked.
     * @return array<string, mixed> The processed array with sensitive values masked.
     */
    public static function maskMetadata(array $metadata, array $sensitiveKeys = ['amount', 'balance', 'ip']): array
    {
        foreach ($sensitiveKeys as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                $metadata[$key] = ($key === 'amount' || $key === 'balance')
                    ? self::maskAmount($metadata[$key])
                    : self::mask($metadata[$key]);
            }
        }

        return $metadata;
    }
}
