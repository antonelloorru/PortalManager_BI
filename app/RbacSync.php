<?php
/**
 * PortalManager — app/RbacSync.php (v1.9.83)
 *
 * Auto-sync del modello RBAC: catalogo pagine ↔ ruoli ↔ utenti ↔ permessi ↔ menu.
 * Sostituisce le patch manuali (migration con INSERT su role_permissions, $page_map da aggiornare).
 *
 * Quando parte
 *  - da solo, a fine richiesta, quando cambia la «firma» del codice (menu, router, catalogo, versione):
 *    cioè dopo ogni aggiornamento del portale. Controllo per richiesta: una lettura di file;
 *  - dopo la creazione/eliminazione di un ruolo (manage_roles.php);
 *  - a mano da Sistema → Sincronizzazione permessi (rbac_sync.php), anche in simulazione.
 *
 * Cosa fa (tutto idempotente, mai concessioni implicite)
 *  1. catalogo: registra/aggiorna ogni pagina in `permissions` (is_page=1), disattiva quelle sparite;
 *  2. nomi: 'pagina' → 'pagina.php' in role_permissions/user_permissions (con due righe vince il divieto);
 *  3. orfani: permessi e menu di ruoli eliminati, menu di utenti eliminati;
 *  4. matrice esplicita: una riga (tutto negato) per ogni ruolo × pagina attiva → nessun permesso implicito;
 *  5. regole di seeding (`rbac_seed_rules`): concessioni predefinite per le pagine NUOVE, decise
 *     dall'amministratore una volta per tutte (es. «le nuove pagine di Gestione Commesse: vista al
 *     Direttore IT»). Si applicano una sola volta, alla comparsa della pagina;
 *  6. controlli (senza modifiche): voci di menu non servite dal router, permessi in conflitto con
 *     i blocchi codificati, utenti con ruolo inesistente, pagine nuove ancora senza assegnazioni.
 * Ogni esecuzione è registrata in `rbac_sync_log`.
 */

declare(strict_types=1);

final class RbacSync
{
    private const LOCK = 'pm_rbac_sync';
    private const FLAGS = ['can_view', 'can_create', 'can_edit', 'can_delete', 'can_export'];

    /* ── firma del codice ─────────────────────────────────────────────── */

