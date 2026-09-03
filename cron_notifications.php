<?php
/**
 * certV 2.4 — cron_notifications.php
 * AGGIORNATO: usa SmtpMailer (indipendente dal SO) al posto di mail()
 * v2.2: query certificazioni usa uc.employee_id → employees
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/SmtpMailer.php';

$settings = load_settings_fresh();
$today    = date('Y-m-d');

$smtp_ok = ($settings['smtp_enabled'] ?? '0') === '1';

$alert_levels = [];
for ($i = 1; $i <= 4; $i++) {
    $days = (int)($settings["notify_days_$i"] ?? 0);
    if ($days > 0) $alert_levels[$i] = $days;
}

echo date('Y-m-d H:i:s') . " — cron v2.4 avviato. SMTP: " . ($smtp_ok ? 'ON' : 'OFF')
   . ". Livelli: " . implode(',', $alert_levels) . "\n";
$processed = ['certs_alerted' => 0, 'certs_expired' => 0, 'contracts_alerted' => 0, 'emails_sent' => 0, 'emails_failed' => 0];

// ── 1. ALERT SCADENZE CERTIFICAZIONI ──────────────────────────────────────────
foreach ($alert_levels as $level => $days) {
    $target_date = date('Y-m-d', strtotime("+$days days"));
    $type = ($days <= 7) ? 'critical' : (($days <= 30) ? 'warning' : 'info');

    $s = $pdo->prepare(
        "SELECT uc.id, uc.expiry_date, uc.status,
                e.id employee_id, e.first_name, e.last_name,
                u.id user_id, u.email, u.notifications_email, u.role_id,
                c.name cert_name, b.name brand_name
         FROM user_certifications uc
         JOIN employees e        ON uc.employee_id = e.id
         JOIN certifications c   ON uc.certification_id = c.id
         JOIN brands b           ON c.brand_id = b.id
         LEFT JOIN users u       ON u.employee_id = e.id AND u.status='active'
         WHERE uc.expiry_date = ?
           AND uc.status IN('active','expiring')
           AND e.status = 'active'"
    );
    $s->execute([$target_date]);
    $certs_due = $s->fetchAll();

    foreach ($certs_due as $cert) {
        $title = "Scadenza certificazione — {$days} giorni";
        $msg   = "{$cert['cert_name']} di {$cert['first_name']} {$cert['last_name']}"
               . " scade il " . date('d/m/Y', strtotime($cert['expiry_date']))
               . " ({$cert['brand_name']})";
        $link  = "report_certificazioni.php";

        // Notifica in-app al dipendente (se ha account)
        if ($cert['user_id']) {
            push_notification($title, $msg, 'asset', $type, $cert['user_id'], null, $link);
        }
        // Notifica ai TL e Brand Manager per ruolo
        push_notification($title, $msg, 'asset', $type, null, 4, $link);
        push_notification($title, $msg, 'asset', $type, null, 3, $link);

        // ── EMAIL via SmtpMailer ──────────────────────────────
        if ($smtp_ok && $cert['user_id'] && $cert['email'] && $cert['notifications_email']) {
            // Carica template personalizzato o usa default
            $custom_msg = $settings["notify_msg_$level"] ?? '';
            if ($custom_msg) {
                $body = str_replace(
                    ['{DIPENDENTE}', '{CERTIFICAZIONE}', '{BRAND}', '{DATA_SCADENZA}'],
                    [
                        $cert['first_name'] . ' ' . $cert['last_name'],
                        $cert['cert_name'],
                        $cert['brand_name'],
                        date('d/m/Y', strtotime($cert['expiry_date'])),
                    ],
                    $custom_msg
                );
            } else {
                $body = "Gentile {$cert['first_name']},\r\n\r\n"
                      . "la certificazione {$cert['cert_name']} ({$cert['brand_name']}) "
                      . "scadrà il " . date('d/m/Y', strtotime($cert['expiry_date'])) . ".\r\n\r\n"
                      . "Accedi al portale per pianificare il rinnovo.\r\n\r\n"
                      . "— " . ($settings['app_name'] ?? 'certV');
            }

            $subject = "[" . ($settings['app_name'] ?? 'certV') . "] Scadenza {$cert['cert_name']} — $days giorni";

            // CC opzionale per livello
            $cc = [];
            $cc_addr = $settings["notify_cc_$level"] ?? '';
            if ($cc_addr && filter_var($cc_addr, FILTER_VALIDATE_EMAIL)) {
                $cc[] = $cc_addr;
            }

            $sent = send_certv_email($cert['email'], $subject, $body, null, $cc, 'asset', $cert['id']);
            $processed[$sent ? 'emails_sent' : 'emails_failed']++;
        }

        // Aggiorna stato a 'expiring' se ancora 'active'
        if ($days <= 90 && ($cert['status'] ?? '') === 'active') {
            $pdo->prepare("UPDATE user_certifications SET status='expiring' WHERE id=?")
                ->execute([$cert['id']]);
        }
        $processed['certs_alerted']++;
    }
}

// ── 2. SEGNA SCADUTE ──────────────────────────────────────────────────────────
$expired_stmt = $pdo->prepare(
    "UPDATE user_certifications SET status='expired'
     WHERE expiry_date < CURDATE() AND status NOT IN('expired','revoked')"
);
$expired_stmt->execute();
$processed['certs_expired'] = $expired_stmt->rowCount();

if ($processed['certs_expired'] > 0) {
    push_notification(
        "Certificazioni scadute aggiornate",
        "{$processed['certs_expired']} certificazioni risultano ora scadute. Verificare la gap analysis.",
        'asset', 'critical', null, 3, 'gap_analysis.php'
    );
    write_log('Cron', 'warning', "Scadute: {$processed['certs_expired']} cert");
}

// ── 3. ALERT CONTRATTI AGENZIE ────────────────────────────────────────────────
$contract_days   = (int)($settings['agency_contract_alert_days'] ?? 60);
$contract_target = date('Y-m-d', strtotime("+$contract_days days"));

$cs = $pdo->prepare(
    "SELECT ac.*, a.name agency_name
     FROM agency_contracts ac
     JOIN agencies a ON ac.agency_id = a.id
     WHERE ac.status='active' AND ac.end_date = ?"
);
$cs->execute([$contract_target]);
$expiring_contracts = $cs->fetchAll();

foreach ($expiring_contracts as $c) {
    $n_title = "Contratto agenzia in scadenza";
    $n_msg   = "Il contratto con {$c['agency_name']} (Rif: {$c['contract_ref']}) scade il "
             . date('d/m/Y', strtotime($c['end_date'])) . ".";

    push_notification($n_title, $n_msg, 'recruiting', 'warning', null, 2,
        "recruiting_contratti.php?f_ag={$c['agency_id']}");

    // Email agli HR Director
    if ($smtp_ok) {
        $hr_emails = $pdo->query(
            "SELECT u.email FROM users u WHERE u.role_id <= 2 AND u.status='active' AND u.notifications_email=1"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($hr_emails as $hr_email) {
            send_certv_email($hr_email, "[certV] Scadenza contratto {$c['agency_name']}", $n_msg,
                null, [], 'recruiting', $c['id']);
        }
    }

    $processed['contracts_alerted']++;
}

// ── 4. ALERT ESAMI PIANIFICATI (con ICS e tracking reminder) ─────────────────
$processed['exams_alerted'] = 0;
$exam_days = [
    (int)($settings['notify_exam_days_1'] ?? 7),
    (int)($settings['notify_exam_days_2'] ?? 1),
];

// Auto-check colonne reminder tracking
$has_reminder_cols = true;
try { $pdo->query("SELECT reminder_7d_sent FROM planned_exams LIMIT 0")->closeCursor(); }
catch (\Exception $e) { $has_reminder_cols = false; }

require_once __DIR__ . '/CalendarHelper.php';

$plan_type_labels = [
    'formazione'=>'Formazione','esame_certificazione'=>'Esame certificazione','rinnovo'=>'Rinnovo',
    'workshop_tecnico'=>'Workshop tecnico','workshop_commerciale'=>'Workshop commerciale','convegno'=>'Convegno',
];

foreach ($exam_days as $days) {
    if ($days <= 0) continue;
    $exam_target = date('Y-m-d', strtotime("+$days days"));
    $type = $days <= 1 ? 'critical' : 'warning';
    $reminder_col = $days <= 1 ? 'reminder_1d_sent' : 'reminder_7d_sent';

    try {
        $es = $pdo->prepare(
            "SELECT pe.id, pe.planned_date, pe.exam_location, pe.exam_center, pe.notes, pe.plan_type,
                    e.id employee_id, e.first_name, e.last_name,
                    u.id user_id, u.email, u.notifications_email,
                    c.name cert_name, b.name brand_name
             FROM planned_exams pe
             JOIN employees e ON pe.employee_id = e.id
             LEFT JOIN certifications c ON pe.certification_id = c.id
             LEFT JOIN brands b ON c.brand_id = b.id
             LEFT JOIN users u ON u.employee_id = e.id AND u.status='active'
             WHERE pe.planned_date = ? AND pe.status = 'planned' AND e.status = 'active'"
            . ($has_reminder_cols ? " AND pe.$reminder_col = 0" : '')
        );
        $es->execute([$exam_target]);
        $exams_due = $es->fetchAll();

        foreach ($exams_due as $exam) {
            $pt_label = $plan_type_labels[$exam['plan_type'] ?? 'esame_certificazione'] ?? 'Evento';
            $cert_info = $exam['cert_name'] ? "{$exam['cert_name']}" . ($exam['brand_name'] ? " ({$exam['brand_name']})" : '') : $pt_label;
            $title = "$pt_label tra {$days} giorn" . ($days === 1 ? 'o' : 'i');
            $msg_text = "$cert_info — {$exam['first_name']} {$exam['last_name']} il "
                      . date('d/m/Y', strtotime($exam['planned_date']));
            if ($exam['exam_center']) $msg_text .= " presso {$exam['exam_center']}";

            // Notifica in-app
            if ($exam['user_id']) {
                push_notification($title, $msg_text, 'asset', $type, $exam['user_id'], null, 'programmazione.php');
            }
            push_notification($title, $msg_text, 'asset', $type, null, 4, 'programmazione.php');

            // Email con ICS allegato
            $to_email = $exam['notifications_email'] ?: $exam['email'] ?: null;
            if ($smtp_ok && $to_email) {
                $location = $exam['exam_center'] ?: ($exam['exam_location'] ?: '');
                $eventTitle = "$pt_label: " . ($exam['cert_name'] ?? $exam['notes'] ?? 'Evento formativo');

                // Genera ICS
                $icsContent = CalendarHelper::generateICS(
                    $eventTitle, $exam['planned_date'], '09:00', 2,
                    $location,
                    "Brand: " . ($exam['brand_name'] ?? 'N/A') . "\nNote: " . ($exam['notes'] ?? ''),
                    $settings['mail_from'] ?? ''
                );

                // Google Calendar link
                $googleUrl = CalendarHelper::googleCalendarUrl(
                    $eventTitle, $exam['planned_date'], '09:00', 2, $location,
                    "Brand: " . ($exam['brand_name'] ?? 'N/A')
                );

                // HTML email
                $portalUrl = rtrim($settings['app_url'] ?? '', '/') . '/programmazione.php';
                $htmlBody = CalendarHelper::buildNotificationHtml(
                    $exam['first_name'] . ' ' . $exam['last_name'],
                    "⏰ Promemoria: $eventTitle",
                    $pt_label, $exam['planned_date'],
                    $exam['brand_name'] ?? '', $exam['cert_name'] ?? '',
                    $location, $exam['notes'] ?? '',
                    $googleUrl, $portalUrl
                );

                $body = "Gentile {$exam['first_name']},\r\n\r\n"
                      . "promemoria: $cert_info è programmato "
                      . "per il " . date('d/m/Y', strtotime($exam['planned_date'])) . ".\r\n";
                if ($exam['exam_center']) $body .= "Centro: {$exam['exam_center']}\r\n";
                if ($exam['exam_location']) $body .= "Luogo: {$exam['exam_location']}\r\n";
                $body .= "\r\nVerifica i dettagli nel portale.\r\n\r\n— " . ($settings['app_name'] ?? 'certV');

                send_certv_email($to_email,
                    "[" . ($settings['app_name'] ?? 'certV') . "] ⏰ $cert_info tra $days giorni",
                    $body, $htmlBody, [], 'asset', $exam['id'],
                    [['name' => 'promemoria.ics', 'content' => $icsContent, 'mime' => 'text/calendar; method=PUBLISH']]
                );

                // Marca reminder come inviato
                if ($has_reminder_cols) {
                    try { $pdo->prepare("UPDATE planned_exams SET $reminder_col=1 WHERE id=?")->execute([$exam['id']]); } catch (\Exception $e) {}
                }
            }

            $processed['exams_alerted']++;
        }
    } catch (\Exception $e) {
        write_log('Cron', 'error', "Errore alert esami ($days gg): " . $e->getMessage());
    }
}

// ── 5. FINESTRA RINNOVO CERTIFICAZIONI ────────────────────────────────────────
$processed['renewals_alerted'] = 0;
$renewal_days = (int)($settings['notify_renewal_days'] ?? 30);
if ($renewal_days > 0) {
    $renewal_target = date('Y-m-d', strtotime("-{$renewal_days} days"));
    try {
        $rs = $pdo->prepare(
            "SELECT uc.id, uc.expiry_date,
                    e.id employee_id, e.first_name, e.last_name,
                    u.id user_id, u.email, u.notifications_email,
                    c.name cert_name, b.name brand_name
             FROM user_certifications uc
             JOIN employees e ON uc.employee_id = e.id
             JOIN certifications c ON uc.certification_id = c.id
             JOIN brands b ON c.brand_id = b.id
             LEFT JOIN users u ON u.employee_id = e.id AND u.status='active'
             WHERE uc.status = 'expired'
               AND uc.expiry_date = ?
               AND e.status = 'active'"
        );
        $rs->execute([$renewal_target]);
        $renewals = $rs->fetchAll();

        foreach ($renewals as $ren) {
            $title = "Finestra rinnovo — {$ren['cert_name']}";
            $msg_text = "{$ren['cert_name']} ({$ren['brand_name']}) di {$ren['first_name']} {$ren['last_name']} "
                      . "è scaduta da {$renewal_days} giorni. Pianificare il rinnovo.";

            if ($ren['user_id']) {
                push_notification($title, $msg_text, 'asset', 'warning', $ren['user_id'], null, 'programmazione.php');
            }
            push_notification($title, $msg_text, 'asset', 'warning', null, 3, 'programmazione.php');

            if ($smtp_ok && $ren['user_id'] && $ren['email'] && $ren['notifications_email']) {
                $body = "Gentile {$ren['first_name']},\r\n\r\n"
                      . "la certificazione {$ren['cert_name']} ({$ren['brand_name']}) è scaduta il "
                      . date('d/m/Y', strtotime($ren['expiry_date'])) . ".\r\n\r\n"
                      . "Sono trascorsi {$renewal_days} giorni: si raccomanda di pianificare il rinnovo "
                      . "per mantenere la compliance del brand.\r\n\r\n"
                      . "— " . ($settings['app_name'] ?? 'certV');

                send_certv_email($ren['email'],
                    "[" . ($settings['app_name'] ?? 'certV') . "] Rinnovo {$ren['cert_name']} — finestra aperta",
                    $body, null, [], 'asset', $ren['id']);
            }

            $processed['renewals_alerted']++;
        }
    } catch (\Exception $e) {}
}

// ── 6. RICHIESTE LOGISTICHE PENDENTI (avviso segreteria) ─────────────────────
$processed['logistics_pending'] = 0;
try {
    $pending = $pdo->query(
        "SELECT COUNT(*) FROM logistics_requests WHERE status='submitted'"
    )->fetchColumn();
    if ($pending > 0) {
        push_notification(
            "Richieste logistiche in attesa",
            "$pending richieste da gestire nella sezione Segreteria.",
            'system', 'info', null, 2, 'segreteria.php'
        );
        $processed['logistics_pending'] = $pending;
    }
} catch (\Exception $e) { /* tabella non ancora creata */ }

write_log('Cron', 'success',
    "Cron v2.4: cert_alerted={$processed['certs_alerted']}, "
    . "expired={$processed['certs_expired']}, contracts={$processed['contracts_alerted']}, "
    . "exams={$processed['exams_alerted']}, renewals={$processed['renewals_alerted']}, "
    . "logistics={$processed['logistics_pending']}, "
    . "emails_sent={$processed['emails_sent']}, emails_failed={$processed['emails_failed']}"
);

echo date('Y-m-d H:i:s')
   . " — completato."
   . " Cert: {$processed['certs_alerted']}"
   . ", Scadute: {$processed['certs_expired']}"
   . ", Contratti: {$processed['contracts_alerted']}"
   . ", Esami: {$processed['exams_alerted']}"
   . ", Rinnovi: {$processed['renewals_alerted']}"
   . ", Logistica: {$processed['logistics_pending']}"
   . ", Email OK/ERR: {$processed['emails_sent']}/{$processed['emails_failed']}\n";
