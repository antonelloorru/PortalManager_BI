# Deployment — PortalManager v1.8.79

**Correttiva urgente**: senza, la sincronizzazione fallisce sul dataset delle
allocazioni DGB.

## 1. Contenuto

```
VERSION                          1.8.79
app/SyncDatasets.php             deduplica a monte su 2 dataset
app/Version.php                  PM_VERSION = 1.8.79
gli altri file                   invariati da v1.8.78
sql/migration_v1_8_79.sql        pulizia + vincolo riapplicato + vista
sql/upgrade_1_7_56_to_1_8_79.sql consolidato cumulativo (552 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_79.sql` (da v1.8.78) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto**.

L'ordine conta: la migration ripulisce ciò che la sincronizzazione fallita può
aver lasciato a metà, e la nuova versione di `SyncDatasets.php` impedisce che si
ripresenti.

## 3. Verifica

```sql
SELECT * FROM v_dgb_allocazioni_duplicate;
SELECT * FROM v_cm_rapporti_doppi_attivita;
```

**Entrambe zero righe.**

```sql
SELECT COUNT(*) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND INDEX_NAME = 'uq_dfao_activity_operator';
```

Diverso da zero: il vincolo è attivo.

Poi **Sincronizza tutto**: i dataset **Ore per operatore su attività DGB** e
**Rapporti di intervento** devono riportare esito `ok`.

Se uno dei due torna in errore con *Duplicate entry*, la deduplica non ha
funzionato: mandatemi il messaggio completo.

## 4. Perché l'errore era comparso

Il vincolo introdotto dalla versione precedente respinge i duplicati invece di
accettarli. La sorgente ne contiene, e il dataset li inseriva entrambi perché la
sua chiave è `id` mentre il fatto è identificato dalla coppia
(attività, operatore).

**Il vincolo non ha creato il problema: lo ha reso visibile.** Prima passavano
in silenzio, ed è così che si erano formate le 77 allocazioni duplicate.

## 5. Il secondo dataset

Anche **Rapporti di intervento** legge la stessa tabella. Senza la correzione,
avrebbe continuato a generare due rapporti per lo stesso intervento — le ore
sarebbero tornate a raddoppiare pur avendo sistemato le allocazioni.

## 6. La ripartizione dei valori

In `rapporti`, il conteggio degli operatori per attività passa da `COUNT(*)` a
`COUNT(DISTINCT id_operator)`. Serve a ripartire i valori economici
dell'attività: con un duplicato, la quota di ciascun operatore risultava
inferiore al dovuto.

Dopo la sincronizzazione, i valori economici per operatore possono quindi
**aumentare leggermente** sulle attività che avevano duplicati.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_rapporti_doppi_attivita;
UPDATE app_settings SET setting_value='1.8.78'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare `app/SyncDatasets.php`. **Attenzione**: senza la deduplica a monte
la sincronizzazione tornerà a fallire.
