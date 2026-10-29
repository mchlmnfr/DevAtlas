<?php
/**
 * Filename: settings_helper.php
 * Purpose: Provide reusable Settings request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

function ParameterText(array $data, string $key, int $maxLength): ?string
{
    $value = $data[$key] ?? null;
    if ($value !== null && !is_string($value))
    {
        JsonError('Enter a valid value for ' . str_replace('_', ' ', $key) . '.');
    }
    $value = $value !== null ? trim($value) : null;
    if ($value !== null && mb_strlen($value) > $maxLength)
    {
        JsonError(str_replace('_', ' ', ucfirst($key)) . ' is too long.');
    }
    return $value === '' ? null : $value;
}

function ParameterInteger(array $data, string $key, int $min, int $max = 2147483647): int
{
    $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < $min || $value > $max)
    {
        JsonError('Enter a valid ' . str_replace('_', ' ', $key) . ' value.');
    }
    return $value;
}
