<?php
namespace App\Core;

class Env {
    private static array $variables = [];

    public static function load(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip quotes
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                // Handle boolean conversion
                if (strtolower($value) === 'true') $value = true;
                elseif (strtolower($value) === 'false') $value = false;
                elseif (strtolower($value) === 'null') $value = null;

                self::$variables[$key] = $value;
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed {
        return self::$variables[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}
