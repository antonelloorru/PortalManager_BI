# Deployment — PortalManager v1.8.72

**Release correttiva**: senza, in "Distribuzione sulle 24 ore" compaiono avvisi
PHP e l'export non funziona.

## 1. Contenuto

```
VERSION                          1.8.72
dgb_activities.php               (ROOT)  correzione ordine definizioni
app/SyncDatasets.php             divisione sulle commesse
app/Version.php                  PM_VERSION = 1.8.72
gli altri file                   invariati da v1.8.71
sql/migration_v1_8_72.sql        division_code + vista per divisione
sql/upgrade_1_7_56_to_1_8_72.sql consolidato cumulativo (502 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i tre file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_72.sql` (da v1.8.71) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto**, per popolare la divisione
   sulle commesse.

## 3. Verifica della correzione

**Attività & Rendicontazione DGB** → **Giorni (mese)**.

| Controllo | Atteso |
|---|---|
| Sopra la matrice | **nessun avviso PHP** |
| Legenda | quattro voci: cliente ordinario/reperibilità, interno ordinario/reperibilità |
| Pulsante **XLSX** | scarica il file senza errori |

L'export non funzionava per lo stesso difetto: l'avviso veniva stampato prima
delle intestazioni HTTP e corrompeva il file.

## 4. Verifica della divisione

```sql
SELECT division_code, COUNT(*) FROM cm_projects GROUP BY division_code ORDER BY 2 DESC;
```

Attese: Sistemistica la maggioranza, poi NIS, ANT, Assistenza Tecnica, WeSecure.

```sql
SELECT divisione, commesse, ore, costo, margine_pct, costo_medio_orario
  FROM v_cm_divisione_analisi ORDER BY ore DESC;
```

Le **commesse non assegnate** sono quelle non ancora riconciliate con il
gestionale: dovrebbero ridursi dopo la sincronizzazione.

## 5. Sul legame divisione–tecnico

Non esiste come appartenenza. `dgb_operator_can_see_forms_division` è un permesso
di visibilità: nessun operatore ha una sola divisione, il minimo è due e la media
quattro.

L'analisi per struttura passa quindi dalla **commessa**, che ha la divisione
valorizzata su tutte le 808 righe del gestionale. Le ore di una divisione sono
quelle spese sulle sue commesse.

## 6. Tre divisioni senza commesse

Laboratorio, WENEST e WeEnengys esistono in anagrafica ma non hanno commesse
associate. Prima di leggerle come «divisioni improduttive» conviene verificare se
siano strutture dismesse, nuove, o che operano su commesse attribuite ad altre
divisioni.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_divisione_analisi;
ALTER TABLE cm_projects DROP COLUMN IF EXISTS division_code;
UPDATE app_settings SET setting_value='1.8.71'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare anche i tre file. Attenzione: tornare indietro ripristina anche gli
avvisi PHP.
