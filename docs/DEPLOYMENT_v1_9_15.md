# Deployment — PortalManager v1.9.15

## 1. Contenuto

```
VERSION                           1.9.15
service_desk.php                  (ROOT)  riquadro costi, export, stampa
it_service.php                    (ROOT)  riquadro costi
app/SdModel.php                   + 5 metodi
app/ItServiceModel.php            + 3 metodi
app/Version.php                   PM_VERSION = 1.9.15
sql/migration_v1_9_15.sql         1 tabella + 4 viste + 3 parametri
sql/upgrade_1_7_56_to_1_9_15.sql  consolidato cumulativo
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i **due file in ROOT** e i tre in `app\`.
3. SQL Runner: `sql/migration_v1_9_15.sql` (da v1.9.14) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Prima cosa: verificare le tariffe

```sql
SELECT service_line, fascia, scaglione, etichetta, tariffa_ora, origine
  FROM cm_sd_tariffe ORDER BY service_line, fascia, scaglione;
```

**Le tariffe sono dedotte dal vostro template, non dichiarate.** La colonna
`origine` lo dice: `dedotta da template`.

| Fascia | Scaglione | Tariffa |
|---|---|---|
| C — ordinario | ora (fino a 4 h) | 100,00 |
| C | mezza giornata (4–8 h) | 87,50 |
| C | giornata (da 8 h) | 81,25 |
| D — extra-orario | ora | 120,00 |
| D | mezza giornata | **NULL** |
| D | giornata | **NULL** |

Le due combinazioni a NULL **non comparivano nell'esempio**: «da stabilire», non
«gratis». Se servono:

```sql
UPDATE cm_sd_tariffe SET tariffa_ora = 105.00, origine = 'dichiarata'
 WHERE service_line = 'WTS-ACM' AND fascia = 'D' AND scaglione = 'mezza_giornata';
```

**Se le condizioni contrattuali reali differiscono da quelle che ho dedotto,
correggetele qui** — e aggiornate `origine` a `dichiarata`, così resta traccia di
quali valori sono verificati.

## 4. Verifica

**Service Desk** e **Relazione di Servizio IT** → riquadro **Riepilogo costi per
fascia e contratto**.

Le due sezioni devono dare **lo stesso valore**: condividono le viste.

```sql
SELECT * FROM v_cm_sd_costi_quadro\G
SELECT codice_linea, descrizione_tariffa, interventi, ore, valore, tariffa_ora
  FROM v_cm_sd_costi_riepilogo ORDER BY codice_linea, fascia, scaglione;
```

## 5. Come funziona il calcolo

**La durata del singolo intervento decide la tariffa**, non il totale del periodo:

| Durata dell'intervento | Tariffa applicata |
|---|---|
| fino a 4 ore | oraria |
| oltre 4, sotto 8 | mezza giornata |
| da 8 ore | giornata |

Il valore è **ore × tariffa**, non un pacchetto: un intervento da 5 ore vale
5 × 87,50 = 437,50, che è la riga «Fascia C 5 ore» del vostro esempio.

Le soglie sono parametri:

```sql
UPDATE app_settings SET setting_value = '4' WHERE setting_key = 'sd_soglia_mezza';
UPDATE app_settings SET setting_value = '8' WHERE setting_key = 'sd_soglia_giornata';
```

## 6. Fascia C e fascia D

**C** è l'orario ordinario — 09–13 e 14–18 nei feriali. **D** è l'extra-orario.

Sabato e domenica sono **fascia D per costruzione**, valutati prima dell'ora: un
intervento domenicale alle 10 cadrebbe altrimenti nella finestra 09–13.

Se un modulo non ha l'attività DGB collegata l'ora manca, e viene trattato come
fascia C: è la scelta prudente, perché contarlo extra-orario gonfierebbe la
tariffa più alta.

## 7. Export

Tre fogli che riproducono il layout del vostro esempio:

| Foglio | Struttura |
|---|---|
| **Riepilogo x fascia e contratto** | un blocco per contratto, riga TOTALE |
| **Elenco Contratti e fasce** | dettaglio per commessa |
| **Tariffe per fascia** | il listino, con `origine` |

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_costi_quadro;
DROP VIEW IF EXISTS v_cm_sd_costi_commessa;
DROP VIEW IF EXISTS v_cm_sd_costi_riepilogo;
DROP VIEW IF EXISTS v_cm_sd_costi_valorizzati;
DROP VIEW IF EXISTS v_cm_sd_costi_moduli;
DROP TABLE IF EXISTS cm_sd_tariffe;
DELETE FROM app_settings
 WHERE setting_key IN ('sd_costi_linee','sd_soglia_mezza','sd_soglia_giornata');
UPDATE app_settings SET setting_value='1.9.14'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
