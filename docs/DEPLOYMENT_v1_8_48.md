# Deployment — PortalManager v1.8.48

Da applicare a un'installazione **v1.8.47**.

## 1. Contenuto del pacchetto

```
VERSION                          1.8.48
tech_registry.php                (ROOT)  NUOVA  Anagrafica Tecnica
tech_units.php                   (ROOT)  NUOVA  Unità Organizzative Tecniche
project_dashboard.php            (ROOT)  colonna Rapporto collegata a DGB
dgb_activities.php               (ROOT)  campo di ricerca per codice
app/DgbModel.php                 filtro per codice attività o ticket
app/MenuManager.php              due nuove voci di menu
app/Router.php                   due nuove pagine routabili
app/Version.php                  PM_VERSION = 1.8.48
sql/migration_v1_8_48.sql        4 tabelle, tassonomia, legame DGB, permessi
sql/upgrade_1_7_56_to_1_8_48.sql consolidato cumulativo (342 statement)
docs/                            questa documentazione
```

I file `.php` di primo livello nella radice, quelli di `app/` in `app\`.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_48.sql` (da v1.8.47) oppure
   `sql/upgrade_1_7_56_to_1_8_48.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

### Tempi della migration

La migration crea un indice su `dgb_forms_activity.code` e poi popola il legame
fra rapporti e attività. Su decine di migliaia di righe l'indice richiede
qualche decina di secondi e la UPDATE altrettanto.

**L'indice va creato prima della UPDATE**: la migration lo fa nell'ordine
corretto, ma se si eseguono gli statement a mano occorre rispettarlo, altrimenti
la UPDATE finisce in lock wait timeout.

Se il SQL Runner va in timeout, aumentare `max_execution_time` in `php.ini`
oppure eseguire il file da phpMyAdmin → Importa.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.48` |
| Menu Gestione Commesse | compaiono **Anagrafica Tecnica** e **Unità Organizzative Tecniche** |
| Unità Organizzative Tecniche | 9 unità, 24 sotto-unità |
| Espandere un'unità | elenco delle sue sotto-unità |
| Anagrafica Tecnica | elenco con interni ed esterni, schede di riepilogo in testa |
| Classificare una persona | salvataggio riuscito, l'unità compare in elenco |
| Assegnare una sotto-unità di un'altra unità | rifiutato con messaggio |
| Salvare spuntando "Registra nello storico" | compare la tabella dello storico |
| Registrare una seconda variazione | la precedente si chiude il giorno prima |
| Scheda commessa → tab Consuntivo | colonna "Rapporto / Codice DGB" |
| Cliccare un codice | si apre Attività & Rendicontazione filtrata su quel codice |

Controllo rapido del legame:

```sql
SELECT COUNT(*) AS rapporti,
       SUM(dgb_activity_id IS NOT NULL) AS legati
  FROM cm_intervention_reports;
```

Sui rapporti importati da DGB i due numeri devono coincidere.

## 4. Primo utilizzo

Le nove unità precaricate coprono i profili indicati. Se la vostra struttura è
diversa, si modificano o disattivano da Unità Organizzative Tecniche prima di
classificare le persone.

Dopo la migration tutte le persone risultano **da classificare**: il contatore
rosso in testa all'Anagrafica Tecnica indica quante sono, e il filtro dedicato le
isola per lavorarci.

## 5. Rollback

Rimuovere `tech_registry.php` e `tech_units.php`, ripristinare gli altri file
dalla copia precedente, poi:

```sql
DELETE FROM role_permissions WHERE page_name IN ('tech_registry.php','tech_units.php');
UPDATE app_settings SET setting_value='1.8.47'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le quattro tabelle nuove e le colonne `dgb_activity_*` possono restare: sono
inerti senza le pagine che le usano. Per rimuoverle:

```sql
DROP TABLE cm_tech_history, cm_tech_profiles, cm_tech_subunits, cm_tech_units;
```

Stop + Start Apache, Ctrl+F5.
