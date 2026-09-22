<?php
/**
 * app/CostModel.php — Calcolo costo pieno e valore FTE (v1.8.0, era v1.7.93)
 *
 * v1.8.0 — DATI ECONOMICI PER ANNO DI COMPETENZA.
 *   • I valori di riferimento globali (hr_reference_values) sono per-anno.
 *   • Gli input per-dipendente sono per-anno (hr_employee_economics).
 *   • compute() e refs() accettano l'anno; se assente si usa l'anno corrente
 *     (hr_economic_years.is_current). Fallback all'anno precedente più vicino,
 *     poi ad app_settings, poi ai default di fabbrica: nessuna regressione.
 *
 * Formule (sezione Compensation & Benefit, riservata HR):
 *   FullCost          = RAL × Moltiplicatore FC
 *   TotAAxTA+BP       = (Qt. Buoni Pasto + Qt. Trasferte Annue) × ValoreTABP
 *   Rimborso KM       = Km concordati (annui) × Val.KM
 *   TotalePreOverHead = FullCost + TotAAxTA+BP + Rimborso KM
 *                       + Incentivazione Extra + Valore Medio anno Auto
 *   TotCostoTab       = TotalePreOverHead + TotalePreOverHead × OverHead Aziendale
 *   CostoNoAuto       = FullCost + TotAAxTA+BP + Incentivazione Extra
 *   ValoreFTE         = TotCostoTab × Moltiplicatore FTE
 *   TotaleFTE+CA      = TotCostoTab + ValoreFTE
 */
require_once __DIR__ . '/FormulaEval.php';

final class CostModel
{
    /** v1.7.95: formule di default, usate se la tabella hr_formulas non è presente. */
    private const FORMULAS = [
        'full_cost'           => 'ral * mult_fc',
        'tot_aa_ta_bp'        => '(buoni + trasferte) * valore_tabp',
        'rimborso_km'         => 'km * val_km',
        'totale_pre_overhead' => 'full_cost + tot_aa_ta_bp + rimborso_km + incentivo + auto',
        'tot_costo_tab'       => 'totale_pre_overhead + totale_pre_overhead * overhead',
        'costo_no_auto'       => 'full_cost + tot_aa_ta_bp + incentivo',
        'valore_fte'          => 'costo_no_auto * mult_fte',
        'totale_fte_ca'       => 'tot_costo_tab + valore_fte',
    ];

    /** Colonne di input economico (per-dipendente, per-anno). */
    public const INPUT_COLUMNS = [
        'ral','premio_concordato','km_concordati','km_effettivi','fuori_sede','fuori_sede_amount',
        'classificazione_finanziaria','moltiplicatore_fc','qt_trasferte_annue','qt_buoni_pasto',
        'valore_tabp','val_km','incentivazione_extra','valore_medio_anno_auto','overhead_aziendale','moltiplicatore_fte',
    ];

    /** Variabili di input disponibili nelle formule. */
    public static function inputVars(): array
    {
        return ['ral','mult_fc','trasferte','buoni','valore_tabp','km','val_km','incentivo','auto','overhead','mult_fte'];
    }

    /** Formule correnti (tabella hr_formulas, in ordine; fallback ai default). */
    public function formulas(): array
    {
        if ($this->formulas !== null) return $this->formulas;
        $out = self::FORMULAS;
        try {
            $rows = $this->pdo->query("SELECT formula_key, expression FROM hr_formulas ORDER BY sort_order, id")->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($rows) {
                $ordered = [];
                foreach ($rows as $k => $expr) if (isset($out[$k]) && trim((string)$expr) !== '') $ordered[$k] = (string)$expr;
                foreach ($out as $k => $expr) if (!isset($ordered[$k])) $ordered[$k] = $expr;
                $out = $ordered;
            }
        } catch (Throwable $e) { /* tabella non ancora creata */ }
        return $this->formulas = $out;
    }

    /** Chiave in app_settings => default di fabbrica. */
    private const DEFAULTS = [
        'hr_mult_fc'            => 1.42540,
        'hr_valore_tabp'        => 46.48,
        'hr_val_km'             => 0.5000,
        'hr_overhead_aziendale' => 0.0300,
        'hr_mult_fte'           => 0.1238,
    ];

    private PDO $pdo;
    /** @var array<int,array> refs per anno */
    private array $refsByYear = [];
    private ?array $formulas = null;
    private ?int $currentYear = null;
    private ?array $yearList = null;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /* ── Annualità ─────────────────────────────────────────────────────────── */

