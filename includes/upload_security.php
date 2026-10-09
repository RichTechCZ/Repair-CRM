<?php

/**
 * Central policy for order attachments.
 *
 * Extensions are derived from server-side MIME detection. The original client
 * name is retained only as display metadata and never influences the stored
 * path.
 */
function crmOrderUploadPolicy(): array
{
    return [
        'image/jpeg' => ['extension' => 'jpg', 'max_bytes' => 25 * 1024 * 1024],
        'image/png' => ['extension' => 'png', 'max_bytes' => 25 * 1024 * 1024],
        'image/gif' => ['extension' => 'gif', 'max_bytes' => 25 * 1024 * 1024],
        'image/webp' => ['extension' => 'webp', 'max_bytes' => 25 * 1024 * 1024],
        'video/mp4' => ['extension' => 'mp4', 'max_bytes' => 250 * 1024 * 1024],
        'video/quicktime' => ['extension' => 'mov', 'max_bytes' => 250 * 1024 * 1024],
        'video/x-msvideo' => ['extension' => 'avi', 'max_bytes' => 250 * 1024 * 1024],
    ];
}

function crmEnsureUploadDirectory(string $uploadDirectory): void
{
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the upload directory.');
    }

    $protectionFile = rtrim($uploadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
    // Attachments are private customer data: the directory is never served directly.
    // Files are delivered by api/media.php after the order authorization check.
    $protectionRules =
        "# CRM-PRIVATE-UPLOADS v2 (served only through api/media.php)\n" .
        "Options -Indexes\n" .
        "<IfModule mod_authz_core.c>\n" .
        "    Require all denied\n" .
        "</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n" .
        "    Order deny,allow\n" .
        "    Deny from all\n" .
        "</IfModule>\n" .
        "RemoveHandler .php .phtml .php3 .php4 .php5 .phar .cgi .pl .py .sh .shtml\n" .
        "RemoveType .php .phtml .php3 .php4 .php5 .phar .cgi .pl .py .sh .shtml\n";

    $current = is_file($protectionFile) ? (string)file_get_contents($protectionFile) : '';
    if (strpos($current, 'CRM-PRIVATE-UPLOADS v2') === false) {
        $written = file_put_contents($protectionFile, $protectionRules, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to protect the upload directory.');
        }
    }
}

/**
 * Browser URL of an order attachment (authorized delivery, never the raw uploads/ path).
 */
function crmOrderAttachmentUrl(int $attachmentId): string
{
    return 'api/media.php?id=' . $attachmentId;
}

/**
 * Absolute path of a stored "uploads/<name>" value, or null when it escapes uploads/ or is missing.
 */
function crmResolveStoredUploadPath(string $storedPath): ?string
{
    $uploadsRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false) {
        return null;
    }
    $relative = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $storedPath), DIRECTORY_SEPARATOR);
    $real = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative);
    if ($real === false || !is_file($real) || !str_starts_with($real, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $real;
}

function crmNormalizeUploadedFiles(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
    }

    $normalized = [];
    foreach (array_keys($names) as $index) {
        $normalized[] = [
            'name' => (string)($files['name'][$index] ?? ''),
            'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
            'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'][$index] ?? 0),
        ];
    }

    return $normalized;
}

/**
 * Stores valid uploaded files and inserts their attachment rows.
 *
 * Invalid client files are rejected individually. Storage/database failures
 * are fail-closed and all files created by this call are removed.
 */
function crmStoreOrderUploads(PDO $pdo, int $orderId, array $files): array
{
    $uploads = crmNormalizeUploadedFiles($files);
    if (count($uploads) > 10) {
        throw new InvalidArgumentException('A maximum of 10 files can be uploaded at once.');
    }

    $policy = crmOrderUploadPolicy();
    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    crmEnsureUploadDirectory($uploadDirectory);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $storedPaths = [];
    $storedCount = 0;
    $rejectedCount = 0;

    try {
        foreach ($uploads as $upload) {
            if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                $rejectedCount++;
                continue;
            }
            if (
                $upload['tmp_name'] === '' ||
                !is_uploaded_file($upload['tmp_name']) ||
                $upload['size'] <= 0
            ) {
                $rejectedCount++;
                continue;
            }

            $mimeType = (string)$finfo->file($upload['tmp_name']);
            $mimePolicy = $policy[$mimeType] ?? null;
            if ($mimePolicy === null || $upload['size'] > $mimePolicy['max_bytes']) {
                $rejectedCount++;
                continue;
            }
            if (str_starts_with($mimeType, 'image/') && @getimagesize($upload['tmp_name']) === false) {
                $rejectedCount++;
                continue;
            }

            $storedName = bin2hex(random_bytes(24)) . '.' . $mimePolicy['extension'];
            $absolutePath = $uploadDirectory . $storedName;
            if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
                throw new RuntimeException('Unable to store an uploaded file.');
            }
            @chmod($absolutePath, 0644);
            $storedPaths[] = $absolutePath;

            $originalName = basename(str_replace("\0", '', $upload['name']));
            $stmt = $pdo->prepare(
                'INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$orderId, 'uploads/' . $storedName, $mimeType, mb_substr($originalName, 0, 255)]);
            $storedCount++;
        }
    } catch (Throwable $e) {
        crmRemoveStoredUploadPaths($storedPaths);
        throw $e;
    }

    return ['stored' => $storedCount, 'rejected' => $rejectedCount, 'paths' => $storedPaths];
}

function crmRemoveStoredUploadPaths(array $storedPaths): void
{
    $uploadsRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false) {
        return;
    }
    $uploadsPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    foreach ($storedPaths as $storedPath) {
        $realPath = realpath((string)$storedPath);
        if ($realPath !== false && str_starts_with($realPath, $uploadsPrefix) && is_file($realPath)) {
            @unlink($realPath);
        }
    }
}
