<?php
/**
 * app/WpAtsSync.php — v1.10.05
 * Sincronizzazione PortalManager ⇄ sito WordPress (plugin pm-ats), sempre avviata da PortalManager.
 *
 *  push  — posizioni aperte (vista v_public_open_positions) → POST /sync/jobs (mode=full: il sito ritira
 *          quelle non più aperte). Esito in position_publications (channel 'wordpress', URL della scheda).
 *  pull  — candidature dal sito: GET /sync/applications (a pagine), GET /sync/applications/{uuid}/cv,
 *          import in candidates / candidate_documents / candidate_applications, POST /sync/ack.
 *          Idempotente: wp_ats_imports (uuid → candidato/candidatura); un uuid già importato viene solo riconfermato.
 *  Registro: wp_ats_sync_log. Lock: GET_LOCK('pm_wpats_sync') — mai due sincronizzazioni insieme.
 */
declare(strict_types=1);

require_once __DIR__ . '/WpAtsClient.php';

final class WpAtsSync
{
    /** Estensione → [MIME canonico, MIME ammessi da finfo, firma iniziale] (come il plugin). */
    private const CV = [
        'pdf'  => ['application/pdf', ['application/pdf'], "%PDF-"],
        'doc'  => ['application/msword', ['application/msword', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'], "\xD0\xCF\x11\xE0"],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                   ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], "PK\x03\x04"],
    ];
    private const ACTIVE = ['cv_received', 'screening', 'tech_test', 'hr_interview', 'tech_interview', 'offer_sent'];

    private ?WpAtsClient $c;
    private array $say = [];

    public function __construct(private PDO $pdo, ?WpAtsClient $client = null, private ?Closure $out = null)
    {
        $this->c = $client ?? WpAtsClient::fromSettings($pdo);
    }

    public function configured(): bool { return $this->c !== null; }

    private function log(string $m): void { $this->say[] = $m; if ($this->out) ($this->out)($m); }

    /* ── operazioni ──────────────────────────────────────────────────── */

    /** @return array{ok:bool, message:string, data?:array} */
    public function test(?int $userId = null, string $trigger = 'manuale'): array
    {
        if (!$this->c) return $this->finish($this->start('test', $trigger, $userId), false, 'Configurazione incompleta (URL, client ID o PM_WPATS_SECRET in .env.php)');
        $id = $this->start('test', $trigger, $userId);
        $r = $this->c->request('GET', '/sync/status');
        if ($r['status'] !== 200 || empty($r['json']['ok'])) return $this->finish($id, false, WpAtsClient::describe($r));
        $j = $r['json'];
        return $this->finish($id, true, sprintf('Connesso a %s — plugin %s, WordPress %s, %d posizioni pubblicate, %d candidature da importare (%d ms)',
            $j['site'] ?? '?', $j['plugin'] ?? '?', $j['wordpress'] ?? '?', (int)($j['jobs_published'] ?? 0), (int)($j['applications']['pending'] ?? 0), $r['ms']), [], $j);
    }

    /** Invia l'elenco completo delle posizioni aperte. */
    public function pushJobs(?int $userId = null, string $trigger = 'manuale'): array
    {
        $id = $this->start('push', $trigger, $userId);
        if (!$this->c) return $this->finish($id, false, 'Configurazione incompleta');
        if (!$this->lock()) return $this->finish($id, false, 'Sincronizzazione già in corso');
        try {
            $items = $this->openPositions();
            $r = $this->c->request('POST', '/sync/jobs', ['mode' => 'full', 'items' => $items, 'closed' => []]);
            if ($r['status'] !== 200 || empty($r['json']['ok'])) return $this->finish($id, false, WpAtsClient::describe($r), ['jobs_sent' => count($items)]);
            $j = $r['json'];
            $this->recordPublications((array)($j['map'] ?? []), $userId, true);
            $cnt = ['jobs_sent' => count($items), 'jobs_created' => (int)$j['created'], 'jobs_updated' => (int)$j['updated'], 'jobs_withdrawn' => (int)$j['withdrawn']];
            $err = (array)($j['errors'] ?? []);
            $msg = sprintf('Posizioni inviate %d: nuove %d, aggiornate %d, invariate %d, ritirate %d%s', count($items), $j['created'], $j['updated'], $j['unchanged'], $j['withdrawn'],
                $err ? ', scartate ' . count($err) : '');
            $this->log($msg);
            return $this->finish($id, !$err, $msg, $cnt, $j, $err ? 'warn' : null);
        } finally { $this->unlock(); }
    }

    /** Preleva e importa le candidature (al massimo $max per esecuzione). */
    public function pullApplications(?int $userId = null, string $trigger = 'manuale', int $max = 500): array
    {
        $id = $this->start('pull', $trigger, $userId);
        if (!$this->c) return $this->finish($id, false, 'Configurazione incompleta');
        if (!$this->lock()) return $this->finish($id, false, 'Sincronizzazione già in corso');
        $batch = max(1, min(100, (int)(WpAtsClient::settings($this->pdo)['wpats.pull_batch'] ?? 20)));
        $after = 0; $fetched = 0; $imported = 0; $failed = 0; $skipped = 0; $errors = [];
        try {
            while ($fetched < $max) {
                $r = $this->c->request('GET', '/sync/applications', null, ['limit' => $batch, 'after' => $after]);
                if ($r['status'] !== 200 || empty($r['json']['ok'])) { $errors[] = WpAtsClient::describe($r); break; }
                $items = (array)($r['json']['items'] ?? []);
                if (!$items) break;
                $acks = [];
                foreach ($items as $a) {
                    $fetched++; $after = max($after, (int)($a['id'] ?? 0));
                    $res = $this->importOne((array)$a, $userId);
                    if ($res['ack'] === null) { $skipped++; $errors[] = $res['error']; continue; }   // errore temporaneo: resta sul sito
                    $acks[] = $res['ack'];
                    $res['ack']['ok'] ? $imported++ : $failed++;
                    if (!$res['ack']['ok']) $errors[] = substr((string)$a['uuid'], 0, 8) . ': ' . $res['ack']['error'];
                }
                if ($acks) {
                    $ra = $this->c->request('POST', '/sync/ack', ['items' => $acks]);
                    if ($ra['status'] === 200) {
                        $st = $this->pdo->prepare("UPDATE wp_ats_imports SET acked_at = NOW() WHERE wp_uuid = ?");
                        foreach ($acks as $k) $st->execute([$k['uuid']]);
                    } else $errors[] = 'Conferma al sito non riuscita: ' . WpAtsClient::describe($ra);
                }
                if (count($items) < $batch) break;
            }
        } finally { $this->unlock(); }
        $msg = sprintf('Candidature prelevate %d: importate %d, rifiutate %d, rinviate %d', $fetched, $imported, $failed, $skipped);
        $this->log($msg);
        $ok = !$errors || ($imported + $failed > 0 && $skipped === 0 && $failed === 0);
        return $this->finish($id, $ok && !$errors, $msg . ($errors ? ' — ' . implode(' · ', array_slice(array_unique($errors), 0, 5)) : ''),
            ['apps_fetched' => $fetched, 'apps_imported' => $imported, 'apps_failed' => $failed + $skipped], null, $errors && ($imported > 0) ? 'warn' : null);
    }

    /** push + pull. */
    public function run(?int $userId = null, string $trigger = 'pianificata'): array
    {
        $p = $this->pushJobs($userId, $trigger);
        $q = $this->pullApplications($userId, $trigger);
        return ['ok' => $p['ok'] && $q['ok'], 'message' => $p['message'] . ' | ' . $q['message'], 'push' => $p, 'pull' => $q];
    }

    /**
     * Dopo una modifica alle posizioni (recruiting_posizioni.php): invio immediato se abilitato.
     * Timeout breve, nessun errore mostrato all'utente (resta nel registro).
     */
    public static function pushOnChange(PDO $pdo, ?int $userId): void
    {
        try {
            $s = WpAtsClient::settings($pdo);
            if ($s['wpats.enabled'] !== '1' || $s['wpats.push_on_change'] !== '1') return;
            $c = WpAtsClient::fromSettings($pdo, 8);
            if ($c) (new self($pdo, $c))->pushJobs($userId, 'modifica');
        } catch (Throwable $e) { error_log('[WpAtsSync::pushOnChange] ' . $e->getMessage()); }
    }

    /* ── posizioni ───────────────────────────────────────────────────── */

    /** Campi della posizione inviati al sito. */
    public const ITEM_COLS = 'id, title, department, location, contract_type, remote_policy, description, required_skills, nice_to_have,
                              hard_skills, soft_skills, benefits, we_offer, presentation_text, offer_info, gender_disclaimer,
                              positions_expected, opened_at, target_date';

    /**
     * Posizioni da inviare: aperte, avviate, non scadute (v_public_open_positions) e con stato sito ≠ «off» (v1.10.18).
     * web_status: publish = visibile | draft = bozza sul sito (non visibile, anteprima) | off = non pubblicare / ritirare.
     */
    public function openPositions(): array
    {
        $rows = $this->pdo->query("SELECT v.*, COALESCE(jp.web_status, 'publish') AS web_status
                                     FROM (SELECT " . self::ITEM_COLS . " FROM v_public_open_positions) v JOIN job_positions jp ON jp.id = v.id
                                    WHERE COALESCE(jp.web_status, 'publish') <> 'off'
                                    ORDER BY v.opened_at DESC, v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'item'], $rows);
    }

    public static function item(array $r): array
    {
        $r['id'] = (int)$r['id']; $r['positions_expected'] = (int)$r['positions_expected'];
        $r['code'] = 'POS-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
        foreach ($r as $k => $v) if (is_string($v)) $r[$k] = trim($v);
        if (!in_array($r['web_status'] ?? 'publish', ['publish', 'draft'], true)) $r['web_status'] = 'publish';
        return $r;
    }

    /** v1.10.18 — Posizione qualsiasi (anche non aperta) come item per l'anteprima. */
    public function positionItem(int $posId): ?array
    {
        $st = $this->pdo->prepare("SELECT " . self::ITEM_COLS . ", web_status, status FROM job_positions WHERE id = ?");
        $st->execute([$posId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::item($r) : null;
    }

    /**
     * v1.10.18 — Invio puntuale della singola posizione (delta): pubblica/aggiorna se inviabile, altrimenti ritira.
     * Non tocca le altre posizioni del sito.
     */
    public function pushOne(int $posId, ?int $userId = null): array
    {
        $id = $this->start('push', 'manuale', $userId);
        if (!$this->c) return $this->finish($id, false, 'Configurazione incompleta');
        if (!$this->lock()) return $this->finish($id, false, 'Sincronizzazione già in corso');
        try {
            $item = null;
            foreach ($this->openPositions() as $it) if ($it['id'] === $posId) { $item = $it; break; }
            $r = $this->c->request('POST', '/sync/jobs', ['mode' => 'delta', 'items' => $item ? [$item] : [], 'closed' => $item ? [] : [$posId]]);
            if ($r['status'] !== 200 || empty($r['json']['ok'])) return $this->finish($id, false, WpAtsClient::describe($r), ['jobs_sent' => $item ? 1 : 0]);
            $j = $r['json'];
            $this->recordPublications((array)($j['map'] ?? []), $userId, false, $item ? [] : [$posId]);
            $code = 'POS-' . str_pad((string)$posId, 4, '0', STR_PAD_LEFT);
            $msg = $item ? sprintf('%s %s sul sito (%s)', $code, $item['web_status'] === 'draft' ? 'salvata come bozza' : 'pubblicata', $j['created'] ? 'nuova' : ($j['updated'] ? 'aggiornata' : 'invariata'))
                         : sprintf('%s ritirata dal sito%s', $code, $j['withdrawn'] ? '' : ' (non era pubblicata)');
            $this->log($msg);
            return $this->finish($id, empty($j['errors']), $msg, ['jobs_sent' => $item ? 1 : 0, 'jobs_created' => (int)$j['created'], 'jobs_updated' => (int)$j['updated'], 'jobs_withdrawn' => (int)$j['withdrawn']], $j);
        } finally { $this->unlock(); }
    }

    /**
     * v1.10.18 — Anteprima della scheda sul sito (plugin ≥ 1.2.0): URL temporaneo generato dal plugin con i dati attuali.
     * @return array{ok:bool, url?:string, expires?:int, post_url?:?string, message:string, code?:string}
     */
    public function preview(int $posId): array
    {
        if (!$this->c) return ['ok' => false, 'message' => 'Configurazione incompleta'];
        $item = $this->positionItem($posId);
        if (!$item) return ['ok' => false, 'message' => 'Posizione non trovata'];
        $r = $this->c->request('POST', '/sync/preview', ['item' => $item]);
        if ($r['status'] === 200 && !empty($r['json']['ok']) && !empty($r['json']['url']))
            return ['ok' => true, 'url' => (string)$r['json']['url'], 'expires' => (int)($r['json']['expires'] ?? 0), 'post_url' => $r['json']['post_url'] ?? null, 'message' => 'ok'];
        $a = WpAtsClient::analyze($r);
        return ['ok' => false, 'code' => $a['code'], 'message' => WpAtsClient::describe($r)];
    }

    private function recordPublications(array $map, ?int $userId, bool $full = true, array $removed = []): void
    {
        $live = [];
        $sel = $this->pdo->prepare("SELECT id FROM position_publications WHERE position_id = ? AND channel = 'wordpress' ORDER BY id DESC LIMIT 1");
        // v1.10.18 — stato effettivo restituito dal plugin: publish → published, draft → draft (bozza sul sito)
        $upd = $this->pdo->prepare("UPDATE position_publications SET channel_url = ?, api_post_id = ?, status = ?,
                                           published_at = CASE WHEN ? = 'published' THEN COALESCE(IF(status = 'published', published_at, NULL), NOW()) ELSE published_at END WHERE id = ?");
        $ins = $this->pdo->prepare("INSERT INTO position_publications (position_id, channel, channel_url, status, published_at, published_by, api_post_id, notes)
                                    VALUES (?, 'wordpress', ?, ?, IF(? = 'published', NOW(), NULL), ?, ?, 'Sito web aziendale (plugin pm-ats)')");
        foreach ($map as $m) {
            $pid = (int)($m['id'] ?? 0); if ($pid <= 0) continue;
            $live[] = $pid;
            $st = ($m['status'] ?? 'publish') === 'draft' ? 'draft' : 'published';
            $url = mb_substr((string)($m['url'] ?? ''), 0, 500); $post = 'wp:' . (int)($m['post_id'] ?? 0);
            $sel->execute([$pid]); $pubId = (int)$sel->fetchColumn(); $sel->closeCursor();
            $pubId ? $upd->execute([$url, $post, $st, $st, $pubId]) : $ins->execute([$pid, $url, $st, $st, $userId, $post]);
        }
        if ($full) {
            $q = "UPDATE position_publications SET status = 'removed' WHERE channel = 'wordpress' AND status IN ('published','draft')";
            if ($live) $q .= ' AND position_id NOT IN (' . implode(',', array_map('intval', $live)) . ')';
            $this->pdo->exec($q);
        } elseif ($removed) {
            $this->pdo->exec("UPDATE position_publications SET status = 'removed' WHERE channel = 'wordpress' AND status IN ('published','draft') AND position_id IN ("
                             . implode(',', array_map('intval', $removed)) . ')');
        }
    }

    /* ── candidature ─────────────────────────────────────────────────── */

    /**
     * Importa una candidatura del sito.
     * @return array{ack:?array, error?:string}  ack null = errore temporaneo (non confermare: si riprova)
     */
    public function importOne(array $a, ?int $userId = null): array
    {
        $uuid = strtolower((string)($a['uuid'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $uuid)) return ['ack' => null, 'error' => 'uuid non valido'];
        $nak = fn(string $e) => ['ack' => ['uuid' => $uuid, 'ok' => false, 'error' => $e]];

        // già importata: si riconferma con gli stessi id
        $st = $this->pdo->prepare("SELECT candidate_id, application_id FROM wp_ats_imports WHERE wp_uuid = ? AND status = 'imported'");
        $st->execute([$uuid]);
        if ($prev = $st->fetch(PDO::FETCH_ASSOC))
            return ['ack' => ['uuid' => $uuid, 'ok' => true, 'pm_candidate_id' => (int)$prev['candidate_id'], 'pm_application_id' => $prev['application_id'] !== null ? (int)$prev['application_id'] : null]];

        $email = strtolower(trim((string)($a['email'] ?? '')));
        $first = trim((string)($a['first_name'] ?? '')); $last = trim((string)($a['last_name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $nak('invalid_email');
        if ($first === '' || $last === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) return $nak('invalid_name');
        if ((int)($a['consent_privacy'] ?? 0) !== 1) return $nak('privacy_consent_missing');

        // posizione (se la posizione non esiste più la candidatura diventa spontanea, con nota)
        $posId = isset($a['pm_position_id']) ? (int)$a['pm_position_id'] : 0; $posNote = '';
        if ($posId > 0) {
            $q = $this->pdo->prepare("SELECT id FROM job_positions WHERE id = ?"); $q->execute([$posId]);
            if (!$q->fetchColumn()) { $posNote = 'Posizione #' . $posId . ' (' . ($a['position_title'] ?? '') . ') non più presente: importata come spontanea. '; $posId = 0; }
        }

        // CV: scaricato e verificato PRIMA di scrivere nel database
        $cv = null;
        if (!empty($a['cv'])) {
            $r = $this->c->request('GET', '/sync/applications/' . $uuid . '/cv');
            if ($r['status'] === 404) return $nak('cv_not_found_on_site');
            if ($r['status'] !== 200 || $r['body'] === '') return ['ack' => null, 'error' => 'CV ' . substr($uuid, 0, 8) . ': ' . WpAtsClient::describe($r)];
            $cv = $this->checkCv($r['body'], (array)$a['cv'], (string)($r['headers']['x-pm-sha256'] ?? ''));
            if (isset($cv['error'])) return in_array($cv['error'], ['cv_checksum_mismatch', 'cv_size_mismatch'], true)
                ? ['ack' => null, 'error' => 'CV ' . substr($uuid, 0, 8) . ': download incompleto, si riprova'] : $nak($cv['error']);
        } else return $nak('cv_missing');

        $ip = filter_var((string)($a['ip'] ?? ''), FILTER_VALIDATE_IP) ? @inet_pton((string)$a['ip']) : null;
        $ua = mb_substr((string)($a['user_agent'] ?? ''), 0, 255);
        $consentDate = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($a['consent_at'] ?? '')) ? substr((string)$a['consent_at'], 0, 10) : date('Y-m-d');
        $ral = null;
        if (($s = trim((string)($a['salary_expectation'] ?? ''))) !== '') { $n = str_replace([' ', '€', '.', ','], ['', '', '', '.'], $s); if (is_numeric($n)) $ral = (float)$n; }
        $linkedin = filter_var((string)($a['linkedin_url'] ?? ''), FILTER_VALIDATE_URL) ? mb_substr((string)$a['linkedin_url'], 0, 255) : '';
        $phone = mb_substr(trim((string)($a['phone'] ?? '')), 0, 30);
        $city = mb_substr(trim((string)($a['city'] ?? '')), 0, 120);
        $notice = mb_substr(trim((string)($a['availability'] ?? '')), 0, 50);
        $source = (string)($this->setting('careers.public_source_tag') ?: 'Portale');
        if (!in_array($source, ['Agenzia', 'LinkedIn', 'Referral', 'Portale', 'Altro'], true)) $source = 'Portale';
        $ref = 'wp:' . substr($uuid, 0, 8);
        $storeRel = trim((string)($this->setting('careers.storage_path') ?: 'uploads/cv_imports'), '/');
        $root = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
        $storeAbs = $root . '/' . $storeRel;
        if (!is_dir($storeAbs) && !@mkdir($storeAbs, 0750, true) && !is_dir($storeAbs)) return ['ack' => null, 'error' => 'Cartella CV non scrivibile: ' . $storeRel];
        $written = [];

        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("SELECT id FROM candidates WHERE LOWER(TRIM(email)) = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $q->execute([$email]); $candId = (int)$q->fetchColumn(); $isNew = $candId === 0;
            if ($isNew) {
                $this->pdo->prepare("INSERT INTO candidates (first_name, last_name, email, phone, linkedin_url, notice_period, ral_requested, city, source,
                                        gdpr_consent, gdpr_date, consent_marketing, status, submitted_ip, submitted_ua, submitted_ref, added_by, created_at, applied_at)
                                     VALUES (?,?,?,?,?,?,?,?,?, 1, ?, ?, 'new', ?, ?, ?, ?, NOW(), ?)")
                    ->execute([$first, $last, $email, $phone ?: null, $linkedin ?: null, $notice ?: null, $ral, $city ?: null, $source,
                               $consentDate, (int)($a['consent_marketing'] ?? 0), $ip, $ua, substr($uuid, 0, 32), $userId, substr((string)($a['created_at'] ?? ''), 0, 10) ?: date('Y-m-d')]);
                $candId = (int)$this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare("UPDATE candidates SET first_name = ?, last_name = ?,
                                        phone = COALESCE(NULLIF(?, ''), phone), linkedin_url = COALESCE(NULLIF(?, ''), linkedin_url),
                                        notice_period = COALESCE(NULLIF(?, ''), notice_period), ral_requested = COALESCE(?, ral_requested),
                                        city = COALESCE(NULLIF(?, ''), city), gdpr_consent = 1, gdpr_date = ?, consent_marketing = ?,
                                        submitted_ip = ?, submitted_ua = ?, submitted_ref = ?, applied_at = CURDATE(),
                                        status = IF(status IN ('rejected','withdrawn'), 'new', status)
                                     WHERE id = ?")
                    ->execute([$first, $last, $phone, $linkedin, $notice, $ral, $city, $consentDate, (int)($a['consent_marketing'] ?? 0), $ip, $ua, substr($uuid, 0, 32), $candId]);
            }

            // CV
            $ts = time();
            $file = sprintf('cand_%d_cv_%d_%s.%s', $candId, $ts, substr($uuid, 0, 6), $cv['ext']);
            if (@file_put_contents("$storeAbs/$file", $cv['bytes'], LOCK_EX) === false) throw new RuntimeException('Scrittura CV non riuscita');
            @chmod("$storeAbs/$file", 0640); $written[] = "$storeAbs/$file";
            $rel = preg_replace('#^uploads/#', '', $storeRel) . '/' . $file;
            $orig = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string)($a['cv']['name'] ?? 'cv.' . $cv['ext']))) ?: 'cv.' . $cv['ext'];
            $this->pdo->prepare("INSERT INTO candidate_documents (candidate_id, doc_type, file_path, original_filename, file_size, mime_type, description, uploaded_by, uploaded_at)
                                 VALUES (?, 'cv', ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$candId, $rel, mb_substr($orig, 0, 255), strlen($cv['bytes']), $cv['mime'], 'Sito web — ' . ($a['position_title'] ?? '') . ' — rif. ' . strtoupper(substr($uuid, 0, 8)), $userId]);
            $docId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE candidates SET cv_path = ? WHERE id = ?")->execute(['uploads/' . $rel, $candId]);

            // presentazione
            $cover = trim((string)($a['cover_letter'] ?? ''));
            if ($cover !== '') {
                $lf = sprintf('cand_%d_lettera_%d_%s.txt', $candId, $ts, substr($uuid, 0, 6));
                if (@file_put_contents("$storeAbs/$lf", $cover, LOCK_EX) !== false) {
                    @chmod("$storeAbs/$lf", 0640); $written[] = "$storeAbs/$lf";
                    $this->pdo->prepare("INSERT INTO candidate_documents (candidate_id, doc_type, file_path, original_filename, file_size, mime_type, description, uploaded_by, uploaded_at)
                                         VALUES (?, 'lettera', ?, 'presentazione.txt', ?, 'text/plain', ?, ?, NOW())")
                        ->execute([$candId, preg_replace('#^uploads/#', '', $storeRel) . '/' . $lf, strlen($cover), 'Presentazione dal sito web — rif. ' . strtoupper(substr($uuid, 0, 8)), $userId]);
                }
            }

            // candidatura (o nota per la spontanea)
            $appId = null;
            if ($posId > 0) {
                $q = $this->pdo->prepare("SELECT id, stage FROM candidate_applications WHERE candidate_id = ? AND position_id = ?");
                $q->execute([$candId, $posId]); $prev = $q->fetch(PDO::FETCH_ASSOC);
                if ($prev && in_array($prev['stage'], self::ACTIVE, true)) $appId = (int)$prev['id'];       // già in selezione: invariata
                elseif ($prev) {
                    $this->pdo->prepare("UPDATE candidate_applications SET stage = 'cv_received', rejection_reason = NULL, source_channel = 'careers_portal',
                                            submitted_ip = ?, submitted_ua = ?, api_request_id = ? WHERE id = ?")->execute([$ip, $ua, substr($uuid, 0, 32), (int)$prev['id']]);
                    $appId = (int)$prev['id'];
                } else {
                    $this->pdo->prepare("INSERT INTO candidate_applications (candidate_id, position_id, stage, source_channel, submitted_ip, submitted_ua, api_request_id, created_at)
                                         VALUES (?, ?, 'cv_received', 'careers_portal', ?, ?, ?, NOW())")->execute([$candId, $posId, $ip, $ua, substr($uuid, 0, 32)]);
                    $appId = (int)$this->pdo->lastInsertId();
                }
                $this->pdo->prepare("UPDATE candidates SET status = 'in_pipeline' WHERE id = ? AND status = 'new'")->execute([$candId]);
            }
            $note = date('d/m/Y') . ' — ' . ($posId > 0 ? 'Candidatura dal sito web per «' . ($a['position_title'] ?? '') . '»' : 'Candidatura spontanea dal sito web')
                  . ' (rif. ' . strtoupper(substr($uuid, 0, 8)) . ', informativa v' . ($a['privacy_version'] ?? '?') . '). ' . $posNote;
            $this->pdo->prepare("UPDATE candidates SET notes = CONCAT_WS('\n', NULLIF(notes, ''), ?) WHERE id = ?")->execute([trim($note), $candId]);

            $this->pdo->prepare("INSERT INTO wp_ats_imports (wp_uuid, wp_app_id, candidate_id, application_id, position_id, document_id, status, error, imported_at)
                                 VALUES (?, ?, ?, ?, ?, ?, 'imported', NULL, NOW())
                                 ON DUPLICATE KEY UPDATE candidate_id = VALUES(candidate_id), application_id = VALUES(application_id), position_id = VALUES(position_id),
                                                         document_id = VALUES(document_id), status = 'imported', error = NULL, imported_at = NOW()")
                ->execute([$uuid, (int)($a['id'] ?? 0), $candId, $appId, $posId ?: null, $docId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($written as $f) @unlink($f);
            error_log('[WpAtsSync::importOne] ' . $e->getMessage());
            return ['ack' => null, 'error' => 'Import ' . substr($uuid, 0, 8) . ': errore del database'];
        }
        if (function_exists('write_log'))
            write_log('Recruiting', 'success', sprintf('Candidatura dal sito importata: candidato #%d%s (rif. %s)', $candId, $appId ? ", candidatura #$appId" : ' (spontanea)', strtoupper(substr($uuid, 0, 8))), $userId);
        return ['ack' => ['uuid' => $uuid, 'ok' => true, 'pm_candidate_id' => $candId, 'pm_application_id' => $appId]];
    }

    /** @return array{ext?:string,mime?:string,bytes?:string,error?:string} */
    private function checkCv(string $bytes, array $meta, string $hdrSha): array
    {
        // limite del sito (impostazioni del plugin) con tetto di sicurezza a 20 MB
        if (strlen($bytes) === 0 || strlen($bytes) > 20 * 1048576) return ['error' => 'cv_too_large'];
        if (isset($meta['size']) && (int)$meta['size'] !== strlen($bytes)) return ['error' => 'cv_size_mismatch'];
        $sha = hash('sha256', $bytes);
        if (!empty($meta['sha256']) && !hash_equals(strtolower((string)$meta['sha256']), $sha)) return ['error' => 'cv_checksum_mismatch'];
        if ($hdrSha !== '' && !hash_equals(strtolower($hdrSha), $sha)) return ['error' => 'cv_checksum_mismatch'];
        $ext = array_search((string)($meta['mime'] ?? ''), array_map(fn($x) => $x[0], self::CV), true);
        if ($ext === false) return ['error' => 'cv_bad_type'];
        $real = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if (!in_array($real, self::CV[$ext][1], true) || !str_starts_with($bytes, self::CV[$ext][2])) return ['error' => 'cv_bad_type'];
        return ['ext' => (string)$ext, 'mime' => self::CV[$ext][0], 'bytes' => $bytes];
    }

    /* ── supporto ────────────────────────────────────────────────────── */

    private function setting(string $k): ?string
    {
        $q = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?"); $q->execute([$k]);
        $v = $q->fetchColumn(); return $v === false ? null : (string)$v;
    }

    private function lock(): bool
    {
        return (int)$this->pdo->query("SELECT GET_LOCK('pm_wpats_sync', 0)")->fetchColumn() === 1;
    }

    private function unlock(): void
    {
        try { $this->pdo->query("SELECT RELEASE_LOCK('pm_wpats_sync')")->fetchAll(); } catch (Throwable $e) {}
    }

    private function start(string $op, string $trigger, ?int $userId): int
    {
        try {
            $this->pdo->prepare("INSERT INTO wp_ats_sync_log (operation, trigger_type, status, started_at, user_id) VALUES (?, ?, 'running', NOW(), ?)")
                ->execute([$op, in_array($trigger, ['manuale', 'pianificata', 'modifica'], true) ? $trigger : 'manuale', $userId]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) { return 0; }
    }

    private function finish(int $id, bool $ok, string $msg, array $cnt = [], ?array $data = null, ?string $status = null): array
    {
        if ($id) {
            $cols = ['jobs_sent', 'jobs_created', 'jobs_updated', 'jobs_withdrawn', 'apps_fetched', 'apps_imported', 'apps_failed'];
            $set = []; $args = [$status ?? ($ok ? 'ok' : 'error'), mb_substr($msg, 0, 1000)];
            foreach ($cols as $c) if (isset($cnt[$c])) { $set[] = "$c = ?"; $args[] = (int)$cnt[$c]; }
            $args[] = $id;
            try { $this->pdo->prepare("UPDATE wp_ats_sync_log SET status = ?, message = ?, finished_at = NOW()" . ($set ? ', ' . implode(', ', $set) : '') . " WHERE id = ?")->execute($args); }
            catch (Throwable $e) {}
        }
        return ['ok' => $ok, 'message' => $msg, 'data' => $data] + $cnt;
    }
}