    /** Anno corrente (esercizio marcato is_current; fallback max anno; fallback anno solare). */
    public function currentYear(): int
    {
        if ($this->currentYear !== null) return $this->currentYear;
        $y = null;
        try {
            $y = $this->pdo->query("SELECT year FROM hr_economic_years WHERE is_current=1 ORDER BY year DESC LIMIT 1")->fetchColumn();
            if (!$y) $y = $this->pdo->query("SELECT MAX(year) FROM hr_economic_years")->fetchColumn();
        } catch (Throwable $e) {}
        if (!$y) { try { $y = $this->pdo->query("SELECT MAX(year) FROM hr_employee_economics")->fetchColumn(); } catch (Throwable $e) {} }
        return $this->currentYear = (int)($y ?: 2025);
    }

    /** Elenco annualità catalogate: [year => label]. Garantisce l'anno corrente. */
    public function years(): array
    {
        if ($this->yearList !== null) return $this->yearList;
        $out = [];
        try { $out = $this->pdo->query("SELECT year, label FROM hr_economic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
        if (!$out) {
            try { $ys = $this->pdo->query("SELECT DISTINCT year FROM hr_employee_economics ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN); }
            catch (Throwable $e) { $ys = []; }
            foreach ($ys as $y) $out[(int)$y] = 'Esercizio ' . (int)$y;
        }
        $cy = $this->currentYear();
        if (!isset($out[$cy])) { $out[$cy] = 'Esercizio ' . $cy; krsort($out); }
        return $this->yearList = $out;
    }

    /** Valida un anno rispetto al catalogo; se non catalogato ritorna l'anno corrente. */
    public function resolveYear($year): int
    {
        $year = (int)$year;
        if ($year <= 0) return $this->currentYear();
        $y = $this->years();
        return isset($y[$year]) ? $year : $this->currentYear();
    }

    /* ── Riferimenti globali per-anno ──────────────────────────────────────── */

    /**
     * Valori di riferimento globali per l'anno indicato (default: anno corrente).
     * Fallback: anno esatto → anno precedente più vicino → app_settings → default.
     */
    public function refs(?int $year = null): array
    {
        $year = $year ?? $this->currentYear();
        if (isset($this->refsByYear[$year])) return $this->refsByYear[$year];
        $out = self::DEFAULTS;

        // sorgente primaria: tabella per-anno (con caduta all'anno precedente)
        try {
            $st = $this->pdo->prepare(
                "SELECT ref_key, ref_value FROM hr_reference_values
                  WHERE year = (SELECT MAX(year) FROM hr_reference_values WHERE year <= ? )"
            );
            $st->execute([$year]);
            $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) if (isset($out[$k]) && is_numeric($v)) $out[$k] = (float)$v;
            if ($rows) return $this->refsByYear[$year] = $out;
        } catch (Throwable $e) { /* colonna year assente o tabella non creata: compat schema 1.7.94 */
            try {
                $rows = $this->pdo->query("SELECT ref_key, ref_value FROM hr_reference_values")->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($rows as $k => $v) if (isset($out[$k]) && is_numeric($v)) $out[$k] = (float)$v;
                if ($rows) return $this->refsByYear[$year] = $out;
            } catch (Throwable $e2) {}
        }

        // compatibilità: valori in app_settings (schema 1.7.93)
        try {
            $keys = "'" . implode("','", array_keys(self::DEFAULTS)) . "'";
            $rows = $this->pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($keys)")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) {
                $v = str_replace(',', '.', (string)$v);
                if (is_numeric($v)) $out[$k] = (float)$v;
            }
        } catch (Throwable $e) { /* schema precedente */ }
        return $this->refsByYear[$year] = $out;
    }

    /** Definizione dei parametri di riferimento (per la pagina di Amministrazione). */
    public static function refDefinitions(): array
    {
        return [
            'hr_mult_fc'            => ['Moltiplicatore FC', 5],
            'hr_valore_tabp'        => ['ValoreTABP', 2],
            'hr_val_km'             => ['Val.KM', 4],
            'hr_overhead_aziendale' => ['OverHead Aziendale', 4],
            'hr_mult_fte'           => ['Moltiplicatore FTE', 4],
        ];
    }

    /* ── Input economici per-dipendente e per-anno ─────────────────────────── */

    /**
     * Input economici del dipendente per l'anno indicato.
     * @return array|null la riga di hr_employee_economics, o null se assente.
     */
    public function economics(int $employeeId, ?int $year = null): ?array
    {
        $year = $year ?? $this->currentYear();
        try {
            $st = $this->pdo->prepare("SELECT * FROM hr_employee_economics WHERE employee_id=? AND year=? LIMIT 1");
            $st->execute([$employeeId, $year]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }

    private static function num($v): ?float
    {
        if ($v === null || $v === '') return null;
        $v = str_replace(',', '.', (string)$v);
        return is_numeric($v) ? (float)$v : null;
    }

    /**
     * Calcola i valori derivati da una riga di input economici.
     * @param array    $e   riga con le colonne di input (employees o hr_employee_economics)
     * @param int|null $year anno per i valori di riferimento globali (default: corrente)
     * @return array valori usati (con indicazione se dal riferimento globale) e calcolati
     */
    public function compute(array $e, ?int $year = null): array
    {
        $r = $this->refs($year);

        // valori in ingresso: campo del dipendente, altrimenti riferimento globale
        $multFc   = self::num($e['moltiplicatore_fc']      ?? null);
        $valTabp  = self::num($e['valore_tabp']            ?? null);
        $valKm    = self::num($e['val_km']                 ?? null);
        $ovh      = self::num($e['overhead_aziendale']     ?? null);
        $multFte  = self::num($e['moltiplicatore_fte']     ?? null);

        $used = [
            'moltiplicatore_fc'  => ['v' => $multFc  ?? $r['hr_mult_fc'],            'ref' => $multFc  === null],
            'valore_tabp'        => ['v' => $valTabp ?? $r['hr_valore_tabp'],        'ref' => $valTabp === null],
            'val_km'             => ['v' => $valKm   ?? $r['hr_val_km'],             'ref' => $valKm   === null],
            'overhead_aziendale' => ['v' => $ovh     ?? $r['hr_overhead_aziendale'], 'ref' => $ovh     === null],
            'moltiplicatore_fte' => ['v' => $multFte ?? $r['hr_mult_fte'],           'ref' => $multFte === null],
        ];

        $ral       = self::num($e['ral']                    ?? null) ?? 0.0;
        $trasferte = self::num($e['qt_trasferte_annue']     ?? null) ?? 0.0;
        $buoni     = self::num($e['qt_buoni_pasto']         ?? null) ?? 0.0;
        $km        = self::num($e['km_concordati']          ?? null) ?? 0.0;
        $incentivo = self::num($e['incentivazione_extra']   ?? null) ?? 0.0;
        $auto      = self::num($e['valore_medio_anno_auto'] ?? null) ?? 0.0;

        // v1.7.95: i valori derivati sono calcolati applicando le formule configurate
        // (tabella hr_formulas), valutate in ordine con un parser sicuro.
        $vars = [
            'ral'         => $ral,
            'mult_fc'     => $used['moltiplicatore_fc']['v'],
            'trasferte'   => $trasferte,
            'buoni'       => $buoni,
            'valore_tabp' => $used['valore_tabp']['v'],
            'km'          => $km,
            'val_km'      => $used['val_km']['v'],
            'incentivo'   => $incentivo,
            'auto'        => $auto,
            'overhead'    => $used['overhead_aziendale']['v'],
            'mult_fte'    => $used['moltiplicatore_fte']['v'],
        ];
        $calc = []; $errors = [];
        foreach ($this->formulas() as $key => $expr) {
            try {
                $v = FormulaEval::evaluate($expr, array_merge($vars, $calc));
            } catch (Throwable $e) {
                // formula non valida: ricade sulla definizione di default
                $errors[$key] = $e->getMessage();
                try { $v = FormulaEval::evaluate(self::FORMULAS[$key] ?? '0', array_merge($vars, $calc)); }
                catch (Throwable $e2) { $v = 0.0; }
            }
            $calc[$key] = round($v, 2);
        }
        foreach (array_keys(self::FORMULAS) as $k) if (!isset($calc[$k])) $calc[$k] = 0.0;

        return [
            'used'    => $used,
            'ral'     => $ral,
            'calc'    => $calc,
            'errors'  => $errors,
        ];
    }

    /** Etichette correnti (dalla tabella se presente). */
    public function labelsCurrent(): array
    {
        try {
            $rows = $this->pdo->query("SELECT formula_key, label FROM hr_formulas ORDER BY sort_order, id")->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($rows) return $rows;
        } catch (Throwable $e) {}
        return self::labels();
    }

    public static function labels(): array
    {
        return [
            'full_cost'           => 'FullCost',
            'tot_aa_ta_bp'        => 'TotAAxTA+BP',
            'rimborso_km'         => 'Rimborso KM',
            'totale_pre_overhead' => 'TotalePreOverHead',
            'tot_costo_tab'       => 'TotCostoTab',
            'costo_no_auto'       => 'CostoNoAuto',
            'valore_fte'          => 'ValoreFTE',
            'totale_fte_ca'       => 'TotaleFTE+CA',
        ];
    }
}
