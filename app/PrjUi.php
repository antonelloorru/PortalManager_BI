<?php
/**
 * PortalManager — app/PrjUi.php  (v1.10.01)
 *
 * Helper di interfaccia dei Progetti PRJ:
 *  - formattazione (k€, €, h, FTE, %);
 *  - griglie modificabili delle tabelle versionate (campi in whitelist, decorrenza, rettifica);
 *  - salvataggio della griglia: per ogni riga cambiata PrjRepo::writeVersion (nuova versione datata o rettifica).
 * Sicurezza: tabelle e campi solo da whitelist, appartenenza della riga al progetto (o globale) verificata
 * lato server, valori numerici normalizzati, enum validati.
 */
declare(strict_types=1);

require_once __DIR__ . '/PrjRepo.php';

final class PrjUi
{
    /** Campi modificabili per tabella: campo => tipo (num|int|bool|text|enum:a,b). */
    public const EDITABLE = [
        'cm_prj_gara'            => ['durata_mesi' => 'int', 'mesi_operativi' => 'int', 'phase_in_giorni' => 'int', 'phase_in_retribuito' => 'bool', 'handover_giorni' => 'int', 'rinnovo_mesi' => 'int'],
        'cm_prj_tender_base'     => ['canone_eur' => 'num', 'uncommitted_eur' => 'num'],
        'cm_prj_rate_card'       => ['eur_giorno' => 'num'],
        'cm_prj_service'         => ['nome' => 'text', 'modalita' => 'enum:remoto,ibrido,in_sede', 'max_interventi_sede_anno' => 'int', 'fuori_orario_remoto' => 'int',
                                     'fuori_orario_sede' => 'int', 'fuori_orario_note' => 'text', 'h24' => 'bool', 'avvio_anno' => 'int'],
        'cm_prj_asset_metric'    => ['valore' => 'num'],
        'cm_prj_ticket_volume'   => ['quantita' => 'int'],
        'cm_prj_ticket_mapping'  => ['quota' => 'num'],
        'cm_prj_aht'             => ['ore' => 'num'],
        'cm_prj_productivity'    => ['ore_utili_fte' => 'num', 'giorni_fte' => 'num', 'uplift' => 'num', 'banda_volumi' => 'num'],
        'cm_prj_service_profile' => ['n_minimo' => 'int', 'fte' => 'num'],
        'cm_prj_salary_band'     => ['ral_min' => 'num', 'ral_ideale' => 'num'],
        'cm_prj_profile_req'     => ['anni_min' => 'int', 'titolo_min' => 'text', 'lingue' => 'text'],
        'cm_prj_param'           => ['valore' => 'num'],
        'cm_prj_zone'            => ['indice_ral' => 'num', 'affitto_mq_mese' => 'num'],
        'cm_prj_nearshore'       => ['indice_ral' => 'num', 'oneri_pct' => 'num'],
        'cm_prj_equipment'       => ['prezzo' => 'num', 'anni_ammortamento' => 'num'],
        'cm_prj_site_cost'       => ['eur_mese' => 'num'],
        'cm_prj_overhead'        => ['tipo' => 'enum:fisso,per_fte', 'importo' => 'num'],
        'cm_prj_kpi'             => ['livello_atteso' => 'text', 'penale_importo' => 'num', 'penale_importi_priorita' => 'text'],
        'cm_prj_criterion'       => ['punti_max' => 'num', 'formula' => 'text'],
    ];

    /* ── formattazione ── */
    public static function k(?float $v, int $dec = 1): string { return $v === null ? '—' : number_format($v / 1000, $dec, ',', '.') . ' k€'; }
    public static function eur(?float $v, int $dec = 2): string { return $v === null ? '—' : number_format($v, $dec, ',', '.') . ' €'; }
    public static function n(?float $v, int $dec = 2, string $u = ''): string { return $v === null ? '—' : number_format($v, $dec, ',', '.') . ($u !== '' ? " $u" : ''); }
    public static function pct(?float $v, int $dec = 0): string { return $v === null ? '—' : number_format($v * 100, $dec, ',', '.') . '%'; }

