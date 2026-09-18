# Release Notes — PortalManager v1.9.44

Data: 2026-09-09
Allineamento versioni: Software 1.9.44 · Schema 1.9.44 · Upgrade 1.9.44
Modulo: Sincronizzazione gestionale · File: `app/DatasetSync.php`

## Bug fix — errore 1062 in sincronizzazione
Sintomo: `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
'77772-2474' for key 'uq_dfao_activity_operator'`.

Dataset coinvolto: **allocazioni_dgb** → target `dgb_forms_activity_operator`.
La tabella ha una UNIQUE COMPOSTA `uq_dfao_activity_operator (id_activity, id_operator)`
(v1.8.78), ma la chiave del dataset è il surrogato `id`. Il writer generico verificava
l'esistenza solo su `id`: quando in sorgente la coppia (attività, operatore) cambia `id`
— la deduplica a monte tiene `MAX(id)` — il controllo su `id` non trovava la riga già
presente a destinazione e l'`INSERT` collideva con la UNIQUE composta.
I due numeri dell'errore sono i valori della coppia: `77772` = id_activity, `2474` =
id_operator.

## Correzione (solo `app/DatasetSync.php`)
Il ramo `INSERT` del writer generico (`writeRows()`) è ora un upsert idempotente:
`INSERT ... ON DUPLICATE KEY UPDATE`. Riconcilia sulla UNIQUE reale — qualunque essa
sia, singola o composta — aggiornando la riga esistente (regola "ultimo vince", coerente
con la deduplica a monte). Dettagli:
- la chiave del dataset (`$keyF`) non viene mai modificata (per allocazioni_dgb `id`
  resta quello già registrato);
- le celle vuote non sovrascrivono i valori esistenti, come nel ramo UPDATE; il confronto
  del vuoto è su `CAST(... AS CHAR)` per non violare lo strict mode sulle colonne numeriche;
- il conteggio insert/update resta corretto via `ROW_COUNT()` (2 = aggiornata, 1 = inserita).

Nessuna modifica di schema. La correzione vale per TUTTI i dataset del modulo, non solo
allocazioni_dgb: qualunque target con UNIQUE non coincidente con la chiave del dataset
diventa re-run-safe.

## Contenuto pacchetto
```
VERSION                                 1.9.44
app/DatasetSync.php                     writeRows(): INSERT -> ON DUPLICATE KEY UPDATE
sql/migration_v1_9_44.sql               allineamento versione (nessun delta schema)
sql/upgrade_1_9_42_to_1_9_44.sql        consolidato ultime 2 versioni -> 1.9.44
docs/                                   changelog, manuali, deployment, technical design
```

## QA
- `php -l app/DatasetSync.php` OK.
- Riproduzione in strict mode su `dgb_forms_activity_operator` con
  `uq_dfao_activity_operator`: coppia esistente sotto nuovo `id` → nessun 1062, riga
  aggiornata (ROW_COUNT=2); testo vuoto non sovrascrive; coppia nuova inserita (ROW_COUNT=1).
- SQL migration/consolidato: RUN1/RUN2 err=0; `;` nei commenti = 0; versioni → 1.9.44.
