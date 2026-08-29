<?php

namespace Local\Energy;

/** Reads KEY=value pairs from .env — no framework, no dependencies. */
final class Config
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(string $file)
    {
        if (! is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $this->values[trim($key)] = trim(trim($value), "\"'");
        }
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }
}
