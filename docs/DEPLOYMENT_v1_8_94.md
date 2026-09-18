# Deployment — PortalManager v1.8.94

## 1. Contenuto

```
VERSION                          1.8.94
dir_report.php                   (ROOT)  NUOVO — report e schede
app/DirModel.php                 NUOVO — letture
app/dir_report_print.php         NUOVO — stampa a colori
app/MenuManager.php              voce di menu
app/Router.php                   slug
app/Version.php                  PM_VERSION = 1.8.94
gli altri file                   invariati da v1.8.93
sql/migration_v1_8_94.sql        4 viste
sql/upgrade_1_7_56_to_1_8_94.sql consolidato cumulativo (612 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`dir_report.php` in ROOT** e i cinque file in `app\`.
3. SQL Runner: `sql/migration_v1_8_94.sql` (da v1.8.93) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: `commercial_ref` era già popolato.

## 3. Verifica

**Gestione Commesse → Report direzionale**. Attesi, con il perimetro predefinito
(solo commesse aperte):

| Indicatore | Valore |
|---|---|
| Commesse | 575 |
| Valore | 27.346.626 € |
| Margine | 76,1% |
| Sforate | 216 |
| Ferme da 90 gg | 168 |

Selezionando un agente dal menu, la pagina diventa la sua **scheda personale**: il
perimetro è dichiarato in testa e la tabella degli agenti scompare.

## 4. Perché i numeri sono diversi da quelli grezzi

**Il margine è sulle sole commesse a ricavo.** Le 76 commesse interne consumano
ore senza produrne per costruzione: includerle abbasserebbe il margine descrivendo
una realtà che non esiste.

**Il rischio è sulle sole commesse aperte.** Sui dati grezzi risultavano 490
sforate e 505 ferme, ma 274 e 337 di queste sono **chiuse**. Un quadro con il 46%
del portafoglio in sforamento, di cui metà è storia, non fa agire nessuno.

Il selettore **Perimetro** permette comunque di vedere tutto: i valori economici
cambiano, gli indicatori di rischio no — restano sulle aperte per costruzione.

## 5. Come leggere la divergenza

**Divergenza = consumo del budget − avanzamento temporale.**

| Divergenza | Significato |
|---|---|
| +35 | consumato il 35% in più di quanto il tempo giustifichi — **da guardare** |
| circa 0 | consumo e tempo procedono insieme — regolare |
| −30 | sotto consumo: forse la commessa è ferma |

Il rischio sta nella **distanza fra i due**, non nel singolo valore: 80% di budget
all'80% del tempo è regolare, 80% al 40% no.

## 6. Le schede commerciale

Selezionando un agente:

- il perimetro è dichiarato in testa — «300 commesse su 1.062, 57,6% del valore»
- la tabella di confronto fra agenti **scompare**

È deliberato: una scheda personale che riporta la classifica diventa uno strumento
di valutazione invece che di lavoro. Il confronto resta nel report direzionale.

## 7. Export e stampa

**XLSX**: quattro fogli — commesse, da presidiare, agenti (solo nel direzionale),
andamento.

**Stampa**: A4 orizzontale a colori. Per i colori delle aree attivare *«Grafica di
sfondo»* nel browser; i grafici sono SVG ed escono a colori comunque.

## 8. Rollback

Rimuovere `dir_report.php`, `app/DirModel.php`, `app/dir_report_print.php`,
ripristinare `MenuManager.php`, `Router.php`, `Version.php`, poi:

```sql
DROP VIEW IF EXISTS v_cm_dir_andamento;
DROP VIEW IF EXISTS v_cm_dir_attenzione;
DROP VIEW IF EXISTS v_cm_dir_agente;
DROP VIEW IF EXISTS v_cm_dir_commessa;
UPDATE app_settings SET setting_value='1.8.93'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
