<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Validazione CV upload sicura.
 * - Whitelist MIME reale via finfo (no fiducia sul client)
 * - Estensione coerente
 * - Dimensione massima
 * - Nome file sanificato + storage con nome random (no path traversal)
 * - Deduplica via sha256
 */
namespace App;

use RuntimeException;

final class CvUploadValidator
{
    /** @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
    public function __construct(
        private readonly array $file,
        private readonly int   $maxBytes,
        /** @var string[] */
        private readonly array $allowedMime,
        private readonly string $storageDir
    ) {}

    public function validateAndStore(int $candidateId): array
    {
        if (($this->file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('upload_error_' . (int)$this->file['error'], 400);
        }
        $tmp = $this->file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('not_uploaded_file', 400);
        }
        $size = (int)($this->file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new RuntimeException('cv_too_large', 413);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = (string)$finfo->file($tmp);
        if (!in_array($realMime, $this->allowedMime, true)) {
            throw new RuntimeException('cv_bad_mime:' . $realMime, 415);
        }

        $extAllowed = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $ext = $extAllowed[$realMime] ?? null;
        if ($ext === null) throw new RuntimeException('cv_bad_ext', 415);

        $origName = self::sanitizeFilename((string)$this->file['name']);
        $sha256 = hash_file('sha256', $tmp);
        if ($sha256 === false) throw new RuntimeException('hash_failed', 500);

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('storage_unwritable', 500);
        }

        // Sottodir per candidato
        $subDir = $this->storageDir . '/' . $candidateId;
        if (!is_dir($subDir) && !mkdir($subDir, 0750, true) && !is_dir($subDir)) {
            throw new RuntimeException('storage_subdir_unwritable', 500);
        }

        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $subDir . '/' . $storedName;

        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('move_failed', 500);
        }
        @chmod($dest, 0640);

        return [
            'original_name' => $origName,
            'stored_name'   => (string)$candidateId . '/' . $storedName,
            'mime_type'     => $realMime,
            'size_bytes'    => $size,
            'sha256'        => $sha256,
        ];
    }

    public static function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'cv';
        $name = ltrim($name, '.');
        return substr($name, 0, 200) ?: 'cv';
    }
}
