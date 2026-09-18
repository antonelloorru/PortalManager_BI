# Deployment — PortalManager v1.8.95

## 1. Contenuto

```
VERSION                          1.8.95
cron_alerts.php                  (ROOT)  NUOVO — esecuzione pianificata
dir_report.php                   (ROOT)  pannello di stato
app/AlertEngine.php              NUOVO — rilevazione e invio
app/SmtpMailer.php               invariato, incluso per completezza
app/Version.php                  PM_VERSION = 1.8.95
gli altri file                   invariati da v1.8.94
sql/migration_v1_8_95.sql        4 tabelle, 2 viste, 8 regole, 6 impostazioni
sql/upgrade_1_7_56_to_1_8_95.sql consolidato cumulativo (622 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in ROOT e i tre in `app\`.
3. SQL Runner: `sql/migration_v1_8_95.sql` (da v1.8.94) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

**Il sistema parte spento**: `alert_enabled = 0` e `alert_dry_run = 1`. Rileva e
registra, non spedisce, finché non lo accendete deliberatamente.

## 3. Configurazione — quattro passi

### Passo 1 — l'alias del mittente

```sql
UPDATE app_settings SET setting_value = 'commesse@wetechs.it'
 WHERE setting_key = 'alert_alias_email';
UPDATE app_settings SET setting_value = 'PortalManager — Commesse'
 WHERE setting_key = 'alert_alias_name';
```

Se lasciate vuoto `alert_alias_email`, viene usato `mail_from` — lo stesso
mittente delle notifiche di scadenza. L'alias serve a distinguerle: chi riceve
capisce dall'intestazione di cosa si tratta e può filtrarle.

**L'alias deve essere un indirizzo che il server SMTP autorizza a spedire.** Con
Aruba, in genere deve appartenere allo stesso dominio dell'utenza.

### Passo 2 — il direttore

```sql
UPDATE app_settings SET setting_value = 'direttore@wetechs.it'
 WHERE setting_key = 'alert_director_email';
```

### Passo 3 — gli agenti e le copie

```sql
INSERT INTO cm_alert_recipients (agent_name, email, cc_email) VALUES
  ('Turchi Alessandro', 'a.turchi@wetechs.it', 'responsabile.area@wetechs.it'),
  ('Motta Jonatan',     'j.motta@wetechs.it',  NULL);
```

`agent_name` deve corrispondere **esattamente** a `cm_projects.commercial_ref`.
Per l'elenco:

```sql
SELECT DISTINCT commercial_ref FROM cm_projects
 WHERE commercial_ref <> '' ORDER BY commercial_ref;
```

Sono 45 agenti. Gli agenti senza indirizzo non ricevono nulla: i loro alert
arrivano comunque al direttore.

### Passo 4 — la prova

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_alerts.php --dry-run
```

Rileva e registra gli invii come **simulate** senza spedire. Verificate in
`cm_alert_sent` che i destinatari e i conteggi siano quelli attesi.

Quando siete soddisfatti:

```sql
UPDATE app_settings SET setting_value = '0' WHERE setting_key = 'alert_dry_run';
UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'alert_enabled';
```

## 4. Pianificazione

Utilità di pianificazione di Windows, **una volta al giorno** — le soglie si
superano nell'arco di giorni, non di ore.

```
Programma:  P:\xampp\php\php.exe
Argomenti:  P:\xampp\htdocs\portalmanager\cron_alerts.php --quiet
Inizia in:  P:\xampp\htdocs\portalmanager
```

| Codice di uscita | Significato |
|---|---|
| 0 | eseguito, oppure niente da fare |
| 1 | eseguito con errori di invio |
| 2 | errore di configurazione o connessione |

## 5. Verifica

**Gestione Commesse → Report direzionale**: in testa il riquadro **Alerting
commesse** con lo stato.

Attese sui vostri dati: **434 condizioni rilevabili** — 216 sforate, 168 ferme,
22 in scadenza, 16 sotto margine, 10 in divergenza.

## 6. Perché non ricevete la stessa email ogni giorno

Ogni segnalazione viene inviata **una sola volta per livello di soglia**.

Una commessa che resta all'85% non genera un promemoria quotidiano; un nuovo invio
avviene solo se la situazione peggiora di fascia — dall'80% al 90% sì, dall'85%
all'86% no.

Senza questo vincolo, in due settimane il destinatario smetterebbe di leggere le
email, **incluse quelle nuove**.

Le condizioni rientrate vengono chiuse automaticamente alla rilevazione
successiva.

## 7. Se un invio fallisce

L'errore viene registrato in `cm_alert_sent` con `status = 'errore'`, e **gli
eventi non vengono marcati come inviati**: la successiva esecuzione riprova.

```sql
SELECT recipient, subject, error_msg, sent_at FROM cm_alert_sent
 WHERE status = 'errore' ORDER BY sent_at DESC LIMIT 20;
```

## 8. Le soglie si modificano senza release

```sql
UPDATE cm_alert_rules SET threshold_warn = 80, threshold_alarm = 95
 WHERE code = 'budget_consumo';

UPDATE cm_alert_rules SET is_active = 0 WHERE code = 'fermo';
```

Sono valori aziendali: li avete stabiliti voi e potete cambiarli quando serve.

**Nota**: modificando le soglie, le condizioni già segnalate mantengono la loro
firma. Per rigenerare gli alert con le nuove soglie:

```sql
UPDATE cm_alert_events SET resolved_at = NOW() WHERE resolved_at IS NULL;
```

## 9. I riepiloghi cadenzati

Le regole `riepilogo_sett` e `riepilogo_mens` sono predisposte ma **disattivate**
(`is_active = 0`): il motore di invio periodico non è ancora implementato.

A differenza degli alert, un riepilogo va inviato **anche se vuoto** — «nessuna
criticità» è un'informazione, mentre un alert che non arriva è ambiguo.

## 10. Rollback

```sql
DROP VIEW IF EXISTS v_cm_alert_stato;
DROP VIEW IF EXISTS v_cm_alert_da_rilevare;
DROP TABLE IF EXISTS cm_alert_sent;
DROP TABLE IF EXISTS cm_alert_events;
DROP TABLE IF EXISTS cm_alert_recipients;
DROP TABLE IF EXISTS cm_alert_rules;
UPDATE app_settings SET setting_value='1.8.94'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Rimuovere `cron_alerts.php`, `app/AlertEngine.php` e l'attività pianificata.
