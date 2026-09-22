<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.26 — Helper accesso a app_settings (schema reale).
 * Colonne: setting_key / setting_value / description.
 */
namespace App;

use PDO;

final class CareersSettings
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $s = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public function getInt(string $key, int $default): int
    {
        $v = $this->get($key);
        return $v === null || !is_numeric($v) ? $default : (int)$v;
    }

    public function getCsv(string $key, array $default = []): array
    {
        $v = $this->get($key);
        if ($v === null || $v === '') return $default;
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }
}
