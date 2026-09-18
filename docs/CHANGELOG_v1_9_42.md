# Release Notes — PortalManager v1.9.42

Data: 2026-09-07
Allineamento versioni: Software 1.9.42 · Schema 1.9.42 · Upgrade 1.9.42
Sezione: Relazione di Servizio IT — Riepilogo / Dettaglio per Codice Contratto
File: `app/ItServiceModel.php`

## Bug fix — nessun dato nel Riepilogo per Codice Contratto
Sintomo: "Nessun dato per il periodo … verifica la sincronizzazione DGB", su qualunque
intervallo, pur con rapportini presenti.

Causa: `riepilogoContratto()` e `dettaglioCommessa()` univano `dgb_forms_contract` in
**INNER JOIN**. In produzione quella tabella lookup è **vuota** (non ancora sincronizzata),
quindi ogni attività veniva scartata — benché `dgb_forms_activity` contenga i dati
(nel dump: 409 contratti, 19.797 attività, 85.534 ore nel periodo 01–08/2026).
Il `catch(Throwable){return []}` trasformava il risultato vuoto nel banner di
"sincronizzazione DGB".

## Correzione (solo `app/ItServiceModel.php`)
- `dgb_forms_contract` e `dgb_operator` → **LEFT JOIN** (le attività non spariscono se
  manca la riga di lookup o l'operatore).
- Identità del contratto spostata su **`a.id_contract`** (chiave sempre presente).
- Etichetta contratto: `c.code` quando disponibile, altrimenti `cm_projects.project_code`
  (via `dgb_contract_id`), altrimenti `Contratto #<id>`.
- Cliente ricavato da `COALESCE(c.id_customer_comp, a.id_customer_comp)`.
- `cm_projects` unito tramite derivata a una riga per contratto → nessun fan-out sui totali.
- `riepilogoContratto`: `GROUP BY a.id_contract`, etichette in `MAX()`.

Forward-compatible: appena la sincronizzazione di `dgb_forms_contract` viene ripristinata,
le etichette tornano a usare automaticamente `c.code`/descrizione/cliente reali.

## Contenuto pacchetto
```
VERSION                              1.9.42
app/ItServiceModel.php               fix Riepilogo/Dettaglio per Codice Contratto
sql/migration_v1_9_42.sql            allineamento versione (nessun delta schema)
sql/upgrade_1_9_40_to_1_9_42.sql     consolidato ultime 2 versioni -> 1.9.42
docs/                                changelog, manuali, deployment, technical design
```

## QA
- `php -l app/ItServiceModel.php` OK.
- Query `riepilogoContratto` eseguita sui dati del dump: **409 gruppi** (prima 0),
  85.534 ore ordinarie, € 2.352.692; etichette da project_code (WTS_3016, …).
- SQL migration/consolidato: RUN1/RUN2 err=0; `;` nei commenti = 0; versioni → 1.9.42.
