# Deployment — PortalManager v1.8.49

Il pacchetto è **cumulativo** e comprende la v1.8.48: se quella non è stata
ancora installata, questa la include.

## 1. Contenuto

```
VERSION                          1.8.49
tech_registry.php                (ROOT)  Anagrafica Tecnica (da v1.8.48)
tech_units.php                   (ROOT)  Unità Organizzative (da v1.8.48)
sync_commesse.php                (ROOT)  + analisi completa della sorgente
project_dashboard.php            (ROOT)  colonna Rapporto collegata a DGB
dgb_activities.php               (ROOT)  campo di ricerca per codice
app/SourceDb.php                 + inventory() e currentSchema()
app/SyncDatasets.php             + tablesOf(), allTables(), coverage()
app/DatasetSync.php              invariato da v1.8.46
app/DgbModel.php                 filtro per codice attività o ticket
app/MenuManager.php              voci DGB, Anagrafica Tecnica, Unità
app/Router.php                   rotte allineate
app/Version.php                  PM_VERSION = 1.8.49
sql/migration_v1_8_49.sql        permessi pagina DGB + bump
sql/upgrade_1_7_56_to_1_8_49.sql consolidato cumulativo (345 statement)
docs/                            questa documentazione
```

**`MenuManager.php` e `Router.php` vanno sovrascritti con quelli di questo
pacchetto**, non con quelli della v1.8.48: la v1.8.48 conteneva versioni
anteriori che avrebbero rimosso la voce "Sincronizzazione gestionale".

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_49.sql` (se la v1.8.48 è già installata)
   oppure `sql/upgrade_1_7_56_to_1_8_49.sql` (in ogni altro caso).
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.49` |
| Menu Gestione Commesse | contiene **Attività & Rendicontazione DGB** |
| Idem | contiene ancora **Sincronizzazione gestionale** |
| Idem | contiene **Anagrafica Tecnica** e **Unità Organizzative Tecniche** |
| Apertura di Attività & Rendicontazione DGB | pagina funzionante |
| Sincronizzazione gestionale | in testa il riquadro *Analisi del database sorgente* |
| **Analizza tutte le tabelle** | conteggi di oggetti, tabelle, viste, usate, mancanti |
| Elenco completo degli oggetti | espandibile, righe usate evidenziate in verde |

Sulla sorgente attuale l'analisi deve riportare **111 oggetti (102 tabelle, 9
viste)** e **0 mancanti**. Un numero diverso da zero in *Mancanti* indica che
una tabella richiesta è stata rinominata o spostata: il messaggio elenca quali.

## 4. Note

L'analisi legge dai cataloghi di sistema e non tocca i dati, quindi si può
eseguire in qualunque momento, anche in orario di lavoro.

Il numero di righe è la stima del motore, non un conteggio esatto: serve
all'ordine di grandezza. Su MySQL/MariaDB la stima può discostarsi
sensibilmente dal reale finché non viene eseguito `ANALYZE TABLE`.

Le nove viste presenti nella sorgente compaiono fra gli oggetti non utilizzati.
Alcune — in particolare `v_contract_export_list` — hanno una struttura vicina ai
tracciati già in uso: sono candidate per nuovi dataset, valutabili in una
prossima release.

## 5. Rollback

Ripristinare i file dalla copia precedente e riportare l'etichetta:

```sql
UPDATE app_settings SET setting_value='1.8.48'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

I permessi aggiunti per `dgb_activities.php` possono restare: la pagina esisteva
già e continuava a funzionare per chi vi accedeva per indirizzo diretto.
