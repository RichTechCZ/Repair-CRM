<?php

const CRM_SENSITIVE_VALUE_PREFIX = 'enc:v1:';

function crmSensitiveDataKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $encoded = trim((string)(getenv('CRM_DATA_ENCRYPTION_KEY') ?: ''));
    $decoded = base64_decode($encoded, true);
    if ($decoded === false || strlen($decoded) !== 32) {
        throw new RuntimeException('CRM_DATA_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
    }

    $key = $decoded;
    return $key;
}

function crmSensitiveDataIsEncrypted(?string $value): bool
{
    return str_starts_with((string)$value, CRM_SENSITIVE_VALUE_PREFIX);
}

function crmEncryptSensitiveValue(?string $plaintext): ?string
{
    $plaintext = trim((string)$plaintext);
    if ($plaintext === '') {
        return null;
    }
    if (crmSensitiveDataIsEncrypted($plaintext)) {
        return $plaintext;
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for sensitive-data encryption.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        crmSensitiveDataKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Sensitive-data encryption failed.');
    }

    return CRM_SENSITIVE_VALUE_PREFIX . base64_encode($iv . $tag . $ciphertext);
}

function crmDecryptSensitiveValue(?string $stored): string
{
    $stored = (string)$stored;
    if ($stored === '') {
        return '';
    }

    // Legacy plaintext is readable only until migration 004 encrypts it.
    if (!crmSensitiveDataIsEncrypted($stored)) {
        return $stored;
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required for sensitive-data decryption.');
    }

    $payload = base64_decode(substr($stored, strlen(CRM_SENSITIVE_VALUE_PREFIX)), true);
    if ($payload === false || strlen($payload) < 29) {
        throw new RuntimeException('Sensitive-data payload is invalid.');
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        crmSensitiveDataKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($plaintext === false) {
        throw new RuntimeException('Sensitive-data decryption failed.');
    }

    return $plaintext;
}

function crmDecryptDevicePinInRow(array &$row): void
{
    if (!array_key_exists('pin_code', $row)) {
        return;
    }

    $stored = (string)$row['pin_code'];
    if ($stored === '') {
        $row['pin_code'] = '';
        $row['pin_code_decrypt_failed'] = false;
        return;
    }

    try {
        $row['pin_code'] = crmDecryptSensitiveValue($stored);
        $row['pin_code_decrypt_failed'] = false;
    } catch (Throwable $e) {
        error_log('Device PIN could not be decrypted: ' . $e->getMessage());
        // Keep ciphertext out of the UI, but mark failure so the form does not
        // look like a missing PIN and accidental saves do not wipe the DB value.
        $row['pin_code'] = '';
        $row['pin_code_decrypt_failed'] = true;
        $row['pin_code_encrypted'] = $stored;
    }
}
