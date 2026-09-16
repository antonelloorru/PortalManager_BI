# Deployment — PortalManager v1.8.92

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.92
service_desk.php                 (ROOT)  riquadro codici linea
it_service.php                   (ROOT)  dimensione Codice linea
app/SdModel.php                  + 2 metodi
app/ItServiceModel.php           dimensione e filtro sul codice
app/Version.php                  PM_VERSION = 1.8.92
gli altri file                   invariati da v1.8.91
sql/migration_v1_8_92.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_92.sql consolidato cumulativo (603 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in ROOT e i tre in `app\`.
3. SQL Runner: `sql/migration_v1_8_92.sql` (da v1.8.91) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: il codice era già nei dati, mancava solo l'esposizione.

## 3. Verifica — Relazione di Servizio IT

Nel pannello dei filtri compare **Codice linea** accanto a «Linee di servizio»;
in *Raggruppa per* compare la stessa voce.

Raggruppando per codice, attese circa **19 righe**:

| Codice | Ore |
|---|---|
| WTS-PRES | ~29.100 |
| NV_AI | ~10.860 |
| WTS-CSS | ~10.790 |

Un nuovo grafico **Ore per codice linea** e un foglio nell'export.

## 4. Verifica — Service Desk

Nuovo riquadro **Moduli di intervento per codice linea**, sopra le tabelle
finali. Attesi 14 codici per un totale di **11.908,5 ore**.

Aprendo la scheda di un componente il riquadro **si restringe ai suoi codici**.

Il codice compare anche nella tabella per tipologia di contratto e nel report di
stampa.

## 5. Codice o etichetta?

Sono la stessa linea. Il **codice** — `WTS-ACM` — è quello che trova sui documenti
e nel gestionale; l'**etichetta** — «Chiavi in mano» — è leggibile senza conoscere
i codici.

Si possono usare insieme: raggruppando per *Codice linea × Linea di servizio* si
ottiene una riga con entrambi.

## 6. Sul campo «Tipo»

La colonna `cm_projects.project_type` esiste ma è **vuota su tutte e 1.062 le
commesse**: nessuna sincronizzazione la popola.

Non l'ho esposta come filtro perché avrebbe un solo valore possibile. Se nel
gestionale c'è un campo corrispondente, va prima aggiunto ai dataset di
sincronizzazione: mi indichi la tabella e la colonna.

## 7. Rollback

Ripristinare i cinque file dalla v1.8.91, poi:

```sql
UPDATE app_settings SET setting_value='1.8.91'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
