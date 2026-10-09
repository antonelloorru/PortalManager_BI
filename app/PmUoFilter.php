<?php
/**
 * PortalManager — app/PmUoFilter.php  (v1.10.30)
 *
 * Filtro multi-select «Unità Organizzativa» condiviso dalle pagine di Gestione Commesse: Commesse / Progetti,
 * Report direzionale, Service Desk, Service SOC, Relazione di Servizio IT, Attività & Rendicontazione DGB,
 * Carico & Sovrapposizioni, Relazione Tecnici.
 *
 * Sorgente: Anagrafica tecnica (cm_tech_units ← cm_tech_profiles attivi: dipendente o professionista esterno).
 * Parametro: uo[] (id dell'unità) oppure uo=1,4 nei link. Gli id sono interi validati: le sottoquery li
 * contengono in chiaro (nessun parametro da allineare con le altre condizioni della pagina).
 *
 * Significato per oggetto filtrato:
 *   persona (modulo, allocazione, ticket, carico) → la risorsa appartiene a una delle unità scelte;
 *   commessa (Commesse / Progetti, Report direzionale) → almeno un modulo di intervento o un membro del team
 *   della commessa appartiene a una delle unità scelte.
 */
declare(strict_types=1);

final class PmUoFilter
{
    public const KEY = 'uo';

    /** Selezione normalizzata (id interi positivi, univoci, al massimo 30). */
    public static function norm($raw): array
    {
        $v = is_array($raw) ? $raw : explode(',', (string)$raw);
        $out = [];
        foreach ($v as $x) { if (is_array($x)) continue; $i = (int)trim((string)$x); if ($i > 0) $out[$i] = $i; }
        return array_slice(array_values($out), 0, 30);
    }

    public static function fromRequest(array $q): array { return self::norm($q[self::KEY] ?? []); }

    /** Unità attive: [id => nome], nell'ordine dell'anagrafica. */
    public static function options(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            return $cache = $pdo->query("SELECT id, name FROM cm_tech_units WHERE COALESCE(is_active,1) = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) { return $cache = []; }
    }

    private static function ids(array $sel): string { return implode(',', array_map('intval', $sel ?: [-1])); }

    /** Sottoquery: dipendenti delle unità scelte. */
    public static function empSub(array $sel): string
    {
        return "SELECT tpu.employee_id FROM cm_tech_profiles tpu WHERE tpu.is_active = 1 AND tpu.employee_id IS NOT NULL AND tpu.unit_id IN (" . self::ids($sel) . ")";
    }

    /** Sottoquery: professionisti esterni delle unità scelte. */
    public static function profSub(array $sel): string
    {
        return "SELECT tpp.professional_id FROM cm_tech_profiles tpp WHERE tpp.is_active = 1 AND tpp.professional_id IS NOT NULL AND tpp.unit_id IN (" . self::ids($sel) . ")";
    }

    /** Condizione su una colonna «id dipendente». */
    public static function empSql(array $sel, string $col): string { return "$col IN (" . self::empSub($sel) . ")"; }

    /** Condizione su una colonna «id operatore DGB» (dgb_operator_map → dipendente). */
    public static function dgbOperatorSql(array $sel, string $col): string
    {
        return "$col IN (SELECT omu.dgb_operator_id FROM dgb_operator_map omu WHERE omu.employee_id IN (" . self::empSub($sel) . "))";
    }

    /** Condizione su una commessa per id (alias p.id): moduli di intervento o team con risorse delle unità. */
    public static function projectSql(array $sel, string $col): string
    {
        return "(EXISTS (SELECT 1 FROM cm_intervention_reports iru WHERE iru.project_id = $col
                          AND (iru.technician_id IN (" . self::empSub($sel) . ") OR iru.technician_professional_id IN (" . self::profSub($sel) . ")))
                 OR EXISTS (SELECT 1 FROM cm_team tmu WHERE tmu.project_id = $col
                          AND (tmu.employee_id IN (" . self::empSub($sel) . ") OR tmu.professional_id IN (" . self::profSub($sel) . "))))";
    }

    /** Condizione su una commessa per codice (cm_projects.project_code). */
    public static function projectCodeSql(array $sel, string $col): string
    {
        return "$col IN (SELECT pu.project_code FROM cm_projects pu WHERE " . self::projectSql($sel, 'pu.id') . ")";
    }

    /** Dipendenti delle unità scelte (id). */
    public static function employeeIds(PDO $pdo, array $sel): array
    {
        if (!$sel) return [];
        try { return array_map('intval', $pdo->query(self::empSub($sel))->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; }
    }

    /**
     * Nomi delle persone delle unità (minuscolo, «cognome nome» e «nome cognome») per le sorgenti che identificano
     * la risorsa per nome (ticket del Service Desk).
     */
    public static function names(PDO $pdo, array $sel): array
    {
        if (!$sel) return [];
        $out = [];
        try {
            $q = "SELECT e.first_name f, e.last_name l FROM employees e WHERE e.id IN (" . self::empSub($sel) . ")
                  UNION SELECT p.first_name, p.last_name FROM cm_professionals p WHERE p.id IN (" . self::profSub($sel) . ")";
            foreach ($pdo->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $f = mb_strtolower(trim((string)$r['f'])); $l = mb_strtolower(trim((string)$r['l']));
                if ($f === '' && $l === '') continue;
                $out[trim("$l $f")] = 1; $out[trim("$f $l")] = 1;
            }
        } catch (Throwable $e) {}
        return array_keys($out);
    }

    /** Campo del pannello filtri (select multipla con ricerca, pm-ms). */
    public static function field(array $opts, array $sel, string $hint = ''): string
    {
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $h = '<div class="form-group"><label>Unità Organizzativa <span class="pm-multi">(multipla' . ($hint !== '' ? ' · ' . $e($hint) : '') . ')</span></label>'
           . '<select name="' . self::KEY . '[]" multiple size="4" class="pm-ms" data-placeholder="Tutte">';
        foreach ($opts as $id => $name) $h .= '<option value="' . (int)$id . '"' . (in_array((int)$id, $sel, true) ? ' selected' : '') . '>' . $e($name) . '</option>';
        return $h . '</select></div>';
    }

    public static function describe(array $sel, array $opts): string
    {
        return $sel ? 'Unità Organizzativa: ' . implode(', ', array_map(fn($i) => $opts[$i] ?? ('#' . $i), $sel)) : '';
    }

    /** Valore per i link (uo=1,4). */
    public static function query(array $sel): string { return implode(',', $sel); }
}