    /** Firma del catalogo definito nel codice (menu, router, pagine curate, blocchi, versione). */
    public static function codeSignature(): string
    {
        require_once __DIR__ . '/PermissionCatalog.php';
        $cat = PermissionCatalog::discover(null);
        ksort($cat);
        $v = defined('PM_VERSION') ? PM_VERSION : (is_file(dirname(__DIR__) . '/VERSION') ? trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')) : '');
        return hash('sha256', json_encode([$cat, $v]));
    }

    private static function stampFile(): string
    {
        $dir = dirname(__DIR__) . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/.rbac_sync_signature';
    }

    /**
     * Da chiamare a ogni richiesta (access_control.php): se la firma del codice è cambiata,
     * pianifica la sincronizzazione a fine richiesta. Costo nel caso normale: lettura di un file.
     */
    public static function autoSync(PDO $pdo): void
    {
        static $done = false;
        if ($done || PHP_SAPI === 'cli') return;
        $done = true;
        try {
            $sig = self::codeSignature();
            $f = self::stampFile();
            if (is_file($f) && trim((string)@file_get_contents($f)) === $sig) return;
            register_shutdown_function(static function () use ($pdo, $sig, $f): void {
                try {
                    if (self::setting($pdo, 'rbac_autosync_enabled', '1') !== '1') return;
                    $r = self::run($pdo, true, 'automatica', null);
                    if (($r['status'] ?? '') === 'ok') @file_put_contents($f, $sig);
                } catch (Throwable $e) { error_log('[RbacSync] ' . $e->getMessage()); }
            });
        } catch (Throwable $e) { /* mai bloccare la richiesta */ }
    }

    /* ── esecuzione ───────────────────────────────────────────────────── */

    /**
     * @param bool $apply false = simulazione (nessuna modifica, stesso report)
     * @return array{status:string, changes:array, warnings:array, stats:array}
     */
    public static function run(PDO $pdo, bool $apply, string $trigger = 'manuale', ?int $userId = null): array
    {
        require_once __DIR__ . '/PermissionCatalog.php';
        $rep = ['status' => 'ok', 'mode' => $apply ? 'applica' : 'simulazione', 'changes' => [], 'warnings' => [], 'stats' => []];
        if ((int)$pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== 1) {
            return ['status' => 'occupato', 'mode' => $rep['mode'], 'changes' => [], 'warnings' => ['Sincronizzazione già in corso.'], 'stats' => []];
        }
        try {
            self::ensureSchema($pdo);
            $cat   = PermissionCatalog::discover($pdo);
            $roles = $pdo->query("SELECT id, name FROM roles ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
            $now   = date('Y-m-d H:i:s');
            $baseline = self::setting($pdo, 'rbac_baseline_at', '');

            if ($apply) $pdo->beginTransaction();

            // 1. catalogo
            $known = [];
            foreach ($pdo->query("SELECT name, is_page, is_active FROM permissions")->fetchAll(PDO::FETCH_ASSOC) as $r) $known[$r['name']] = $r;
            $new = [];
            $up = $pdo->prepare(
                "INSERT INTO permissions (name, label, description, module, is_page, in_menu, in_router, in_curated, icon,
                                          sort_order, hard_gate_max_role, is_active, first_seen_at, last_seen_at)
                 VALUES (?,?,?,?,1,?,?,?,?,?,?,1,?,?)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), module = VALUES(module),
                     is_page = 1, in_menu = VALUES(in_menu), in_router = VALUES(in_router), in_curated = VALUES(in_curated),
                     icon = VALUES(icon), sort_order = VALUES(sort_order), hard_gate_max_role = VALUES(hard_gate_max_role),
                     is_active = 1, last_seen_at = VALUES(last_seen_at),
                     first_seen_at = COALESCE(first_seen_at, VALUES(first_seen_at))");
            foreach ($cat as $f => $c) {
                $isNew = !isset($known[$f]) || (int)$known[$f]['is_page'] !== 1;
                if ($isNew) $new[] = $f;
                elseif ((int)$known[$f]['is_active'] !== 1) $rep['changes'][] = "Pagina riattivata nel catalogo: $f";
                if ($apply) $up->execute([$f, mb_substr($c['label'], 0, 150), $c['description'], mb_substr($c['section'], 0, 100),
                                          $c['in_menu'], $c['in_router'], $c['in_curated'], $c['icon'], $c['sort'],
                                          $c['hard_gate'], $now, $now]);
            }
            if ($new) $rep['changes'][] = count($new) . ' pagine registrate nel catalogo' . ($baseline === '' ? ' (prima sincronizzazione)' : ': ' . implode(', ', array_slice($new, 0, 20)));
            $gone = [];
            foreach ($known as $n => $r) if ((int)$r['is_page'] === 1 && (int)$r['is_active'] === 1 && !isset($cat[$n])) $gone[] = $n;
            if ($gone) {
                $rep['changes'][] = 'Pagine non più presenti nel codice (disattivate, permessi conservati): ' . implode(', ', $gone);
                if ($apply) { $d = $pdo->prepare("UPDATE permissions SET is_active = 0 WHERE name = ?"); foreach ($gone as $g) $d->execute([$g]); }
            }

            // 2. nomi pagina
            foreach (['role_permissions' => 'role_id', 'user_permissions' => 'user_id'] as $tab => $key) {
                $rows = $pdo->query("SELECT * FROM `$tab` WHERE page_name NOT LIKE '%.php' OR page_name <> LOWER(TRIM(page_name))")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $to = PermissionCatalog::normalize((string)$r['page_name']);
                    $rep['changes'][] = "$tab: '{$r['page_name']}' → '$to' ({$key} {$r[$key]}, in caso di conflitto prevale il divieto)";
                    if (!$apply) continue;
                    $st = $pdo->prepare("SELECT * FROM `$tab` WHERE `$key` = ? AND page_name = ?");
                    $st->execute([$r[$key], $to]);
                    $ex = $st->fetch(PDO::FETCH_ASSOC);
                    if ($ex) {
                        $set = []; $args = [];
                        foreach (self::FLAGS as $fl) {
                            $a = $r[$fl]; $b = $ex[$fl];
                            $v = ($a === null) ? $b : (($b === null) ? $a : min((int)$a, (int)$b));
                            $set[] = "`$fl` = ?"; $args[] = $v;
                        }
                        $args[] = $r[$key]; $args[] = $to;
                        $pdo->prepare("UPDATE `$tab` SET " . implode(', ', $set) . " WHERE `$key` = ? AND page_name = ?")->execute($args);
                        $pdo->prepare("DELETE FROM `$tab` WHERE `$key` = ? AND page_name = ?")->execute([$r[$key], $r['page_name']]);
                    } else {
                        $pdo->prepare("UPDATE `$tab` SET page_name = ? WHERE `$key` = ? AND page_name = ?")->execute([$to, $r[$key], $r['page_name']]);
                    }
                }
            }

            // 3. orfani
            $o = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id NOT IN (SELECT id FROM roles)")->fetchColumn();
            if ($o) { $rep['changes'][] = "$o permessi di ruoli eliminati rimossi"; if ($apply) $pdo->exec("DELETE FROM role_permissions WHERE role_id NOT IN (SELECT id FROM roles)"); }
            $o = (int)$pdo->query("SELECT COUNT(*) FROM menu_preferences WHERE (scope_type = 'role' AND scope_id NOT IN (SELECT id FROM roles))
                                     OR (scope_type = 'user' AND scope_id NOT IN (SELECT id FROM users))")->fetchColumn();
            if ($o) { $rep['changes'][] = "$o menu personalizzati di ruoli/utenti eliminati rimossi";
                      if ($apply) $pdo->exec("DELETE FROM menu_preferences WHERE (scope_type = 'role' AND scope_id NOT IN (SELECT id FROM roles))
                                                OR (scope_type = 'user' AND scope_id NOT IN (SELECT id FROM users))"); }

            // 4. matrice esplicita (tutto negato dove manca la riga)
            $pages = array_keys($cat);
            $have = [];
            foreach ($pdo->query("SELECT role_id, page_name FROM role_permissions")->fetchAll(PDO::FETCH_NUM) as [$rid, $pn]) $have[$rid . '|' . $pn] = true;
            $miss = 0;
            $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES (?,?,0,0,0,0,0)");
            foreach ($roles as $rid => $rn) {
                if ((int)$rid === 1) continue;
                foreach ($pages as $p) {
                    if (isset($have[$rid . '|' . $p])) continue;
                    $miss++;
                    if ($apply) $ins->execute([$rid, $p]);
                }
            }
            if ($miss) $rep['changes'][] = "$miss righe di permesso esplicite create (tutto negato: nessun accesso implicito)";

            // 5. regole di seeding sulle pagine nuove (non alla prima sincronizzazione: le pagine esistenti non sono «nuove»)
            $seeded = 0;
            $granted = array_fill_keys($new, false);
            if ($baseline !== '' && $new) {
                $rules = $pdo->query("SELECT * FROM rbac_seed_rules WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($new as $p) {
                    foreach ($rules as $ru) {
                        if (!isset($roles[(int)$ru['role_id']]) || (int)$ru['role_id'] === 1) continue;
                        $match = $ru['scope'] === 'all'
                              || ($ru['scope'] === 'section' && mb_strtolower($cat[$p]['section']) === mb_strtolower((string)$ru['target']))
                              || ($ru['scope'] === 'page' && PermissionCatalog::normalize((string)$ru['target']) === $p);
                        if (!$match) continue;
                        $gate = $cat[$p]['hard_gate'];
                        if ($gate !== null && (int)$ru['role_id'] > $gate) {
                            $rep['warnings'][] = "Regola #{$ru['id']} non applicata a $p: la pagina ammette solo ruoli ≤ $gate";
                            continue;
                        }
                        $seeded++;
                        $granted[$p] = $granted[$p] || (int)$ru['can_view'] === 1;
                        $what = [];
                        foreach (['can_view' => 'vista', 'can_create' => 'crea', 'can_edit' => 'modifica',
                                  'can_delete' => 'elimina', 'can_export' => 'esporta'] as $fl => $lb) if ((int)$ru[$fl] === 1) $what[] = $lb;
                        $rep['changes'][] = "Regola #{$ru['id']}: $p → «{$roles[(int)$ru['role_id']]}» (" . ($what ? implode(', ', $what) : 'nessun permesso') . ')';
                        if ($apply) {
                            $pdo->prepare("INSERT INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export)
                                           VALUES (?,?,?,?,?,?,?)
                                           ON DUPLICATE KEY UPDATE can_view = GREATEST(can_view, VALUES(can_view)),
                                               can_create = GREATEST(can_create, VALUES(can_create)), can_edit = GREATEST(can_edit, VALUES(can_edit)),
                                               can_delete = GREATEST(can_delete, VALUES(can_delete)), can_export = GREATEST(can_export, VALUES(can_export))")
                                ->execute([(int)$ru['role_id'], $p, (int)$ru['can_view'], (int)$ru['can_create'], (int)$ru['can_edit'],
                                           (int)$ru['can_delete'], (int)$ru['can_export']]);
                        }
                    }
                }
            }

            // 6. controlli
            if (class_exists('Router')) {
                foreach ($cat as $f => $c) {
                    if ($c['in_menu'] && !$c['in_router']) $rep['warnings'][] = "Voce di menu fuori da Router::PAGES (URL non anonimizzato, servita per percorso diretto): $f";
                }
            }
            $gates = [];
            foreach ($cat as $f => $c) if ($c['hard_gate'] !== null) $gates[$f] = $c['hard_gate'];
            if ($gates) {
                $st = $pdo->query("SELECT rp.role_id, rp.page_name FROM role_permissions rp WHERE rp.can_view = 1 AND rp.role_id <> 1");
                foreach ($st->fetchAll(PDO::FETCH_NUM) as [$rid, $pn]) {
                    if (isset($gates[$pn]) && (int)$rid > $gates[$pn]) {
                        $rep['warnings'][] = "Permesso senza effetto: «" . ($roles[$rid] ?? "ruolo $rid") . "» su $pn (la pagina ammette solo ruoli ≤ {$gates[$pn]})";
                    }
                }
            }
            foreach ($pdo->query("SELECT u.id, u.email, u.role_id FROM users u LEFT JOIN roles r ON r.id = u.role_id
                                   WHERE u.status = 'active' AND r.id IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $rep['warnings'][] = "Utente attivo con ruolo inesistente: {$u['email']} (ruolo {$u['role_id']}) — nessun accesso finché non viene assegnato un ruolo";
            }
            $orph = (int)$pdo->query("SELECT COUNT(*) FROM user_permissions up LEFT JOIN permissions p ON p.name = up.page_name AND p.is_page = 1
                                        WHERE p.name IS NULL OR p.is_active = 0")->fetchColumn();
            if ($orph) $rep['warnings'][] = "$orph override utente su pagine non più presenti nel catalogo (senza effetto)";
            $unassigned = [];
            if ($baseline !== '') {
                // pagine comparse dopo la prima sincronizzazione che nessun ruolo (oltre al Super Admin) puo' vedere
                $cands = $new;
                $st = $pdo->prepare("SELECT name FROM permissions WHERE is_page = 1 AND is_active = 1 AND first_seen_at > ?");
                $st->execute([$baseline]);
                $cands = array_unique(array_merge($cands, $st->fetchAll(PDO::FETCH_COLUMN)));
                $gv = $pdo->query("SELECT DISTINCT page_name FROM role_permissions WHERE role_id <> 1 AND can_view = 1")->fetchAll(PDO::FETCH_COLUMN);
                $gv = array_flip($gv);
                foreach ($cands as $p) if (!isset($gv[$p]) && empty($granted[$p])) $unassigned[] = $p;
            }
            if ($unassigned) $rep['warnings'][] = 'Pagine nuove visibili solo al Super Admin, da assegnare in Permessi: ' . implode(', ', $unassigned);
            $rep['unassigned'] = $unassigned;

            $rep['stats'] = ['pagine' => count($cat), 'nuove' => count($new), 'ruoli' => count($roles), 'righe_create' => $miss,
                             'regole_applicate' => $seeded, 'avvisi' => count($rep['warnings'])];

            if ($apply) {
                if ($baseline === '') self::saveSetting($pdo, 'rbac_baseline_at', $now);
                self::saveSetting($pdo, 'rbac_last_sync_at', $now);
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($apply && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
            $rep['status'] = 'errore';
            $rep['warnings'][] = 'Errore: ' . $e->getMessage();
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
        self::log($pdo, $trigger, $rep, $userId);
        if (function_exists('write_log') && $apply) {
            write_log('Permissions', $rep['status'] === 'ok' ? 'info' : 'error',
                "Sincronizzazione RBAC ($trigger): " . count($rep['changes']) . ' modifiche, ' . count($rep['warnings']) . ' avvisi', $userId);
        }
        return $rep;
    }

    /** Copia i permessi di un ruolo modello in un ruolo nuovo (creazione ruolo). */
    public static function cloneRole(PDO $pdo, int $fromRole, int $toRole): int
    {
        if ($fromRole === $toRole || $toRole === 1) return 0;
        $st = $pdo->prepare("INSERT INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export)
                             SELECT ?, page_name, can_view, can_create, can_edit, can_delete, can_export
                               FROM role_permissions WHERE role_id = ?
                             ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_create = VALUES(can_create),
                                 can_edit = VALUES(can_edit), can_delete = VALUES(can_delete), can_export = VALUES(can_export)");
        $st->execute([$toRole, $fromRole]);
        return $st->rowCount();
    }

    /* ── supporto ─────────────────────────────────────────────────────── */

    /** Colonne e tabelle necessarie (la migration le crea; qui solo come rete di sicurezza). */
    private static function ensureSchema(PDO $pdo): void
    {
        try { $pdo->query("SELECT is_page, first_seen_at FROM permissions LIMIT 0")->closeCursor(); }
        catch (Throwable $e) { throw new RuntimeException('Schema RBAC non aggiornato: eseguire sql/migration_v1_9_83.sql'); }
    }

    private static function log(PDO $pdo, string $trigger, array $rep, ?int $userId): void
    {
        try {
            $pdo->prepare("INSERT INTO rbac_sync_log (trigger_type, mode, status, changes, warnings, report, user_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$trigger, $rep['mode'], $rep['status'], count($rep['changes']), count($rep['warnings']),
                           json_encode($rep, JSON_UNESCAPED_UNICODE), $userId]);
        } catch (Throwable $e) {}
    }

    public static function setting(PDO $pdo, string $k, string $d = ''): string
    {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
            $st->execute([$k]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null) ? $d : (string)$v;
        } catch (Throwable $e) { return $d; }
    }

    private static function saveSetting(PDO $pdo, string $k, string $v): void
    {
        $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    }
}
