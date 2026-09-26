<?php
/**
 * PortalManager — app/PmFilter.php  (v1.9.71)
 *
 * Helper per filtri server-side MULTI-VALORE e con intervalli, da usare nelle pagine
 * che filtrano via GET. Legge sia il formato array (stato[]=A&stato[]=B) sia quello a
 * virgole (stato=A,B), così i link e i segnalibri esistenti continuano a funzionare.
 *
 *   $stati = PmFilter::values('stato');                       // ['A','B']
 *   $where[] = PmFilter::in('p.operational_status', $stati, $params);
 *   $where[] = PmFilter::range('p.start_date', PmFilter::date('dal'), PmFilter::date('al'), $params);
 *   echo PmFilter::select('stato', $opzioni, $stati, 'Tutti gli stati');   // multi-select con ricerca
 */
declare(strict_types=1);

final class PmFilter
{
    /** Valori richiesti per un parametro (array o CSV), puliti e senza duplicati. */
    public static function values(string $key, ?array $src = null): array
    {
        $src ??= $_GET;
        $raw = $src[$key] ?? [];
        if (!is_array($raw)) $raw = explode(',', (string)$raw);
        $out = [];
        foreach ($raw as $v) {
            if (is_array($v)) continue;
            $v = trim((string)$v);
            if ($v !== '') $out[$v] = true;
        }
        return array_keys($out);
    }

    /** Condizione "col IN (…)" con parametri posizionali; '1=1' se nessun valore. */
    public static function in(string $col, array $vals, array &$params): string
    {
        if (!$vals) return '1=1';
        foreach ($vals as $v) $params[] = $v;
        return $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
    }

    /** Condizione di intervallo (estremi inclusi); '1=1' se entrambi vuoti. */
    public static function range(string $col, $min, $max, array &$params): string
    {
        $c = [];
        if ($min !== null && $min !== '') { $c[] = "$col >= ?"; $params[] = $min; }
        if ($max !== null && $max !== '') { $c[] = "$col <= ?"; $params[] = $max; }
        return $c ? implode(' AND ', $c) : '1=1';
    }

    /** Data da GET in formato Y-m-d (accetta anche gg/mm/aaaa), null se non valida. */
    public static function date(string $key, ?array $src = null): ?string
    {
        $v = trim((string)(($src ?? $_GET)[$key] ?? ''));
        if ($v === '') return null;
        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }

    /** Numero da GET (formato italiano o internazionale), null se non valido. */
    public static function number(string $key, ?array $src = null): ?float
    {
        $v = trim((string)(($src ?? $_GET)[$key] ?? ''));
        if ($v === '') return null;
        if (strpos($v, ',') !== false) $v = str_replace(['.', ','], ['', '.'], $v);
        return is_numeric($v) ? (float)$v : null;
    }

    /**
     * <select multiple> resa dal componente con ricerca (assets/js/pm-multiselect.js).
     * $options: [valore => etichetta] oppure lista di valori.
     */
    public static function select(string $name, array $options, array $selected, string $placeholder = 'Tutti', array $attrs = []): string
    {
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $isList = array_keys($options) === range(0, count($options) - 1);
        $sel = array_flip(array_map('strval', $selected));
        $extra = '';
        foreach ($attrs as $k => $v) $extra .= ' ' . $h($k) . '="' . $h($v) . '"';
        $html = '<select name="' . $h($name) . '[]" multiple class="pm-ms" data-placeholder="' . $h($placeholder) . '"' . $extra . '>';
        foreach ($options as $k => $label) {
            $val = $isList ? $label : $k;
            $html .= '<option value="' . $h($val) . '"' . (isset($sel[(string)$val]) ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        return $html . '</select>';
    }
}
