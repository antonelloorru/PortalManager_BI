# Deployment — PortalManager v1.9.11

## 1. Contenuto

```
VERSION                           1.9.11
service_desk.php                  (ROOT)  riquadro OBJ_2.1/2.2, export, stampa
app/SdModel.php                   + 6 metodi
app/Version.php                   PM_VERSION = 1.9.11
gli altri file                    invariati da v1.9.10
sql/migration_v1_9_11.sql         1 tabella + 6 viste + 2 parametri
sql/upgrade_1_7_56_to_1_9_11.sql  consolidato cumulativo
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_11.sql` (da v1.9.10) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Prima cosa da fare: compilare il listino

```sql
SELECT service_line, label, tariffa_ora FROM cm_sd_listino ORDER BY service_line;
```

**Tutte le tariffe nascono a NULL**, e finché lo sono il valore a listino non viene
calcolato.

```sql
UPDATE cm_sd_listino SET tariffa_ora = 80 WHERE service_line = 'WTS-ACM';
UPDATE cm_sd_listino SET tariffa_ora = 75 WHERE service_line = 'WTS-CSS';
UPDATE cm_sd_listino SET tariffa_ora = 95 WHERE service_line = 'WTS-CC';
UPDATE cm_sd_listino SET tariffa_ora = 60 WHERE service_line = 'NV_AI';
```

**NULL e non zero**: zero significherebbe «gratis», NULL significa «non stabilita».
Un valore a listino calcolato su tariffe assenti sarebbe zero e sembrerebbe un
dato.

Il riquadro segnala quante linee sono ancora senza tariffa.

## 4. Verifica

**Service Desk** → riquadro **Attività del Service Desk: fatturabile e interna**.

```sql
SELECT * FROM v_cm_sd_obj21_quadro\G
SELECT * FROM v_cm_sd_obj21_fatturabili;
SELECT * FROM v_cm_sd_obj22_interne;
SELECT * FROM v_cm_sd_obj23_tecnici;
```

## 5. Chi è il Service Desk

I tecnici vengono dall'**unità organizzativa**, non da una deduzione:

```sql
SELECT * FROM v_cm_sd_tecnici_uo ORDER BY ordina;
```

Attesi i **4 profili** assegnati a «Service Desk». Per articolare diversamente il
primo livello:

```sql
UPDATE app_settings SET setting_value = 'Service Desk,Help Desk,SOC'
 WHERE setting_key = 'sd_unita_organizzative';
```

Se un tecnico non compare, verificate che abbia un profilo in `cm_tech_profiles`
con `unit_id` dell'unità giusta.

## 6. Fatturabile o interna

Dipende dalla **natura della commessa** (`cm_contract_models.has_revenue`), non dal
ticket:

- **fatturabile**: ACM, CSS, CC, SD — commesse a ricavo
- **interna**: `NV_*`, WTS-HD — commesse senza ricavo, l'«Internal Support»

## 7. Le due valorizzazioni

| Colonna | Cosa contiene |
|---|---|
| **Addebitato** | ciò che il gestionale ha valorizzato sulla commessa |
| **A listino** | ore × tariffa |

Il primo esiste solo dove il gestionale lo ha compilato. Il secondo si applica a
tutti i moduli, ed è **l'unico modo di valorizzare quelli interni**.

Dove i due divergono, la differenza è informativa: un intervento addebitato meno
del listino è stato scontato o assorbito.

## 8. «Interventi» e non «ticket»

L'obiettivo chiedeva un numero di ticket. Dai moduli si contano **moduli**: un
ticket può generare più moduli e un modulo coprire più ticket.

La colonna si chiama «Interventi» per non far credere che sia la stessa cosa.

## 9. Export

**XLSX** — tre fogli nuovi:

| Foglio | Contenuto |
|---|---|
| OBJ_2.1-2.2 attività | fatturabile e interna, per contratto |
| OBJ_2.3 per tecnico | ripartizione dell'unità |
| Listino | le tariffe, per verificarle |

**PDF** — dal Report generale, poi «Stampa → Salva come PDF».

## 10. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_obj23_tecnici;
DROP VIEW IF EXISTS v_cm_sd_obj21_quadro;
DROP VIEW IF EXISTS v_cm_sd_obj22_interne;
DROP VIEW IF EXISTS v_cm_sd_obj21_fatturabili;
DROP VIEW IF EXISTS v_cm_sd_attivita;
DROP VIEW IF EXISTS v_cm_sd_tecnici_uo;
DROP TABLE IF EXISTS cm_sd_listino;
DELETE FROM app_settings WHERE setting_key IN ('sd_unita_organizzative','sd_tariffa_default');
UPDATE app_settings SET setting_value='1.9.10'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
