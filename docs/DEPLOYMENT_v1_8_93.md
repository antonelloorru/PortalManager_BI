# Deployment — PortalManager v1.8.93

## 1. Contenuto

```
VERSION                          1.8.93
service_desk.php                 (ROOT)  riquadro aziende
it_service.php                   (ROOT)  dimensione Azienda esecutrice
app/SdModel.php                  + 1 metodo
app/ItServiceModel.php           dimensione e filtro
app/Version.php                  PM_VERSION = 1.8.93
gli altri file                   invariati da v1.8.92
sql/migration_v1_8_93.sql        v_cm_it_servizio con l'azienda
sql/upgrade_1_7_56_to_1_8_93.sql consolidato cumulativo (606 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in ROOT e i tre in `app\`.
3. SQL Runner: `sql/migration_v1_8_93.sql` (da v1.8.92) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: `exec_company_id` era già popolato su tutte le
commesse.

## 3. Verifica — Relazione di Servizio IT

Nuovo filtro **Azienda esecutrice** e nuova voce in *Raggruppa per*, con grafico e
foglio nell'export.

| Azienda | Interventi | Ore |
|---|---|---|
| WETECH'S SPA SB | 13.673 | 62.540,0 |
| Nis Group srl | 2.171 | 5.444,5 |
| Antea srl | 932 | 2.845,5 |
| Wenest SRL | 143 | 1.057,5 |
| Weenergy | 18 | 146,5 |

Combinabile: `Azienda × Codice linea` dà 43 righe con la stessa somma.

## 4. Verifica — Service Desk

Il riquadro per azienda **compare solo se le aziende sono più di una**.

Sui dati attuali il Service Desk lavora esclusivamente su commesse WETECH'S, quindi
resta nascosto: una tabella con una riga sola occuperebbe spazio senza dire nulla.

Il **foglio nell'export c'è comunque**: in un file di dati una riga sola è
un'informazione, non un ingombro.

Se un domani il Service Desk operasse anche su commesse di altre società, il
riquadro comparirebbe da solo.

## 5. Perché il nome e non il prefisso

Il prefisso è una convenzione di codifica — `WTS`, `NIS` — mentre il nome è
l'entità reale. `exec_company_id` era già risolto e verificato da `DatasetSync`:
estrarre di nuovo dal codice avrebbe duplicato una logica esistente, con il
rischio che le due divergano se la convenzione cambiasse.

## 6. Rollback

Ripristinare i cinque file dalla v1.8.92 e rieseguire la migration v1.8.91 per
riportare `v_cm_it_servizio` alla forma precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.92'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
