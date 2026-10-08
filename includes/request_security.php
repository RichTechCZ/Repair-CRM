<?php

/**
 * Decide whether the current request is HTTPS without trusting proxy headers by default.
 */
function requestUsesHttps(array $server, bool $trustForwardedProto = false): bool {
    $https = strtolower((string)($server['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1' || ($server['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    if (!$trustForwardedProto) {
        return false;
    }

    $forwardedProto = (string)($server['HTTP_X_FORWARDED_PROTO'] ?? '');
    $firstProto = strtolower(trim(explode(',', $forwardedProto)[0] ?? ''));
    return $firstProto === 'https';
}
