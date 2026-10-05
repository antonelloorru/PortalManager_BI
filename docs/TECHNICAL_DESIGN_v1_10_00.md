# TECHNICAL DESIGN — v1.10.00 (motore di calcolo PRJ)
Riferimento generale: `TECHNICAL_DESIGN_v1_9_99.md` (schema ER, permessi, decisioni del 05/10/2026).

## Componenti
| Classe | Ruolo | Dipendenze |
|---|---|---|
| `PrjCalc` | Funzioni pure: `ticketLoad`, `structuralPerFte`, `scenarioFte`, `scenario`, `conguaglio`, `penalties`, `technicalScore`, `economicScore`, `hash` | `FormulaEval` |
| `PrjRepo` | `input`, `calc`, `saveRun`, `runResults`, `compareRuns`, `writeVersion`, `nextCode`, `params` | PDO, `PrjCalc`, `EntityChangeLog` |

Flusso: `PrjRepo::input(prj, scenario, as_of)` → `PrjCalc::scenario(input)` → (opz.) `PrjRepo::saveRun()`.

## Lettura as-of
- Condizione: `:d BETWEEN valid_from AND COALESCE(valid_to,'9999-12-31')`. Un solo segnaposto per condizione: con `ATTR_EMULATE_PREPARES = false` (Config.php) i segnaposto nominali non si possono ripetere.
- Parametri, zone, nearshore, dotazioni, sede e overhead: le righe di progetto (`prj_key = prj_id`) prevalgono sulle globali (`prj_key = 0`) con la stessa chiave.
- `oneri_pct` assente → `hr_reference_values.hr_mult_fc` dell'anno − 1.

## Input del motore
`prj`, `as_of`, `year` (ultimo anno dei volumi), `params`, `productivity`, `gara`, `services[ent_id]`, `volumes`, `mapping`, `aht`, `lines` (profilo × servizio con fasce RAL), `zones[ent_id]`, `nearshore[ent_id]`, `equipment`, `site`, `overhead`, `tender[anno]`, `scenario` (tipo, zona, ral_mode, ribasso, margine target, nearshore e ambito, supporto sostenibile, override per `profile_id:service_id`).

## Regole di calcolo
Formule del Technical Design v1.9.99 §5 (riportate nell'intestazione di `PrjCalc.php`), con le decisioni del 05/10/2026:
- **H24:** flag del profilo, sovrascrivibile per scenario.
- **Strutturale remoto:** solo dotazione per gli FTE con override `remoto` e per gli FTE in nearshore.
- **Nearshore:** solo profili di supporto con `nearshore_ammesso`; usa indice RAL e oneri del paese.
- **RAL di zona:** RAL di riferimento × indice zona ÷ indice della zona base della fascia (Roma = 1,00).
- **Per anno:** i profili dei servizi con `avvio_anno` > n sono esclusi dall'anno n. Il costo dell'anno ridotto non include le relative quote di overhead per FTE.

## Calc run
- `cm_prj_calc_run`: input JSON completo, `params_hash` SHA-256 (chiavi ordinate), `as_of`, commessa SP al momento del calcolo, `app_version` (PM_VERSION), `schema_version`, utente. Mai modificato; la FK RESTRICT verso `cm_prj` impedisce di cancellare un PRJ che ha dei run.
- `cm_prj_calc_result` per ambito:
  - `totale`: 31 metriche;
  - `servizio`: ticket, ore, FTE;
  - `profilo`, con riferimento `codice@servizio`: FTE, RAL di zona, costo per FTE, costo aziendale, strutturale;
  - `anno`: FTE, costo, canone, margine, % canone;
  - `zona`: strutturale per FTE da ufficio.
- `compareRuns(a, b)`: delta assoluto e % sulle metriche comuni.

## Scrittura versionata
`writeVersion(table, id, data, valid_from, user, note)`, eseguita in una transazione con `SELECT … FOR UPDATE`:
1. Chiude la versione vigente con `valid_to = valid_from − 1` e `is_current = 0`.
2. Inserisce la nuova versione con `version_no + 1` e lo stesso `ent_id`.
3. Registra le modifiche in EntityChangeLog sull'`ent_id`.

Non ammette una decorrenza anteriore o uguale a quella vigente e non permette di modificare le colonne di versione.

## Risultati con i dati ASPI (as-of 05/10/2026)
| Scenario | FTE | RAL | Costo az. personale | Totale | % canone |
|---|---|---|---|---|---|
| Sostenibile Roma | 34,0 | 1.517,0 | 2.207,3 | 2.371,9 | 101% |
| Sostenibile Milano | 34,0 | 1.699,0 | 2.462,2 | 2.645,3 | 112% |
| Sostenibile Firenze | 34,0 | 1.426,0 | 2.079,9 | 2.233,0 | 95% |
| Sostenibile Napoli | 34,0 | 1.274,3 | 1.867,5 | 2.012,3 | 86% |
| Sostenibile Firenze + supporto Romania | 34,0 | 1.362,6 | 1.938,7 | 2.083,0 | 89% |
| Completa Roma | 55,1 | 2.284,8 | 3.351,2 | 3.599,8 | 153% |
| Completa Firenze | 55,1 | 2.147,7 | 3.159,2 | 3.389,2 | 144% |
| Completa Milano | 55,1 | 2.559,0 | 3.735,0 | 4.013,8 | 171% |

Importi in k€.
