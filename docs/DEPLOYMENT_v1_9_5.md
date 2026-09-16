# Deployment — PortalManager v1.9.5

## 1. Contenuto

```
VERSION                          1.9.5
service_desk.php                 (ROOT)  analisi del Team, stampa, export
app/SdModel.php                  + 4 metodi
app/Version.php                  PM_VERSION = 1.9.5
gli altri file                   invariati da v1.9.4
sql/migration_v1_9_5.sql         5 viste
sql/upgrade_1_7_56_to_1_9_5.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_5.sql` (da v1.9.4) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: le viste leggono i dati già presenti.

## 3. Verifica

**Gestione Commesse → Service Desk**: compare il riquadro **Analisi del Team**,
con:

- sei indicatori di squadra
- il dettaglio per componente, a intestazione doppia TICKET / MODULI
- la ripartizione per fascia oraria
- la ripartizione per tipologia di contratto

Il nome di ogni componente è cliccabile e apre la sua scheda.

```sql
SELECT * FROM v_cm_sd_team_quadro\G
SELECT tecnico, ticket_presi, moduli, ore, ore_fuori_orario, pct_fuori_orario
  FROM v_cm_sd_team_dettaglio ORDER BY ordina;
```

## 4. Ticket e moduli non si sommano

Sono due attività distinte: un ticket **può** generare un modulo di intervento, e
non esiste un legame esplicito fra le due tabelle.

La sovrapposizione non è quantificabile, quindi le due grandezze restano su
colonne separate. Un totale unico conterebbe parte del lavoro due volte senza che
nessuno sappia quanta.

## 5. Le fasce orarie

**09–13 e 14–18 nei feriali**, il resto fuori orario.

**Sabato e domenica sono fuori orario per costruzione**, prima ancora di guardare
l'ora: un intervento domenicale alle 10 non è «in orario».

Se un modulo non ha l'attività DGB collegata, l'ora di inizio manca e la fascia
risulta **«non rilevata»**: non è né in orario né fuori, ed è giusto che sia
distinta da entrambe.

È la stessa regola della Relazione di Servizio IT: se i due numeri divergessero,
sarebbe un difetto nei dati e non nella definizione.

## 6. Contratto e fascia insieme

La tabella incrocia le due dimensioni, e risponde a una domanda che il conteggio
separato non copre: **su quali contratti si lavora fuori orario**.

Un contratto a canone con molte ore fuori orario ha un costo di erogazione diverso
da quello previsto.

## 7. Stampa ed export

Il **report di stampa** include quadro, dettaglio e ripartizione per contratto.

L'**export XLSX** ha tre fogli in più: Team, Fasce orarie, Contratti e fasce.

## 8. Se il team risulta senza moduli

Verificare il ponte fra i nomi:

```sql
SELECT * FROM v_cm_sd_nome_moduli;
```

Attese quattro righe. Nei ticket il nome è `Nome Cognome`, nei moduli
`Cognome Nome`: senza il ponte il team risulterebbe con zero ore, che somiglia
molto a «questa squadra non fa interventi».

## 9. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_team_contratto;
DROP VIEW IF EXISTS v_cm_sd_team_fascia;
DROP VIEW IF EXISTS v_cm_sd_team_dettaglio;
DROP VIEW IF EXISTS v_cm_sd_team_quadro;
DROP VIEW IF EXISTS v_cm_sd_moduli;
UPDATE app_settings SET setting_value='1.9.4'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.9.4.
