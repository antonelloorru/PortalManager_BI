# Deployment — PortalManager v1.9.22

## 1. Contenuto

```
VERSION                           1.9.22
app/Version.php                   PM_VERSION = 1.9.22
gli altri file                    invariati da v1.9.21
sql/migration_v1_9_22.sql         1 tabella + 4 viste + 2 parametri
sql/upgrade_1_7_56_to_1_9_22.sql  consolidato cumulativo (766 statement)
docs/                             questa documentazione
```

**Nessun file applicativo modificato**: la release costruisce le viste.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_22.sql` oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT * FROM v_cm_pratix_quadro\G

SELECT order_code, operazioni, commesse, clienti, importo_totale,
       ha_codici_multipli, esito_validazione
  FROM v_cm_pratix_ordinativi ORDER BY importo_totale DESC LIMIT 20;

SELECT commessa, cliente, tipo_contratto, descrizione, importo, origine_importo
  FROM v_cm_pratix_righe WHERE order_code = 'C1229' ORDER BY importo DESC;

SELECT * FROM v_cm_pratix_anomalie ORDER BY priorita, order_code;
```

Attesi: **896 ordinativi, 1.186 operazioni, 36.620.926 €**.

## 4. La relazione era descritta al contrario

La richiesta diceva `forms_contract_operation.code` = `main_order.order_code`. Nel
gestionale è l'opposto:

- `forms_contract_main_order.**code**` — il codice dell'ordinativo
- `forms_contract_operation.**order_code**` — il riferimento

`forms_contract_operation` non ha una colonna `code`; `main_order` non ha
`order_code`. Ho usato la relazione che esiste.

## 5. La validazione non è ancora possibile

**`forms_contract_main_order` è vuota nel dump**, e nel portale non c'è una
tabella corrispondente: l'importo totale dichiarato non è disponibile.

Tutti i 896 ordinativi risultano quindi **«totale non disponibile»** — che non è
un errore, è l'assenza del termine di confronto.

`cm_pratix_orders` è pronta ad accoglierlo. **Serve aggiungere il dataset di
sincronizzazione** per `forms_contract_main_order`: ditemi se procedo.

## 6. Le celle con più codici

**15 ordinativi** hanno più di un codice nella stessa cella:

```
C2501 C2500 C2499 C1401     quattro codici, 11 commesse
A4385 A4386                 due codici, 6 commesse
A0367 - A0489 - A0452       tre codici, 3 commesse
```

Sono **segnalate, non divise**: dividerle richiederebbe di decidere come ripartire
l'importo fra i codici, e ogni scelta produrrebbe numeri precisi e inventati.

Il criterio di riconoscimento è **strutturale** — due o più sequenze lettera+cifre
— e sta in un parametro:

```sql
SELECT setting_value FROM app_settings WHERE setting_key = 'pratix_regex_multi';
```

Riconosce correttamente `Ordine A2245 - FT 1921/H` come **singolo**: un codice più
un riferimento fattura, non due ordinativi.

## 7. L'importo: consolidato o previsto

`final_value` quando c'è, `revenue` altrimenti. La colonna `origine_importo` lo
dichiara.

Sui dati attuali gli importi sono quasi tutti **previsti**: il totale è quindi una
previsione, non un consuntivo.

## 8. Cosa manca

**La pagina.** Le viste sono verificate e interrogabili; il riquadro con
l'elenco raggruppato, i link alle commesse e gli avvisi si costruisce su queste.

## 9. Rollback

```sql
DROP VIEW IF EXISTS v_cm_pratix_quadro;
DROP VIEW IF EXISTS v_cm_pratix_anomalie;
DROP VIEW IF EXISTS v_cm_pratix_ordinativi;
DROP VIEW IF EXISTS v_cm_pratix_righe;
DROP TABLE IF EXISTS cm_pratix_orders;
DELETE FROM app_settings WHERE setting_key IN ('pratix_regex_multi','pratix_tolleranza');
UPDATE app_settings SET setting_value='1.9.21'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
