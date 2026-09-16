# Deployment — PortalManager v1.9.10

## 1. Contenuto

```
VERSION                           1.9.10
service_desk.php                  (ROOT)  riquadro OBJ_2/2.3, export, stampa
app/SdModel.php                   + 6 metodi
app/Version.php                   PM_VERSION = 1.9.10
gli altri file                    invariati da v1.9.9
sql/migration_v1_9_10.sql         5 viste + 2 parametri
sql/upgrade_1_7_56_to_1_9_10.sql  consolidato cumulativo
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_10.sql` (da v1.9.9) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Service Desk** → riquadro **Quadro del perimetro Service Desk**.

```sql
SELECT * FROM v_cm_sd_obj2_quadro\G
SELECT codice_linea, commesse, valore, margine, margine_pct, quota_valore_pct
  FROM v_cm_sd_obj2_linee ORDER BY valore DESC;
```

Attesi: **718 commesse, 19.407.949 €, margine 55,5%, 269 clienti**.

## 4. Il perimetro è configurabile

```sql
SELECT setting_value FROM app_settings WHERE setting_key = 'sd_linee_perimetro';
-- WTS-SD,WTS-ACM,WTS-CSS,WTS-CC,WTS-HD
```

Per aggiungere o togliere una linea:

```sql
UPDATE app_settings SET setting_value = 'WTS-SD,WTS-ACM,WTS-CSS,WTS-CC'
 WHERE setting_key = 'sd_linee_perimetro';
```

Quali contratti siano «Service Desk» è una domanda aziendale, non tecnica: per
questo è un parametro e non un elenco nel codice.

## 5. I tre numeri di «addetti»

| Misura | Significato |
|---|---|
| **distinti** | persone che hanno lavorato almeno una volta |
| **medi per mese** | media dei distinti mensili |
| **equivalenti a tempo pieno** | ore ÷ (mesi × 168) |

La prima sovrastima chi ha fatto un intervento solo. **Se vi serve un numero solo,
ditemi quale**: le tengo tutte perché «medio» richiede di dire su cosa.

Le ore mensili di un tempo pieno sono un parametro:

```sql
UPDATE app_settings SET setting_value = '160' WHERE setting_key = 'sd_ore_mese_fte';
```

## 6. WTS-HD ha margine −393,8%

Non è un difetto di calcolo. È la linea **interna** (Help Desk interno Wetechs):
il costo eccede di molto il valore nominale, che è ciò che le attività interne
sono.

Se preferite escluderla dal perimetro, toglietela dal parametro.

## 7. Export

**XLSX** — sei fogli nuovi:

| Foglio | Contenuto |
|---|---|
| OBJ_2 quadro | i 21 indicatori |
| OBJ_2 per linea | le 5 linee con quote |
| OBJ_2 addetti | mese per mese |
| OBJ_2.3 ripartizione | classi di gestione |
| OBJ_2.3 per coda | fino a 20 code |
| OBJ_2 commesse | tutte le 718 |

**PDF** — dal **Report generale** (`?print=1`), poi «Stampa → Salva come PDF».
Contiene quadro, dettaglio per linea, ticket gestiti/escalati e ripartizione.

Per i colori dei riquadri attivate **«Grafica di sfondo»** nelle opzioni del
browser.

## 8. Cosa manca ancora

**OBJ_2.1 (fatturabili) e OBJ_2.2 (interni) non sono implementati.**

Il ticket non porta alcun riferimento alla commessa: in `cm_sd_messages` ci sono
solo `ticket_code`, `msg_type`, `queue_name`, `author_name`, `received_at`.

Senza un raccordo dichiarato non posso dire quali ticket siano su contratto ACM,
CSS o CC, né quali siano «Internal Support». Servono:

1. **la regola di raccordo** — la coda identifica il contratto? il cliente?
2. **come si riconosce «Internal Support»** — un valore di `queue_name`? di
   `msg_type`?
3. **il listino standard** per valorizzare i ticket interni

Un estratto di `SELECT queue_name, msg_type, COUNT(*) FROM cm_sd_messages GROUP BY
1,2` mi basterebbe per proporvi la regola guardando i valori reali.

## 9. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_obj23_code;
DROP VIEW IF EXISTS v_cm_sd_obj23_ripartizione;
DROP VIEW IF EXISTS v_cm_sd_obj2_quadro;
DROP VIEW IF EXISTS v_cm_sd_addetti_mese;
DROP VIEW IF EXISTS v_cm_sd_obj2_linee;
DROP VIEW IF EXISTS v_cm_sd_commesse;
DELETE FROM app_settings WHERE setting_key IN ('sd_linee_perimetro','sd_ore_mese_fte');
UPDATE app_settings SET setting_value='1.9.9'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
