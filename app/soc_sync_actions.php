<?php
/**
 * app/soc_sync_actions.php — v1.10.07
 * Azioni (POST) della sottosezione «SOC» di Sincronizzazione gestionale. Incluso da sync_commesse.php dopo
 * CSRF e controllo del permesso di esecuzione; ogni azione termina con redirect (PRG) alla scheda SOC.
 * Variabili attese: $pdo, $u_id, $action.
 */
declare(strict_types=1);

require_once __DIR__ . '/SocSync.php';
require_once __DIR__ . '/SourceDb.php';

$socBack = static function (string $type, string $msg, array $extra = []): void {
    $_SESSION['flash_msg'] = "<div class='alert alert-$type'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>";
    redirect('sync_commesse', ['tab' => 'soc'] + $extra);
};
$isSA = (int)($_SESSION['role_id'] ?? 99) === 1;
$ing = new SocIngest($pdo);

switch ($action) {
    case 'soc_run':                                    // esecuzione immediata della pipeline
        $r = SocSync::run($pdo, 'manuale', true, $u_id);
        write_log('Service SOC', $r['ok'] ? 'success' : 'warning', 'Pipeline SOC (manuale): ' . $r['message'], $u_id);
        $socBack($r['ok'] ? 'success' : 'danger', ($r['ran'] ? 'Sincronizzazione SOC: ' : 'Nessuna esecuzione: ') . $r['message']);

    case 'soc_upload':                                 // il file entra nella cartella di arrivo e la pipeline parte subito
        $fl = $_FILES['file'] ?? null;
        $err = (int)($fl['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true))
            $socBack('danger', 'File oltre il limite del server (upload_max_filesize = ' . ini_get('upload_max_filesize') . '): aumentarlo in php.ini o depositare il file nella cartella di arrivo.');
        if (!$fl || $err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$fl['tmp_name'])) $socBack('danger', 'Caricamento del file non riuscito.');
        if ((int)$fl['size'] > 50 * 1048576) $socBack('danger', 'File oltre 50 MB.');
        try { SocSync::enqueue($pdo, (string)$fl['tmp_name'], basename((string)$fl['name'])); }
        catch (Throwable $e) { $socBack('danger', $e->getMessage()); }
        $r = SocSync::run($pdo, 'caricamento', true, $u_id);
        write_log('Service SOC', $r['ok'] ? 'success' : 'warning', 'Caricamento ' . basename((string)$fl['name']) . ': ' . $r['message'], $u_id);
        $socBack($r['ok'] ? 'success' : 'danger', ($r['ran'] ? 'File acquisito e sincronizzato: ' : 'File in coda (pipeline già in esecuzione): ') . $r['message']);

    case 'soc_settings':
        SocSync::set($pdo, 'soc.sync_enabled', empty($_POST['sync_enabled']) ? '0' : '1');
        SocSync::set($pdo, 'soc.interval_min', (string)max(5, min(1440, (int)($_POST['interval'] ?? 60))));
        SocSync::set($pdo, 'soc.sla_risposta_ore', (string)max(1, min(240, (int)($_POST['sla'] ?? 4))));
        SocSync::set($pdo, 'soc.presidio_ore', (string)max(1, min(720, (int)($_POST['presidio'] ?? 24))));
        $cs = implode(',', array_filter(array_map(fn($s) => strtoupper(trim($s)), explode(',', (string)($_POST['closed'] ?? '')))));
        SocSync::set($pdo, 'soc.closed_states', $cs ?: 'CHIUSO,CHIUSO DAL CLIENTE');
        SocSync::set($pdo, 'soc.uo_auto', empty($_POST['uo_auto']) ? '0' : '1');
        if ($isSA) {
            $dir = trim(str_replace('\\', '/', (string)($_POST['inbox'] ?? '')), '/');
            if ($dir !== '' && !str_contains($dir, '..')) SocSync::set($pdo, 'soc.inbox_dir', $dir);
        }
        try { $ing->rebuild(); } catch (Throwable $e) {}
        write_log('Service SOC', 'info', 'Impostazioni sincronizzazione SOC aggiornate', $u_id);
        $socBack('success', 'Impostazioni salvate, ticket ricalcolati.');

    case 'soc_uo_force':                               // sposta su SOC un tecnico assegnato a un'altra unità
        $emp = (int)($_POST['employee_id'] ?? 0);
        $r = SocSync::assignUnit($pdo, [$emp]);
        write_log('Service SOC', 'info', "Tecnico #$emp spostato sull'Unità Organizzativa SOC", $u_id);
        $socBack('success', 'Unità Organizzativa SOC: ' . $r['assigned'] . ' tecnico assegnato.');

    case 'soc_db_save': case 'soc_db_test': case 'soc_db_preview':
        if (!$isSA) $socBack('danger', 'Solo il Super Admin configura la connessione al DB SOC.');
        $cur = $pdo->query("SELECT * FROM cm_soc_source_db ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        $in = fn($k) => trim((string)($_POST[$k] ?? ''));
        $row = [
            'label' => $in('label') ?: 'Gestionale SOC', 'driver' => array_key_exists($in('driver'), SourceDb::DRIVERS) ? $in('driver') : 'mysql',
            'host' => $in('host'), 'port' => (int)$in('port') ?: 3306, 'dbname' => $in('dbname'), 'username' => $in('username'),
            'source_schema' => $in('source_schema') ?: null, 'timeout' => max(3, min(60, (int)$in('timeout') ?: 10)),
            'window_days' => max(0, min(3650, (int)$in('window_days'))), 'ticket_prefix' => preg_replace('/[^A-Za-z0-9_]/', '', $in('ticket_prefix')) ?: null,
            'extract_sql' => $in('extract_sql') ?: null, 'is_active' => empty($_POST['is_active']) ? 0 : 1,
        ];
        $pw = (string)($_POST['password'] ?? '');
        try { $row['password_enc'] = $pw !== '' ? SourceDb::encrypt($pw) : ($cur['password_enc'] ?? ''); } catch (Throwable $e) { $socBack('danger', $e->getMessage()); }
        // v1.10.08 — server e credenziali ereditati dalla Connessione al gestionale: cambia solo il database
        $row['use_gestionale'] = empty($_POST['use_gestionale']) ? 0 : 1;
        if ($row['use_gestionale']) {
            foreach (['host', 'username'] as $k) if ($row[$k] === '') $row[$k] = (string)($cur[$k] ?? '');
            if ($row['dbname'] === '') $socBack('danger', 'Indicare il nome del database SOC.');
        } elseif ($row['host'] === '' || $row['dbname'] === '' || $row['username'] === '') $socBack('danger', 'Host, database e utente sono obbligatori.');
        if ($row['extract_sql'] !== null && !preg_match('/^\s*(SELECT|WITH)\b/i', (string)$row['extract_sql'])) $socBack('danger', 'La query di estrazione deve essere una SELECT.');
        if ($action === 'soc_db_save') {
            $cols = array_keys($row);
            if ($cur) $pdo->prepare("UPDATE cm_soc_source_db SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . " WHERE id = ?")->execute([...array_values($row), $cur['id']]);
            else $pdo->prepare("INSERT INTO cm_soc_source_db (" . implode(', ', $cols) . ", created_by) VALUES (" . implode(',', array_fill(0, count($cols) + 1, '?')) . ")")->execute([...array_values($row), $u_id]);
            write_log('Service SOC', 'info', 'Connessione DB SOC salvata (' . ($row['use_gestionale'] ? 'credenziali del gestionale' : $row['host']) . '/' . $row['dbname'] . ')', $u_id);
            $socBack('success', 'Connessione al DB SOC salvata: la pipeline la userà dalla prossima esecuzione.');
        }
        try {
            $row = SocIngest::resolveSource($pdo, $row);
            $src = SourceDb::connect(SourceDb::configFromRow($row));
            if ($action === 'soc_db_test') $socBack('success', 'Connessione riuscita: ' . SourceDb::DRIVERS[$row['driver']]['label'] . ' ' . $src->serverVersion()
                . ' — ' . $row['username'] . '@' . $row['host'] . '/' . $row['dbname'] . ($row['cred_origin'] === 'gestionale' ? ' (credenziali del gestionale)' : ''));
            $pv = $ing->previewDb($row, 10);
            $_SESSION['soc_preview'] = $pv;
            $socBack('success', 'Anteprima (ultimi 7 giorni): ' . $pv['count'] . ' eventi, colonne riconosciute: ' . implode(', ', $pv['columns']), ['pv' => 1]);
        } catch (Throwable $e) { $socBack('danger', 'DB SOC: ' . SocIngest::connError($pdo, $e, $row)); }

    case 'soc_map_people': case 'soc_map_clients':
        $tbl = $action === 'soc_map_people' ? ['cm_soc_people', 'employee_id', 'employees'] : ['cm_soc_clients', 'client_id', 'clients'];
        $st = $pdo->prepare("UPDATE {$tbl[0]} SET {$tbl[1]} = ?, is_manual = 1 WHERE name = ?");
        $ok = $pdo->prepare("SELECT COUNT(*) FROM {$tbl[2]} WHERE id = ?");
        $n = 0;
        foreach ((array)($_POST['map'] ?? []) as $name => $id) {
            $id = (int)$id; if ((string)$id === (string)($_POST['orig'][$name] ?? '')) continue;
            if ($id > 0) { $ok->execute([$id]); if (!$ok->fetchColumn()) continue; }
            $st->execute([$id > 0 ? $id : null, (string)$name]); $n++;
        }
        $ing->autoMap();
        if ($action === 'soc_map_people') SocSync::assignUnit($pdo);
        $socBack('success', "Abbinamenti aggiornati: $n.");
}
$socBack('danger', 'Azione non riconosciuta.');
