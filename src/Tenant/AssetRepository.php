<?php
declare(strict_types=1);

namespace App\Tenant;

use RuntimeException;

/**
 * Tenant-scoped image library backed by the filesystem.
 *
 * Files live under
 *   public/assets/tenants/<slug>/<filename>
 * and are served directly by Apache, so the portal never touches them at
 * delivery time — Twig templates just embed the URL.
 *
 * No DB metadata table: directory listing IS the source of truth. Tenant
 * deletion removes the directory recursively (called from TenantController).
 *
 * Validations are deliberately strict because the directory is publicly
 * reachable and reused across requests:
 *   - filename: lowercase alnum / dash / underscore + extension; everything
 *     else is stripped or rejected.
 *   - extension: png/jpg/jpeg/gif/webp only — SVG excluded because it can
 *     contain JavaScript and we don't sanitize it.
 *   - size limit: 5 MB.
 *   - MIME sniffing: finfo on the uploaded file checked against the
 *     expected type; mismatched payloads are rejected.
 */
final class AssetRepository
{
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /** @var array<string,string>  ext → expected MIME */
    private const ALLOWED_EXTS = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(private readonly string $publicAssetsRoot) {}

    /**
     * @return list<array{filename:string,size:int,mtime:int,url:string,content_type:string}>
     */
    public function list(string $tenantSlug, string $baseUrl): array
    {
        $dir = $this->tenantDir($tenantSlug);
        if (!is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $items[] = [
                'filename'     => $entry,
                'size'         => (int) filesize($path),
                'mtime'        => (int) filemtime($path),
                'url'          => rtrim($baseUrl, '/') . '/assets/tenants/' . rawurlencode($tenantSlug) . '/' . rawurlencode($entry),
                'content_type' => $this->guessContentType($entry),
            ];
        }
        usort($items, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /**
     * Saves an uploaded file. Returns the final stored filename.
     *
     * @param array{tmp_name:string,name:string,size:int,type:string,error:int} $upload
     */
    public function store(string $tenantSlug, array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->describeUploadError((int) $upload['error']));
        }
        if ((int) $upload['size'] <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        if ((int) $upload['size'] > self::MAX_SIZE_BYTES) {
            throw new RuntimeException(sprintf(
                'File is %s. Maximum is %s.',
                $this->formatSize((int) $upload['size']),
                $this->formatSize(self::MAX_SIZE_BYTES),
            ));
        }

        $ext = $this->safeExtension((string) $upload['name']);
        if ($ext === null) {
            throw new RuntimeException(
                'File type is not allowed. Use one of: ' . implode(', ', array_keys(self::ALLOWED_EXTS)) . '.'
            );
        }

        $expectedMime = self::ALLOWED_EXTS[$ext];
        $actualMime   = $this->detectMime((string) $upload['tmp_name']);
        // jpg ↔ jpeg are interchangeable on the MIME side.
        if ($actualMime !== $expectedMime) {
            throw new RuntimeException(sprintf(
                'File contents (%s) do not match the .%s extension (expected %s).',
                $actualMime ?: 'unknown',
                $ext,
                $expectedMime,
            ));
        }

        $filename = $this->safeFilename((string) $upload['name'], $ext);
        $dir      = $this->tenantDir($tenantSlug);
        $this->ensureDir($dir);

        // Avoid clobbering an existing file with a different content; suffix instead.
        $finalName = $filename;
        $counter   = 1;
        while (is_file($dir . '/' . $finalName)) {
            $base = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $filename) ?? $filename;
            $finalName = $base . '-' . $counter . '.' . $ext;
            $counter++;
            if ($counter > 999) {
                throw new RuntimeException('Too many files with this name; please rename and try again.');
            }
        }

        $target = $dir . '/' . $finalName;
        if (!@move_uploaded_file((string) $upload['tmp_name'], $target)) {
            // Fall back for non-SAPI contexts (mostly tests)
            if (!@rename((string) $upload['tmp_name'], $target)) {
                throw new RuntimeException('Could not save the uploaded file. Check permissions on public/assets/tenants/.');
            }
        }
        @chmod($target, 0644);
        return $finalName;
    }

    public function delete(string $tenantSlug, string $filename): void
    {
        $safe = $this->safeFilenameForDelete($filename);
        if ($safe === null) {
            throw new RuntimeException('Invalid filename.');
        }
        $path = $this->tenantDir($tenantSlug) . '/' . $safe;
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Could not delete file.');
        }
    }

    /** Recursively removes the tenant's asset directory. Called from tenant deletion. */
    public function dropTenant(string $tenantSlug): void
    {
        $dir = $this->tenantDir($tenantSlug);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            @unlink($dir . '/' . $entry);
        }
        @rmdir($dir);
    }

    // ---------- helpers ----------

    private function tenantDir(string $tenantSlug): string
    {
        // Slug is already validated lowercase/alnum/dash on tenant create —
        // safe to embed in the path.
        return rtrim($this->publicAssetsRoot, '/') . '/tenants/' . $tenantSlug;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create asset directory: {$dir}");
        }
    }

    private function safeExtension(string $filename): ?string
    {
        $ext = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return isset(self::ALLOWED_EXTS[$ext]) ? $ext : null;
    }

    /** Build a safe storage filename from the user-supplied original. */
    private function safeFilename(string $original, string $ext): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = mb_strtolower($base);
        // Replace anything that isn't alnum, dash, underscore with dash.
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');
        if ($base === '') {
            $base = 'image';
        }
        // Cap basename length so the file always fits filesystem limits.
        $base = mb_substr($base, 0, 80);
        return $base . '.' . $ext;
    }

    /** Validate a filename used for delete: must be a single safe-looking entry. */
    private function safeFilenameForDelete(string $filename): ?string
    {
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, "\0")) {
            return null;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9_\-]*[a-z0-9])?\.(png|jpg|jpeg|gif|webp)$/i', $filename) !== 1) {
            return null;
        }
        return $filename;
    }

    private function detectMime(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }
        $info = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $info->file($path);
        return $mime === false ? null : $mime;
    }

    private function guessContentType(string $filename): string
    {
        $ext = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::ALLOWED_EXTS[$ext] ?? 'application/octet-stream';
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the upload size limit.',
            UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted; please retry.',
            UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server temp dir is missing.',
            UPLOAD_ERR_CANT_WRITE                     => 'Server could not write the file.',
            UPLOAD_ERR_EXTENSION                      => 'A PHP extension blocked the upload.',
            default                                   => 'Upload failed.',
        };
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, '.', '') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, '.', '') . ' KB';
        }
        return $bytes . ' B';
    }
}
