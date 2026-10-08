<?php

/**
 * Validate Telegram webhook configuration without bootstrapping the database.
 */
function isValidTelegramWebhookSecret(string $expectedSecret, string $providedSecret): bool {
    return $expectedSecret !== ''
        && $providedSecret !== ''
        && hash_equals($expectedSecret, $providedSecret);
}

/**
 * Telegram accepts 1–256 URL-safe characters for secret_token.
 */
function isValidTelegramWebhookSecretFormat(string $secret): bool {
    return preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $secret) === 1;
}

/**
 * Keep the configured webhook on a supported HTTPS endpoint and avoid credentials in URLs.
 */
function isValidTelegramWebhookUrl(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        return false;
    }

    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return false;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    return in_array($port, [80, 443, 88, 8443], true);
}
