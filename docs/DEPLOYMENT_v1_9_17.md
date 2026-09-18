# Deployment — PortalManager v1.9.17

## 1. Contenuto

```
VERSION                           1.9.17
service_desk.php                  (ROOT)  costi anche nel report personale
it_service.php                    (ROOT)  correzione $mo → $it, pulsante adattivo
app/it_service_print.php          report personale + riepilogo costi
app/Version.php                   PM_VERSION = 1.9.17
gli altri file                    invariati da v1.9.16
sql/migration_v1_9_17.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_17.sql  consolidato cumulativo (746 statement)
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i **due file in ROOT** e i due in `app\`.
3. SQL Runner: `sql/migration_v1_9_17.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica — Service Desk

Selezionare un componente e aprire il **Report personale**: deve contenere il
riepilogo costi, che prima compariva solo nel generale.

Il titolo riporta il nome della persona.

## 4. Verifica — Relazione di Servizio IT

Selezionare **un solo incaricato** nei filtri: il pulsante di stampa cambia
etichetta in **«Report personale»**, e il report esce con l'intestazione
personalizzata e il riepilogo costi.

Con **zero o due o più** incaricati resta il report generale.

| Incaricati | Report |
|---|---|
| 0 | generale |
| 1 | **personale** |
| 2 o più | generale |

Il personale si riconosce dai filtri, non da un parametro: un flag separato
avrebbe permesso di chiedere il report personale con tre incaricati selezionati.

## 5. Un difetto correggo che era in produzione

Il riquadro costi della Relazione IT invocava `$mo->costiQuadro()`, ma il modello
si chiama `$it`. **`$mo` non esiste.**

`php -l` non lo vede — una variabile non definita è sintatticamente valida — e
l'errore si manifesta solo eseguendo quel ramo.

Era così **dalla v1.9.16**: se avete applicato quella release e aperto la
Relazione di Servizio IT, il riquadro costi potrebbe non essersi mostrato.

## 6. Quadratura da verificare

I costi personali devono sommare al generale:

```sql
SELECT tecnico, COUNT(*) AS moduli, ROUND(SUM(ore),2) AS ore, ROUND(SUM(valore),2) AS valore
  FROM v_cm_sd_costi_valorizzati GROUP BY tecnico WITH ROLLUP;
```

E le due sezioni devono dare lo stesso valore: condividono le viste.

## 7. Rollback

Ripristinare i quattro file dalla v1.9.16 — ma il difetto di `$mo` tornerebbe.

```sql
UPDATE app_settings SET setting_value='1.9.16'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
