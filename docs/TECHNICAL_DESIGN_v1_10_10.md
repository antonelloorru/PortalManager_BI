# TECHNICAL DESIGN — v1.10.10

## Relazioni sul DB SOC (sola lettura)
```
tt_article a ──id_tt_ticket──▶ tt_ticket tk ──id_tt_category──▶ tt_category c ──[id_parent]──▶ tt_category cp
     │ id_author ──▶ dgb_operator o            │
     │ id_tt_queue ──▶ tt_queue q
```
`SocIngest::defaultSql(SourceDb)`: verifica le colonne con `SourceDb::columnsOf()`; restituisce `[sql, nota]`.
Espressione: `NULLIF(TRIM(c.name),'')`, con padre `CASE WHEN cp.id IS NULL THEN … ELSE CONCAT(cp.name,' › ',c.name) END AS categoria`.
`extractQuery($cfg, $days, $src)`: query personalizzata se presente, altrimenti `defaultSql($src)`.

## Ingestion
`categoria` → `cm_soc_events.category` (campo ticket: ultimo valore non vuoto) → `cm_soc_tickets.category` in `rebuild()`.
Una categoria cambiata sulla sorgente aggiorna gli eventi (contati come «aggiornate») e il ticket.
`SocSync::run`: con `soc.full_resync = 1` legge con finestra 0 (tutto), poi riporta a 0 se la lettura non è in errore.

## Filtri (SocModel)
`normFilters`: `categoria` = array (`listParam`: select multiple o stringa con virgole, max 50 valori).
`where`: `(t.category IN (…) OR COALESCE(t.category,'') = '')` per «(non indicato)».
Tutte le viste usano `where($f)`: headline, trend, trendGiornaliero, breakdown, presidio, tickets, team, teamCategorie, trendCategorie, clienti, commessePm, export.

## Nuovi metodi
| Metodo | Uscita |
|---|---|
| `SocModel::teamCategorie($f)` | `cats` (ordinate per volume), `tot`, `rows[{nome, tot, c[cat]}]` |
| `SocModel::trendCategorie($f, 12)` | `months[]`, `series[{cat, values[]}]` ticket aperti per mese |
| `SocModel::listParam($v)` | array normalizzato |

## Un solo blocco filtri
`$GLOBALS['PM_NO_AUTOFILTER'] = true` prima di `header.php`: `footer.php` non aggancia `ListFilter::renderAuto`.

## Schema ER (delta)
Indice `idx_soc_tickets_category (cm_soc_tickets.category)`; impostazione `soc.full_resync`.
