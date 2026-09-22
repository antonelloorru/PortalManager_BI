# Technical Design — PortalManager v1.8.41

## 1. Il problema in una frase

Due istanze dello stesso portale, stesso codice, stessa versione, dati divergenti:
in una le commesse sono quelle reali del gestionale, nell'altra soltanto i
segnaposto generati dalla sincronizzazione con DogoBit.

## 2. Come nasce un segnaposto

`app/DgbSync.php` allinea le attività DogoBit ai rapporti di intervento del
portale. Ogni rapporto deve agganciarsi a una commessa, quindi la sincronizzazione
crea preventivamente una riga in `cm_projects` per ogni `id_contract` che non ne
abbia già una:

```php
$code = ($ct && $ct['code']) ? $ct['code'] : ('DGB-' . $cid);
$name = ($ct && $ct['name']) ? $ct['name'] : ('Contratto DogoBit #' . $cid . ...);
```

Il ramo di sinistra legge il codice reale da `dgb_forms_contract`. In entrambe le
istanze quella tabella è **vuota**, quindi si imbocca sempre il fallback e nasce
`DGB-<id>` con nome `Contratto DogoBit #<id>`.

Il segnaposto non è un errore: è un ancoraggio provvisorio che permette ai rapporti
di esistere prima che il file commesse sia disponibile. Diventa un problema quando
il file arriva e nessuno chiude il cerchio.

## 3. Perché il cerchio non si chiudeva

`import_commesse.php` faceva UPSERT su `project_code`. Il segnaposto ha codice
`DGB-77`, la commessa reale ha codice `WTS_3016`: due chiavi diverse, quindi due
righe. La riga reale nasceva completa di tutti i 29 campi ma senza un solo rapporto;
la riga segnaposto conservava i 6.805 rapporti ma nessun dato.

Da qui il sintomo osservato: elenco con codici `DGB-*`, colonne economiche vuote,
Gantt senza barre, carico risorse riferito a commesse inesistenti.

In `demo_portalmanager` la riconciliazione era stata fatta a valle. In
`portalmanager` no, e l'import non era mai stato eseguito.

## 4. La chiave di riconciliazione

`cm_projects.dgb_contract_id` è l'unico attributo che accomuna le due righe:

```
demo:  WTS_3016   dgb_contract_id=77   external_link=.../contract/editV2/77
prod:  DGB-77     dgb_contract_id=77   external_link=.../contract/editV2/77
```

È anche ricavabile dal link con una sola espressione regolare, il che rende la
riconciliazione possibile perfino su righe importate senza `dgb_contract_id`:

```php
preg_match('~/contract/editV2/(\d+)~', $link, $m)
```

Copertura misurata: 776 dei 778 segnaposto hanno una commessa reale corrispondente.
I due residui (`DGB-1140`, `DGB-1147`, con 3 rapporti complessivi) sono contratti
più recenti del file commesse e vanno conservati: rimuoverli avrebbe orfanato i
loro rapporti.

## 5. Le due correzioni

### 5.1 Correzione dei dati — `sql/migration_v1_8_41.sql`

```
clients (305) ─┐
               ├─► cm_projects (1.062 commesse reali, 29 campi)
client_loc (296)┘            │
                             │ dgb_contract_id
                             ▼
                   tmp_dgb_remap (old_id → new_id)
                             │
        ┌────────────────────┼────────────────────┐
        ▼                    ▼                    ▼
 cm_intervention_reports  cm_team, cm_timesheet_entries,
 (67.786 righe)           cm_presales_effort, cm_workflow_steps,
                          cm_project_band_rates, cm_project_updates,
                          cm_project_update_files, cm_project_phases,
                          cm_alias_project, cm_alias_band
                             │
                             ▼
              DELETE dei soli segnaposto rimappati
```

**Nessun ID interno attraversa il confine fra le due istanze.** Ogni riferimento è
risolto per chiave naturale: il cliente per ragione sociale
(`SELECT id FROM clients WHERE name=...`), la commessa per `project_code`, il
segnaposto per `dgb_contract_id`. È questa scelta a rendere lo script rieseguibile
e indipendente dagli auto_increment locali, che nelle due istanze divergono.

La tabella ponte `tmp_dgb_remap` viene creata e distrutta dentro lo script: non
lascia residui e si ricostruisce a ogni esecuzione, il che preserva l'idempotenza.

### 5.2 Correzione della causa — `import_commesse.php`

L'import assorbe i segnaposto invece di affiancarli:

1. `dgb_contract_id` derivato dal link e scritto in `INSERT`, con `COALESCE` in
   `ON DUPLICATE KEY UPDATE` per non azzerare un valore già noto;
2. dopo l'upsert, `absorb($realId, $cid)` cerca i segnaposto con lo stesso
   contratto, sposta i loro riferimenti sulla commessa reale e li elimina;
3. il conteggio finisce nell'esito a video e nell'event log.

La ricerca è vincolata a `project_code LIKE 'DGB-%' AND id <> $realId`: solo i
segnaposto vengono assorbiti, mai una commessa reale, e mai la riga stessa.

## 6. Idempotenza

| Script | RUN1 | RUN2 |
|---|---|---|
| `migration_v1_8_41.sql` | 63 statement, err=0 | 63 statement, err=0 |
| `upgrade_1_7_56_to_1_8_41.sql` | 313 statement, err=0 | 313 statement, err=0 |
| `import_commesse.php` (assorbimento) | 776 assorbiti | 0 assorbiti, stato invariato |

Al secondo passaggio la migration trova `tmp_dgb_remap` vuota (non esistono più
segnaposto rimappabili) e gli `ON DUPLICATE KEY UPDATE` riscrivono valori identici.

## 7. Vincoli di integrità rispettati

- `cm_intervention_reports.project_id` non resta mai NULL: la rimappatura precede
  sempre la `DELETE`, e la `DELETE` colpisce solo righe già rimappate
  (`JOIN tmp_dgb_remap`).
- La `DELETE` porta anche il predicato `project_code LIKE 'DGB-%'` come rete di
  sicurezza: anche se la tabella ponte contenesse un id sbagliato, una commessa
  reale non verrebbe toccata.
- `client_locations` usa `INSERT IGNORE` sulla chiave unica
  `(client_id, location_name)`.

## 8. Cosa resta fuori dall'allineamento

`cm_team` (34 righe in demo) dipende da `employees.id`, e le due anagrafiche
dipendenti non sono sovrapponibili: solo 217 id su 286 coincidono per nome e
cognome. Trasferire quelle righe avrebbe associato persone sbagliate alle commesse.
Il team è dato derivabile: si rigenera dalla scheda commessa con *Sincronizza team
dai rapporti*, che ora opera su commesse corrette.

Analogamente `cm_professionals` (247 contro 246) e `cm_import_batches` (storico
degli import, per definizione locale a ciascuna istanza) restano invariati.
