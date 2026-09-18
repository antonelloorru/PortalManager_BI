# Deployment — PortalManager v1.9.12

## 1. Contenuto

```
VERSION                           1.9.12
app/SyncDatasets.php              filtro stati + colonna stato
app/Version.php                   PM_VERSION = 1.9.12
gli altri file                    invariati da v1.9.11
sql/migration_v1_9_12.sql         1 colonna + 1 indice + 2 viste + 1 parametro
sql/upgrade_1_7_56_to_1_9_12.sql  consolidato cumulativo
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_12.sql` (da v1.9.11) oppure il consolidato.
4. **Stop + Start Apache**.
5. **Risincronizzare i rapporti di intervento** — vedi punto 3.

## 3. La risincronizzazione è necessaria

**Gestione Commesse → Sincronizzazione Gestionale → Sincronizza tutto.**

Senza, non succede nulla di visibile:

- i moduli **già presenti** non hanno `source_status`, perché la colonna non
  esisteva quando sono stati importati
- i moduli **mai importati** — i 3 di Bressi e tutti gli altri scartati per stato
  — entrano solo alla prossima sincronizzazione

## 4. Verifica

**I 3 moduli di Bressi:**

```sql
SELECT COUNT(*) FROM cm_intervention_reports
 WHERE technician_raw LIKE 'Bressi%'
   AND report_date BETWEEN '2026-01-01' AND '2026-08-31';
```

Attesi **307**, come nell'export del gestionale.

**La ripartizione per stato:**

```sql
SELECT stato, moduli, quota_pct, ore, tecnici, ammesso
  FROM v_cm_ir_stati ORDER BY moduli DESC;
```

**Guardate la riga `OPEN`**: vale 327.892 righe sull'intero gestionale. Se dopo la
risincronizzazione risultasse molto pesante, il portale starebbe contabilizzando
lavoro non ancora svolto — è la ragione per cui questa vista esiste.

## 5. Un dato che merita una decisione

**`APPROVED` non esiste nel gestionale**: zero occorrenze su tutto il dump.

Era uno dei tre stati del vecchio filtro, quindi un terzo dell'elenco filtrava su
un valore inventato. Non è più nell'elenco, ma la cosa dice qualcosa su come era
stato scritto.

**`REJECTED` è invece incluso** per vostra indicazione: significa contabilizzare
anche il lavoro respinto. Se non fosse voluto:

```sql
UPDATE app_settings
   SET setting_value = 'OPEN,ASSIGNED,COMPLETED,ACTIVE,PENDING,CLOSED,SUSPENDED,NOT_CLOSED,PASSED,TO_BE_DONE'
 WHERE setting_key = 'sync_stati_moduli';
```

**Attenzione**: il parametro documenta la scelta, ma il filtro operativo è nella
query di `app/SyncDatasets.php`. Cambiare il parametro senza cambiare la query non
ha effetto sulla sincronizzazione — la vista `v_cm_ir_stati` segnalerà la
divergenza nella colonna `ammesso`.

## 6. Perché il difetto era invisibile

Il portale non conservava lo stato del modulo. Una riga scartata dal filtro non
lasciava traccia: non si poteva sapere né quante ne mancassero né perché.

Ve ne siete accorti confrontando un export del gestionale con il portale — che è
l'unico modo che c'era.

Ora lo stato è in tabella, e `v_cm_ir_copertura_tecnico` permette di ripetere quel
confronto senza uscire dal portale.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_ir_copertura_tecnico;
DROP VIEW IF EXISTS v_cm_ir_stati;
ALTER TABLE cm_intervention_reports DROP COLUMN IF EXISTS source_status;
DELETE FROM app_settings WHERE setting_key = 'sync_stati_moduli';
UPDATE app_settings SET setting_value='1.9.11'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

I moduli entrati con la nuova regola **restano**: rimuovere la colonna non li
cancella. Per tornare indietro davvero servirebbe cancellarli, e non è una cosa
che una migration debba fare da sola.
