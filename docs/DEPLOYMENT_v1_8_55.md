# Deployment — PortalManager v1.8.55

Release **additiva**: la migration aggiunge soglie e quattro viste. Nessun dato
viene modificato dall'aggiornamento — le modifiche ai profili avvengono solo se
si lancia la funzione dalla pagina.

## 1. Contenuto

```
VERSION                          1.8.55
tech_registry.php                (ROOT)  pannello + funzione di applicazione
app/Version.php                  PM_VERSION = 1.8.55
gli altri file                   invariati da v1.8.54
sql/migration_v1_8_55.sql        soglie + 4 viste
sql/upgrade_1_7_56_to_1_8_55.sql consolidato cumulativo (399 statement)
docs/                            questa documentazione
```

## 2. Prerequisiti

Richiede la **v1.8.53** (classificazione ordinario/reperibilità) e la **v1.8.48**
(tabelle dell'Anagrafica Tecnica). Il consolidato le comprende entrambe.

## 3. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_55.sql` (da v1.8.54) oppure
   `sql/upgrade_1_7_56_to_1_8_55.sql`.
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

## 4. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.55` |
| Anagrafica Tecnica | compare **Reperibilità rilevata dai consuntivi** |
| Aprendo il pannello | elenco con giornate, ore, notti, festive, flag proposti |
| Colonna *Stato attuale* | *da attivare*, *già corretto* o *profilo assente* |

Controllo da SQL:

```sql
SELECT * FROM v_tech_oncall_riepilogo;
```

Sui dati attuali sono attesi **103 tecnici proposti reperibili**, di cui **23**
anche H24.

## 5. Prima esecuzione

Il pulsante **Applica il flag** imposta la reperibilità ai tecnici elencati.

Alla prima esecuzione **nessuno ha un profilo tecnico**, perché l'Anagrafica
Tecnica è recente. Lasciare spuntata l'opzione *Crea il profilo tecnico dove
manca*, altrimenti la funzione non applica nulla.

Esito atteso: **103 profili creati**, 0 aggiornati, 0 saltati.

L'operazione è **ripetibile**: rilanciandola non cambia nulla se i dati non sono
cambiati. Ogni esecuzione è registrata nell'event log.

## 6. Che cosa la funzione non fa

**Non rimuove mai il flag.** Un periodo senza chiamate non prova che il ruolo sia
cessato, e un tecnico di turno che non riceve interventi non lascia traccia nei
consuntivi. Le disattivazioni restano manuali, dalla scheda del singolo tecnico.

**Non tocca gli altri campi** del profilo: unità organizzativa, seniority e
competenze restano come sono.

## 7. Soglie

In `app_settings`: `oncall_min_days` (5), `oncall_window_months` (12),
`oncall_h24_min_days` (5), `oncall_night_from` (22), `oncall_night_to` (6).

Come per le release precedenti, i valori sono presenti anche nelle viste SQL:
modificarli nei parametri documenta la nuova soglia ma richiede una piccola
release per applicarla.

## 8. Rollback

Ripristinare `tech_registry.php` dalla copia precedente, poi:

```sql
DROP VIEW IF EXISTS v_tech_oncall_riepilogo;
DROP VIEW IF EXISTS v_tech_oncall_proposta;
DROP VIEW IF EXISTS v_tech_oncall_evidenze;
UPDATE app_settings SET setting_value='1.8.54'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

I flag già applicati restano: sono dati dell'anagrafica, non della funzione. Per
azzerarli:

```sql
UPDATE cm_tech_profiles SET on_call = 0, on_call_h24 = 0;
```
