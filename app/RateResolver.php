<?php
// Risoluzione tariffe per fascia/tipologia/regime + calcolo colonne *_calc
final class RateResolver
{
    /** @var AliasStore|null v1.7.67 */
    private $aliasStore = null;

    private PDO $pdo;
    private array $map = []; // band_id => [cost_type => [regime => rate]]
    /** v1.7.89: override per commessa: project_id => professional_id => band_id => [cost_type][regime] */
    private array $projMap = [];
    private bool $projLoaded = false;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->load(); }

    /** v1.7.89: tariffe di fascia specifiche per commessa (e per professionista). */
    private function loadProject(): void
    {
        if ($this->projLoaded) return;
        $this->projLoaded = true;
        try {
            $rows = $this->pdo->query(
                "SELECT project_id, band_id, professional_id, cost_type, regime, rate_hour FROM cm_project_band_rates"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $this->projMap[(int)$r['project_id']][(int)$r['professional_id']][(int)$r['band_id']][$r['cost_type']][$r['regime']] = (float)$r['rate_hour'];
            }
        } catch (Throwable $e) { /* tabella assente */ }
    }

    /**
     * v1.7.89: tariffa con priorità commessa+professionista → commessa → globale.
     * Serve per le fasce legate alla singola commessa (es. "E" con valori diversi per
     * commessa) e per la fascia "X" legata al professionista che esegue l'attività.
     */
    public function rateFor(?int $bandId, string $costType, bool $onCall, ?int $projectId = null, ?int $professionalId = null): float
    {
        if (!$bandId) return 0.0;
        $regime = $onCall ? 'Reperibilità' : 'Ordinario';
        $pick = function (array $byRegime) use ($regime) {
            return isset($byRegime[$regime]) ? (float)$byRegime[$regime]
                 : (isset($byRegime['Ordinario']) ? (float)$byRegime['Ordinario'] : null);
        };
        if ($projectId) {
            $this->loadProject();
            $scopes = [];
            if ($professionalId) $scopes[] = (int)$professionalId; // tariffa dedicata al professionista
            $scopes[] = 0;                                          // tariffa di commessa
            foreach ($scopes as $sc) {
                $byRegime = $this->projMap[(int)$projectId][$sc][$bandId][$costType] ?? null;
                if ($byRegime !== null) { $v = $pick($byRegime); if ($v !== null) return $v; }
            }
        }
        return $this->rate($bandId, $costType, $onCall);
    }

    /** v1.7.89: colonne calcolate tenendo conto delle tariffe di commessa/professionista. */
    public function calcCostsFor(?int $bandId, float $hours, bool $onCall, ?int $projectId = null, ?int $professionalId = null): array
    {
        return [
            'company_cost_calc'    => round($this->rateFor($bandId,'Aziendale',$onCall,$projectId,$professionalId)   * $hours, 2),
            'client_revenue_calc'  => round($this->rateFor($bandId,'Cliente',$onCall,$projectId,$professionalId)     * $hours, 2),
            'commercial_cost_calc' => round($this->rateFor($bandId,'Commerciale',$onCall,$projectId,$professionalId) * $hours, 2),
        ];
    }

    private function load(): void
    {
        $rows = $this->pdo->query(
            "SELECT band_id, cost_type, regime, rate_hour FROM cm_rate_band_rates"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->map[(int)$r['band_id']][$r['cost_type']][$r['regime']] = (float)$r['rate_hour'];
        }
    }

    public function bandIdByName(string $name): ?int
    {
        // v1.7.67: alias di riconciliazione prima dell'euristica.
        if (!isset($this->aliasStore)) {
            require_once __DIR__ . '/AliasStore.php';
            $this->aliasStore = new AliasStore($this->pdo);
        }
        if ($aid = $this->aliasStore->resolve(AliasStore::T_BAND, (string)$name)) return $aid;

        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach ($this->pdo->query("SELECT id, band_name FROM cm_rate_bands")->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $cache[mb_strtolower(trim($b['band_name']))] = (int)$b['id'];
            }
        }
        return $cache[mb_strtolower(trim($name))] ?? null;
    }

    public function rate(?int $bandId, string $costType, bool $onCall): float
    {
        if (!$bandId || !isset($this->map[$bandId][$costType])) return 0.0;
        $regime = $onCall ? 'Reperibilità' : 'Ordinario';
        $byRegime = $this->map[$bandId][$costType];
        // fallback: se manca la tariffa reperibilità usa l'ordinaria
        return (float)($byRegime[$regime] ?? $byRegime['Ordinario'] ?? 0.0);
    }

    // Ritorna le 3 colonne calcolate per una riga di rapporto
    public function calcCosts(?int $bandId, float $hours, bool $onCall): array
    {
        return [
            'company_cost_calc'    => round($this->rate($bandId,'Aziendale',$onCall)  * $hours, 2),
            'client_revenue_calc'  => round($this->rate($bandId,'Cliente',$onCall)    * $hours, 2),
            'commercial_cost_calc' => round($this->rate($bandId,'Commerciale',$onCall)* $hours, 2),
        ];
    }
}
