<?php
// Risoluzione azienda del gruppo esecutrice dal prefisso del codice commessa
final class PrefixResolver
{
    private PDO $pdo;
    private array $map = []; // PREFIX(upper) => company_id
    public function __construct(PDO $pdo) { $this->pdo = $pdo;
        foreach ($pdo->query("SELECT prefix, company_id FROM cm_company_prefix_map")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $this->map[strtoupper($r['prefix'])] = (int)$r['company_id'];
    }
    public static function extractPrefix(string $projectCode): string {
        // 'NIS_3764' / 'WTS-3139' -> 'NIS' / 'WTS'
        return strtoupper(preg_split('/[^A-Za-z]/', trim($projectCode))[0] ?? '');
    }
    public function companyId(string $projectCode): ?int {
        return $this->map[self::extractPrefix($projectCode)] ?? null;
    }
}
