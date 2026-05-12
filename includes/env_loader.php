<?php
/**
 * Lightweight .env file loader.
 * Reads key=value pairs from a .env file and populates $_ENV, $_SERVER, and putenv().
 * Lines starting with # are treated as comments and skipped.
 */
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        $parts = explode('=', $line, 2);
        if (count($parts) < 2) {
            continue;
        }

        [$name, $value] = array_map('trim', $parts);

        // Strip optional surrounding quotes
        if (strlen($value) > 1 && $value[0] === '"' && $value[-1] === '"') {
            $value = substr($value, 1, -1);
        } elseif (strlen($value) > 1 && $value[0] === "'" && $value[-1] === "'") {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}
