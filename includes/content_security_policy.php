<?php

/**
 * Creates a per-response nonce and emits a strict, header-delivered Content
 * Security Policy before trusted templates render.
 *
 * Trusted templates add this nonce explicitly. Inline event attributes are
 * prohibited and UI actions are delegated from assets/js/main.js.
 */
function crmStartContentSecurityPolicy(): void
{
    if (PHP_SAPI === 'cli' || defined('CRM_CSP_STARTED')) {
        return;
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basename = basename($scriptName);
    if (
        str_contains($scriptName, '/api/') ||
        in_array($basename, ['tg_webhook.php', 'sync_from_site.php', 'run_migrations.php'], true)
    ) {
        return;
    }

    try {
        $nonce = base64_encode(random_bytes(24));
    } catch (Throwable $e) {
        error_log('CSP nonce generation failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Internal Server Error');
    }

    define('CRM_CSP_STARTED', true);
    $GLOBALS['crm_csp_nonce'] = $nonce;

    $policy = implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'self'",
        "form-action 'self'",
        "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic' https://code.jquery.com https://cdn.jsdelivr.net",
        "script-src-attr 'none'",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
        "font-src 'self' data: https://cdnjs.cloudflare.com",
        "img-src 'self' data: blob: https://flagcdn.com",
        "media-src 'self' blob:",
        "connect-src 'self' https://ares.gov.cz",
        "frame-src 'self'",
        "worker-src 'self' blob:",
        "manifest-src 'self'",
    ]);

    if (!headers_sent()) {
        header('Content-Security-Policy: ' . $policy);
    }
}

function crmCspNonce(): string
{
    return (string)($GLOBALS['crm_csp_nonce'] ?? '');
}
