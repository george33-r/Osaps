<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Minimal JSON-schema-style validator for model output. Model output is untrusted:
 * every AI response is validated against one of these schemas before the app uses it.
 *
 * Supported: type (object|array|string|integer|number|boolean), nullable, enum, required,
 * properties, additional (false = reject unknown keys), items, maxItems, maxLength, min, max.
 */
final class SchemaValidator
{
    /** @return list<string> errors; empty means valid */
    public static function errors(mixed $value, array $schema, string $path = '$'): array
    {
        if ($value === null) {
            return ($schema['nullable'] ?? false) ? [] : ["{$path}: must not be null"];
        }

        $type = $schema['type'] ?? null;
        $errors = match ($type) {
            'object' => self::object($value, $schema, $path),
            'array' => self::array($value, $schema, $path),
            'string' => is_string($value) ? self::string($value, $schema, $path) : ["{$path}: expected string"],
            'integer' => is_int($value) ? self::range($value, $schema, $path) : ["{$path}: expected integer"],
            'number' => (is_int($value) || is_float($value)) ? self::range($value, $schema, $path) : ["{$path}: expected number"],
            'boolean' => is_bool($value) ? [] : ["{$path}: expected boolean"],
            default => ["{$path}: unknown schema type"],
        };

        if ($errors === [] && isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: value not allowed";
        }

        return $errors;
    }

    public static function valid(mixed $value, array $schema): bool
    {
        return self::errors($value, $schema) === [];
    }

    /** @return list<string> */
    private static function object(mixed $value, array $schema, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return ["{$path}: expected object"];
        }
        $errors = [];
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path}.{$key}: required";
            }
        }
        $props = $schema['properties'] ?? [];
        foreach ($value as $key => $item) {
            if (isset($props[$key])) {
                $errors = array_merge($errors, self::errors($item, $props[$key], "{$path}.{$key}"));
            } elseif (($schema['additional'] ?? true) === false) {
                $errors[] = "{$path}.{$key}: not allowed";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function array(mixed $value, array $schema, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ["{$path}: expected array"];
        }
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            return ["{$path}: too many items"];
        }
        $errors = [];
        foreach ($value as $i => $item) {
            if (isset($schema['items'])) {
                $errors = array_merge($errors, self::errors($item, $schema['items'], "{$path}[{$i}]"));
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function string(string $value, array $schema, string $path): array
    {
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            return ["{$path}: too long"];
        }

        return [];
    }

    /** @return list<string> */
    private static function range(int|float $value, array $schema, string $path): array
    {
        if (isset($schema['min']) && $value < $schema['min']) {
            return ["{$path}: below minimum"];
        }
        if (isset($schema['max']) && $value > $schema['max']) {
            return ["{$path}: above maximum"];
        }

        return [];
    }
}
