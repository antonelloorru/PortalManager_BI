<?php
/**
 * AlertEngine — rilevazione e invio degli alert sulle commesse.
 *
 * Due fasi distinte e invocabili separatamente:
 *
 *   rileva()  confronta la situazione con le regole e registra gli eventi nuovi
 *   invia()   raggruppa gli eventi non inviati per destinatario e li spedisce
 *
 * Separarle permette di rilevare senza inviare — che e' la modalita' con cui si
 * verifica la configurazione prima di accendere il canale — e di reinviare dopo
 * un errore SMTP senza ririlevare.
 */

declare(strict_types=1);

final class AlertEngine
{
    private PDO $pdo;
    private array $cfg = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->cfg = $this->impostazioni();
    }

    /** Le impostazioni del canale, lette una volta. */
    private function impostazioni(): array
    {
        $out = [];
        try {
            $st = $this->pdo->query(
                "SELECT `setting_key`, `setting_value` FROM `app_settings`
                  WHERE `setting_key` LIKE 'alert_%' OR `setting_key` LIKE 'smtp_%'
                     OR `setting_key` IN ('mail_from','mail_from_name')");
            while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                $out[$r['setting_key']] = $r['setting_value'];
            }
            $st->closeCursor();
        } catch (Throwable $e) { /* valori predefiniti sotto */ }
        return $out;
    }

    private function s(string $k, string $def = ''): string
    {
        return isset($this->cfg[$k]) && $this->cfg[$k] !== '' ? (string)$this->cfg[$k] : $def;
    }

    /**
     * FASE 1 — rilevazione.
     *
     * `INSERT IGNORE` sulla firma: se la condizione era gia' stata rilevata e non
     * e' cambiata di fascia, la riga esiste e non se ne crea una seconda. E' il
     * meccanismo che impedisce di reinviare lo stesso alert ogni giorno.
     *
     * Gli eventi la cui condizione non si verifica piu' vengono CHIUSI, non
     * cancellati: la storia serve a capire quanto e' durato un problema.
     */
    public function rileva(bool $dryRun = false): array
    {
        $attive = $this->pdo->query(
            "SELECT `code` FROM `cm_alert_rules` WHERE `is_active` = 1 AND `kind` <> 'cadenzato'")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!$attive) return ['nuovi' => 0, 'gia_noti' => 0, 'chiusi' => 0, 'regole' => 0];

        $ph = implode(',', array_fill(0, count($attive), '?'));
        $st = $this->pdo->prepare(
            "SELECT * FROM `v_cm_alert_da_rilevare` WHERE `rule_code` IN ($ph)");
        $st->execute($attive);
        $correnti = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();

        $nuovi = 0; $noti = 0; $firme = [];
        if (!$dryRun) {
            $ins = $this->pdo->prepare(
                "INSERT IGNORE INTO `cm_alert_events`
                    (`rule_code`, `project_code`, `project_id`, `agent_name`, `severity`,
                     `signature`, `metric_value`, `threshold`, `message`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        }
        foreach ($correnti as $c) {
            $firme[] = $c['signature'];
            if ($dryRun) { $nuovi++; continue; }
            $ins->execute([$c['rule_code'], $c['project_code'], $c['project_id'],
                $c['agent_name'], $c['severity'], $c['signature'],
                $c['metric_value'], $c['threshold'], $c['message']]);
            if ($ins->rowCount() > 0) $nuovi++; else $noti++;
        }

        // chiusura degli eventi risolti: la condizione non compare piu' fra le
        // correnti, quindi la situazione e' rientrata
        $chiusi = 0;
        if (!$dryRun) {
            if ($firme) {
                $ph2 = implode(',', array_fill(0, count($firme), '?'));
                $up = $this->pdo->prepare(
                    "UPDATE `cm_alert_events` SET `resolved_at` = NOW()
                      WHERE `resolved_at` IS NULL AND `signature` NOT IN ($ph2)");
                $up->execute($firme);
            } else {
                $up = $this->pdo->prepare(
                    "UPDATE `cm_alert_events` SET `resolved_at` = NOW() WHERE `resolved_at` IS NULL");
                $up->execute();
            }
            $chiusi = $up->rowCount();
        }

        return ['nuovi' => $nuovi, 'gia_noti' => $noti, 'chiusi' => $chiusi,
                'regole' => count($attive), 'correnti' => count($correnti)];
    }

    /**
     * FASE 2 — invio.
     *
     * Gli eventi si raggruppano PER DESTINATARIO, non per evento: un agente con
     * dodici commesse critiche riceve una email con dodici righe, non dodici
     * email. La seconda forma si chiama posta indesiderata anche quando ogni
     * singolo messaggio e' legittimo.
     */
    public function invia(bool $dryRun = null): array
    {
        $dryRun = $dryRun ?? ($this->s('alert_dry_run', '1') === '1');
        $attivo = $this->s('alert_enabled', '0') === '1';
        $tetto  = max(1, (int)$this->s('alert_max_per_run', '50'));

        if (!$attivo && !$dryRun) {
            return ['stato' => 'disattivato', 'inviate' => 0, 'errori' => 0, 'eventi' => 0];
        }

        $st = $this->pdo->query(
            "SELECT e.*, r.`label` AS regola_label, r.`to_agent`, r.`to_director`
               FROM `cm_alert_events` e
               JOIN `cm_alert_rules` r ON r.`code` = e.`rule_code`
              WHERE e.`sent` = 0 AND e.`resolved_at` IS NULL AND r.`is_active` = 1
              ORDER BY FIELD(e.`severity`,'critico','allarme','attenzione'), e.`detected_at`");
        $eventi = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$eventi) return ['stato' => 'nulla da inviare', 'inviate' => 0, 'errori' => 0, 'eventi' => 0];

        // destinatari per agente
        $dest = [];
        foreach ($this->pdo->query(
            "SELECT `agent_name`, `email`, `cc_email` FROM `cm_alert_recipients` WHERE `is_active` = 1")
            as $r) { $dest[$r['agent_name']] = $r; }

        $direttore = $this->s('alert_director_email');

        // raggruppamento per destinatario
        $per = [];
        foreach ($eventi as $e) {
            if ((int)$e['to_agent'] === 1 && !empty($dest[$e['agent_name']]['email'])) {
                $k = $dest[$e['agent_name']]['email'];
                $per[$k]['cc']   = $dest[$e['agent_name']]['cc_email'] ?? null;
                $per[$k]['tipo'] = 'agente';
                $per[$k]['nome'] = $e['agent_name'];
                $per[$k]['ev'][] = $e;
            }
            if ((int)$e['to_director'] === 1 && $direttore !== '') {
                $per[$direttore]['cc']   = null;
                $per[$direttore]['tipo'] = 'direttore';
                $per[$direttore]['nome'] = 'Direzione Commerciale';
                $per[$direttore]['ev'][] = $e;
            }
        }
        if (!$per) return ['stato' => 'nessun destinatario configurato',
                           'inviate' => 0, 'errori' => 0, 'eventi' => count($eventi)];

        $inviate = 0; $errori = 0; $idInviati = [];
        foreach ($per as $email => $g) {
            if ($inviate + $errori >= $tetto) break;

            [$sub, $html, $text] = $this->componi($g);
            $esito = $dryRun ? ['ok' => true, 'err' => null]
                             : $this->spedisci($email, $g['cc'] ?? null, $sub, $html, $text);

            $this->pdo->prepare(
                "INSERT INTO `cm_alert_sent`
                    (`rule_code`, `kind`, `recipient`, `cc`, `subject`, `events_count`, `status`, `error_msg`)
                 VALUES (NULL, 'evento', ?, ?, ?, ?, ?, ?)")
                ->execute([$email, $g['cc'] ?? null, $sub, count($g['ev']),
                           $dryRun ? 'simulata' : ($esito['ok'] ? 'inviata' : 'errore'),
                           $esito['err']]);

            if ($esito['ok']) { $inviate++; if (!$dryRun) foreach ($g['ev'] as $e) $idInviati[] = (int)$e['id']; }
            else $errori++;
        }

        // marcatura solo degli eventi effettivamente spediti
        if ($idInviati) {
            $ph = implode(',', array_fill(0, count($idInviati), '?'));
            $this->pdo->prepare("UPDATE `cm_alert_events` SET `sent` = 1 WHERE `id` IN ($ph)")
                ->execute($idInviati);
        }

        return ['stato' => $dryRun ? 'simulazione' : 'inviato', 'inviate' => $inviate,
                'errori' => $errori, 'eventi' => count($eventi), 'destinatari' => count($per)];
    }

    /** Compone il messaggio: una email per destinatario con tutte le sue righe. */
    private function componi(array $g): array
    {
        $ev = $g['ev'];
        $crit = 0; foreach ($ev as $e) if ($e['severity'] === 'critico') $crit++;

        $sub = sprintf('[Commesse] %d segnalazion%s%s — %s',
            count($ev), count($ev) === 1 ? 'e' : 'i',
            $crit > 0 ? sprintf(', di cui %d critic%s', $crit, $crit === 1 ? 'a' : 'he') : '',
            $g['nome']);

        $colore = ['critico' => '#dc2626', 'allarme' => '#ea580c', 'attenzione' => '#f59e0b'];
        $righe = ''; $txt = '';
        foreach ($ev as $e) {
            $c = $colore[$e['severity']] ?? '#64748b';
            $righe .= '<tr>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0">'
                . '<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:'
                . $c . ';margin-right:6px"></span>'
                . htmlspecialchars((string)$e['severity']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;font-family:monospace">'
                . htmlspecialchars((string)$e['project_code']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0">'
                . htmlspecialchars((string)$e['regola_label']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0">'
                . htmlspecialchars((string)$e['message']) . '</td>'
                . '</tr>';
            $txt .= sprintf("- [%s] %s — %s: %s\n", $e['severity'], $e['project_code'],
                $e['regola_label'], $e['message']);
        }

        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1e293b">'
            . '<h2 style="font-size:16px;margin:0 0 4px">Segnalazioni sulle commesse</h2>'
            . '<p style="color:#64748b;font-size:12px;margin:0 0 12px">'
            . htmlspecialchars((string)$g['nome']) . ' — ' . date('d/m/Y H:i') . '</p>'
            . '<table style="border-collapse:collapse;font-size:13px;width:100%">'
            . '<thead><tr style="background:#f1f5f9">'
            . '<th style="text-align:left;padding:6px 8px">Gravità</th>'
            . '<th style="text-align:left;padding:6px 8px">Commessa</th>'
            . '<th style="text-align:left;padding:6px 8px">Motivo</th>'
            . '<th style="text-align:left;padding:6px 8px">Dettaglio</th>'
            . '</tr></thead><tbody>' . $righe . '</tbody></table>'
            // il vincolo dichiarato nel messaggio: chi riceve deve sapere che non
            // verra' ripetuto, altrimenti aspetta un promemoria che non arriva
            . '<p style="color:#64748b;font-size:11px;margin-top:14px">'
            . 'Ogni segnalazione viene inviata una sola volta per ciascun livello di soglia: '
            . 'non riceverà un promemoria finché la situazione non cambia. '
            . 'Le segnalazioni rientrate vengono chiuse automaticamente.</p></div>';

        $text = "Segnalazioni sulle commesse — " . $g['nome'] . " — " . date('d/m/Y H:i') . "\n\n" . $txt
            . "\nOgni segnalazione viene inviata una sola volta per livello di soglia.\n";

        return [$sub, $html, $text];
    }

    /** Invio tramite il canale SMTP gia' configurato nel portale. */
    private function spedisci(string $to, ?string $cc, string $sub, string $html, string $text): array
    {
        try {
            require_once(__DIR__ . '/SmtpMailer.php');
            $m = new SmtpMailer();
            $m->configure([
                'host' => $this->s('smtp_host'), 'port' => (int)$this->s('smtp_port', '465'),
                'user' => $this->s('smtp_user'), 'pass' => $this->s('smtp_pass'),
                'encryption' => $this->s('smtp_encryption', 'ssl'),
                'auth' => $this->s('smtp_auth', '1') === '1',
                'timeout' => (int)$this->s('smtp_timeout', '15'),
            ]);
            // l'ALIAS, se impostato, altrimenti il mittente di sistema: le
            // comunicazioni sulle commesse devono essere riconoscibili
            // nell'intestazione e separabili da un filtro sulla casella
            $m->from($this->s('alert_alias_email', $this->s('mail_from')),
                     $this->s('alert_alias_name', $this->s('mail_from_name', 'PortalManager')));
            $m->to($to);
            if ($cc) $m->cc($cc);
            $m->subject($sub)->bodyHtml($html)->bodyText($text);
            $ok = $m->send();
            return ['ok' => (bool)$ok, 'err' => $ok ? null : 'invio non riuscito'];
        } catch (Throwable $e) {
            return ['ok' => false, 'err' => mb_substr($e->getMessage(), 0, 400)];
        }
    }

    /** Stato per il pannello. */
    public function stato(): array
    {
        try {
            $r = $this->pdo->query("SELECT * FROM `v_cm_alert_stato`")->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $r = []; }
        $r['attivo']  = $this->s('alert_enabled', '0') === '1';
        $r['dry_run'] = $this->s('alert_dry_run', '1') === '1';
        $r['alias']   = $this->s('alert_alias_email', $this->s('mail_from'));
        $r['alias_nome'] = $this->s('alert_alias_name', 'PortalManager');
        $r['direttore']  = $this->s('alert_director_email');
        return $r;
    }
}
