# Deployment — PortalManager v1.8.84

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.84
service_desk.php                 (ROOT)  NUOVO — la pagina
app/SdModel.php                  NUOVO — letture
app/MenuManager.php              voce di menu
app/Router.php                   slug della pagina
app/Version.php                  PM_VERSION = 1.8.84
gli altri file                   invariati da v1.8.83
sql/migration_v1_8_84.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_84.sql consolidato cumulativo (574 statement)
docs/                            questa documentazione
```

**Prerequisiti**: v1.8.82 e v1.8.83 applicate, dataset *Service Desk — messaggi
ticket* sincronizzato.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i quattro file in `app\`.
3. SQL Runner: `sql/migration_v1_8_84.sql` (da v1.8.83) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Gestione Commesse → Service Desk**.

| Elemento | Atteso |
|---|---|
| Quattro schede in testa | ticket, presi in carico, tasso escalation, da presidiare |
| Ripartizione | sei classi con barra proporzionale |
| Andamento | grafico a due serie su dodici mesi |
| Da presidiare | elenco ordinato per anzianità |
| Operatori e code | due tabelle affiancate |

Sull'intero periodo (dal 2023): **3.512 ticket, 1.470 presi in carico, 7,1% di
escalation, 14 da presidiare**.

## 4. Se compare l'avviso sul team

*«Nessun tecnico assegnato all'unità Service Desk»* significa che
`cm_tech_profiles.unit_id` non punta all'unità per nessuno.

Senza assegnazione **ogni ticket risulta gestito da specialisti** e il tasso di
escalation non ha significato. Assegnare i tecnici in *Unità Organizzative
Tecniche*: la modifica si riflette subito, senza risincronizzare.

## 5. Come leggere il tasso di escalation

Il denominatore sono i ticket **presi in carico dal Service Desk** — risolti più
scalati — non il totale.

Le prese in carico dirette da specialisti (42% dei ticket) non vi rientrano:
nascono su code specialistiche e non sono mai passate dal primo livello.

Includerle porterebbe il tasso dal 7,1% al 3,6%: un numero più lusinghiero che
descrive una realtà diversa.

## 6. Le tre classi «senza risposta»

Hanno colori distinti perché hanno significati opposti:

| Classe | Colore | Azione |
|---|---|---|
| lavorato senza risposta scritta | grigio | nessuna: risolto per altra via |
| cliente senza risposta scritta | ambra | verificare quelli aperti |
| mai preso in carico | **rosso** | **intervenire** |

## 7. Filtri

Il filtro **livello** seleziona i ticket *toccati* da quel livello, non una
proprietà del ticket: uno stesso ticket può comparire sia con L1 sia con L2, se
entrambi vi hanno lavorato.

## 8. Rollback

Rimuovere `service_desk.php` e `app/SdModel.php`, ripristinare `MenuManager.php`,
`Router.php` e `Version.php`, poi:

```sql
UPDATE app_settings SET setting_value='1.8.83'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le viste restano: sono della v1.8.82/83 e interrogabili da SQL.
