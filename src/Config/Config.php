<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function loadFromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Config file not found: {$path}");
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException("Config file did not return an array: {$path}");
        }
        return new self($values);
    }

    /** Dot-path lookup, e.g. get('db.host'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
