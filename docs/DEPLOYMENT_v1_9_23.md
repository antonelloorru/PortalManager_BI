# Deployment — PortalManager v1.9.23

## 1. Contenuto

```
VERSION                           1.9.23
pratix_orders.php                 (ROOT)  NUOVA — pagina Ordinativi Pratix
app/Router.php                    pagina registrata
app/MenuManager.php               voce di menu
app/Version.php                   PM_VERSION = 1.9.23
gli altri file                    invariati da v1.9.22
sql/migration_v1_9_23.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_23.sql  consolidato cumulativo (767 statement)
docs/                             questa documentazione
```

**Prerequisito**: v1.9.22 applicata (le viste `v_cm_pratix_*`).

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`pratix_orders.php` in ROOT** e i tre file in `app\`.
3. SQL Runner: `sql/migration_v1_9_23.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Dove si trova

**Gestione Commesse → Ordinativi Pratix**, sotto «Fasce costo orario».

## 4. Verifica

Attesi **896 ordinativi, 36.620.926 €**, con i primi 300 a video ordinati per
importo.

Aprite un ordinativo su più commesse — per esempio **C1229**, 8 commesse ESTAR:
l'elenco deve mostrare le commesse con importo singolo e la riga TOTALE deve
sommare esattamente.

Il link a destra di ogni riga apre la commessa.

## 5. Il riquadro azzurro in testa

Dice che **la validazione «somma contro totale dichiarato» non è ancora
possibile**: l'importo totale dell'ordinativo non è sincronizzato.

Non è un errore da correggere: è l'assenza del termine di confronto. Per abilitare
la verifica serve il dataset di sincronizzazione di
`forms_contract_main_order` — ditemi se procedo.

## 6. Gli avvisi rossi

**15 ordinativi hanno più codici nella stessa cella.** Il riquadro li elenca e il
link «Vedi solo queste» filtra l'elenco.

Sono **segnalati e non divisi**: ripartire l'importo fra i codici richiederebbe di
decidere se in parti uguali o tutto al primo, e ogni scelta produrrebbe numeri
precisi e inventati.

## 7. La P arancione accanto agli importi

Segnala un valore **previsto** e non consolidato. Sui dati attuali sono quasi
tutti previsti: **il totale è una previsione, non un consuntivo**.

## 8. Un difetto corretto in collaudo

MariaDB raggruppa `a3992` e `A3992` insieme; un array PHP li distingue. Quattro
ordinativi su 300 avrebbero mostrato il **totale giusto con meno righe** di quelle
che lo compongono — un elenco che non somma al proprio totale, senza errori
visibili.

La chiave è ora normalizzata in maiuscolo.

## 9. Rollback

Rimuovere `pratix_orders.php` e ripristinare `app/Router.php` e
`app/MenuManager.php` dalla v1.9.22.

```sql
UPDATE app_settings SET setting_value='1.9.22'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