    /** Numero da input utente (accetta 1.234,56 e 1234.56). */
    public static function num($s): ?float
    {
        $s = trim((string)$s);
        if ($s === '') return null;
        $s = str_replace(["\u{00A0}", ' ', '€', '%'], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        elseif (str_contains($s, ',')) $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    /** Valore normalizzato secondo il tipo; lancia eccezione se non valido. */
    public static function cast(string $type, $v)
    {
        if ($type === 'bool') return !empty($v) && $v !== '0' ? 1 : 0;
        if ($type === 'text') { $v = trim((string)$v); return $v === '' ? null : mb_substr($v, 0, 255); }
        if (str_starts_with($type, 'enum:')) {
            $ok = explode(',', substr($type, 5));
            if (!in_array((string)$v, $ok, true)) throw new InvalidArgumentException('Valore non ammesso: ' . $v);
            return (string)$v;
        }
        $n = self::num($v);
        if ($n === null) return null;
        if ($n < 0) throw new InvalidArgumentException('Valore negativo non ammesso.');
        return $type === 'int' ? (int)round($n) : $n;
    }

    /** Input di una cella modificabile. */
    public static function input(string $table, int $id, string $col, $val, bool $edit, string $extra = ''): string
    {
        $type = self::EDITABLE[$table][$col] ?? 'text';
        $name = 'v[' . $table . '][' . $id . '][' . $col . ']';
        if (!$edit) {
            if ($type === 'bool') return $val ? '✔' : '—';
            if ($type === 'num' || $type === 'int') return $val === null || $val === '' ? '—' : h(self::n((float)$val, $type === 'int' ? 0 : (fmod((float)$val, 1) == 0 ? 0 : 4)));
            return h((string)($val ?? '—'));
        }
        if ($type === 'bool')
            return '<input type="hidden" name="' . h($name) . '" value="0"><input type="checkbox" name="' . h($name) . '" value="1"' . ($val ? ' checked' : '') . ' ' . $extra . '>';
        if (str_starts_with($type, 'enum:')) {
            $o = '';
            foreach (explode(',', substr($type, 5)) as $e) $o .= '<option value="' . h($e) . '"' . ((string)$val === $e ? ' selected' : '') . '>' . h(str_replace('_', ' ', $e)) . '</option>';
            return '<select name="' . h($name) . '" ' . $extra . '>' . $o . '</select>';
        }
        $v = $val === null ? '' : (string)$val;
        if (($type === 'num' || $type === 'int') && $v !== '' && is_numeric($v)) { $v = rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.'); if ($v === '' || $v === '-0') $v = '0'; }
        $w = $type === 'text' ? 'min-width:140px' : 'width:96px;text-align:right';
        return '<input type="text" name="' . h($name) . '" value="' . h($v) . '" style="' . $w . ';padding:3px 6px;font-size:12px" ' . $extra . '>';
    }

    /** Campi comuni dei form versionati: decorrenza e nota. */
    public static function versionFields(): string
    {
        return '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px;font-size:12px">'
             . '<div class="form-group" style="margin:0"><label style="font-size:11px">Decorrenza</label><input type="date" name="valid_from" value="' . date('Y-m-d') . '" required></div>'
             . '<div class="form-group" style="margin:0;flex:1;min-width:200px"><label style="font-size:11px">Nota della modifica</label><input type="text" name="change_note" maxlength="200" placeholder="motivo / fonte"></div>'
             . '<button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva</button>'
             . '<span style="color:var(--muted);font-size:11px">Nuova versione dalla decorrenza; stessa decorrenza della versione vigente = rettifica.</span></div>';
    }

    /**
     * Salvataggio di una griglia versionata.
     * @param int  $prjId  progetto proprietario (0 = parametri globali, prj_key = 0)
     * @return array{saved:int, errors:string[]}
     */
    public static function saveGrid(PDO $pdo, PrjRepo $repo, int $prjId, array $post, ?int $userId): array
    {
        $vf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['valid_from'] ?? '')) ? $post['valid_from'] : date('Y-m-d');
        $note = mb_substr(trim((string)($post['change_note'] ?? '')), 0, 200);
        $saved = 0; $err = [];
        foreach ((array)($post['v'] ?? []) as $table => $rows) {
            if (!isset(self::EDITABLE[$table]) || !is_array($rows)) continue;
            $spec = self::EDITABLE[$table];
            $own = $prjId > 0 ? 'prj_id = ?' : 'prj_key = 0';
            $sel = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? AND is_current = 1 AND $own");
            foreach ($rows as $id => $vals) {
                if (!ctype_digit((string)$id) || !is_array($vals)) continue;
                $sel->execute($prjId > 0 ? [(int)$id, $prjId] : [(int)$id]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $err[] = "Riga $table #$id non modificabile."; continue; }
                $chg = [];
                try {
                    foreach ($vals as $col => $v) {
                        if (!isset($spec[$col])) continue;
                        $nv = self::cast($spec[$col], $v);
                        $ov = $row[$col];
                        $same = ($nv === null && ($ov === null || $ov === '')) ||
                                ($nv !== null && $ov !== null && (is_numeric($ov) && !is_string($nv) ? abs((float)$ov - (float)$nv) < 1e-9 : (string)$ov === (string)$nv));
                        if (!$same) $chg[$col] = $nv;
                    }
                } catch (Throwable $e) { $err[] = "$table #$id: " . $e->getMessage(); continue; }
                if (!$chg) continue;
                try { $repo->writeVersion($table, (int)$id, $chg, $vf, $userId, $note); $saved++; }
                catch (Throwable $e) { $err[] = "$table #$id: " . $e->getMessage(); }
            }
        }
        return ['saved' => $saved, 'errors' => $err];
    }

    /** Messaggio flash dell'esito. */
    public static function flash(array $r, string $what = 'righe'): string
    {
        $m = $r['saved'] ? "<div class='alert alert-success'>Salvate {$r['saved']} $what.</div>" : "<div class='alert alert-info'>Nessuna modifica da salvare.</div>";
        if ($r['errors']) $m .= "<div class='alert alert-danger'>" . implode('<br>', array_map('h', array_slice($r['errors'], 0, 10))) . '</div>';
        return $m;
    }

    public const STATI = ['Bozza', 'In analisi', 'Offerta presentata', 'Aggiudicato', 'Perso', 'Ritirato', 'In esecuzione', 'Chiuso'];
    public const TIPI  = ['Gara Consip/MePA/Carrier', 'Progetto Standard', 'Trattativa Diretta'];

    public static function statoColor(string $s): string
    {
        return match ($s) {
            'Aggiudicato', 'In esecuzione' => '#16a34a', 'Offerta presentata' => '#2563eb', 'In analisi' => '#7c3aed',
            'Perso', 'Ritirato' => '#dc2626', 'Chiuso' => '#64748b', default => '#94a3b8',
        };
    }
}
