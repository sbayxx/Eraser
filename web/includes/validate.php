<?php
/**
 * GovernIT Data Eraser - input validation helpers.
 */
declare(strict_types=1);

/** Require a non-empty string field from $src. */
function req(string $field, array $src): string
{
    $v = $src[$field] ?? null;
    if (!is_string($v) || trim($v) === '') {
        json_error('missing_field: ' . $field, 422);
    }
    return trim($v);
}

/** Optional string field, trimmed to null when blank. */
function opt_str(string $field, array $src, ?int $max = null): ?string
{
    $v = $src[$field] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return null;
    }
    if (!is_string($v)) {
        json_error("invalid_field: {$field} (expected string)", 422);
    }
    $v = trim($v);
    if ($max !== null && mb_strlen($v) > $max) {
        json_error("invalid_field: {$field} (max $max chars)", 422);
    }
    return $v;
}

/** Optional boolean (accepts bool or 0/1). */
function opt_bool(string $field, array $src, bool $default = false): bool
{
    $v = $src[$field] ?? null;
    if ($v === null) {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v)) {
        return $v !== 0;
    }
    if (is_string($v)) {
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
    json_error("invalid_field: {$field} (expected boolean)", 422);
}

/** Optional email; null when blank, error when invalid. */
function opt_email(string $field, array $src): ?string
{
    $v = opt_str($field, $src);
    if ($v === null) {
        return null;
    }
    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
        json_error("invalid_field: {$field} (invalid email)", 422);
    }
    return strtolower($v);
}

/** Optional UUID reference; null when blank. */
function opt_uuid(string $field, array $src): ?string
{
    $v = opt_str($field, $src);
    if ($v === null) {
        return null;
    }
    return require_uuid($v);
}

/** Optional list of strings; null when absent, error when not an array of strings. */
function opt_string_list(string $field, array $src): ?array
{
    $v = $src[$field] ?? null;
    if ($v === null) {
        return null;
    }
    if (!is_array($v)) {
        json_error("invalid_field: {$field} (expected array)", 422);
    }
    $out = [];
    foreach ($v as $item) {
        if (!is_string($item)) {
            json_error("invalid_field: {$field} (expected array of strings)", 422);
        }
        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * Validate a value against one of $allowed. Returns the (lowercased) value.
 */
function opt_enum(string $field, array $src, array $allowed): ?string
{
    $v = opt_str($field, $src);
    if ($v === null) {
        return null;
    }
    $v = strtolower($v);
    if (!in_array($v, $allowed, true)) {
        json_error("invalid_field: {$field} (allowed: " . implode(',', $allowed) . ')', 422);
    }
    return $v;
}
