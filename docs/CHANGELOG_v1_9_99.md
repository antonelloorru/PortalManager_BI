# CHANGELOG — v1.9.99
Progetti PRJ e Analisi Gara & Dimensionamento — fase 2 di 6: schema, dati di partenza, permessi.

## Funzionalità
- Nuova entità **Progetto PRJ** (`cm_prj`), distinta dalle commesse SP (`cm_projects`, invariata). Codice `PRJ-AAAA-NNNN` generato dal portale, immutabile e non riutilizzabile (`cm_prj_sequence`).
- Collegamento opzionale PRJ → commessa SP (codice commessa) con storico (`cm_prj_link_history`). Se la commessa viene eliminata, il PRJ torna «non collegato» (`ON DELETE SET NULL`).
- 38 tabelle `cm_prj*`: gara, base d'asta, tariffario Uncommitted, servizi, tecnologie, asset, ticket, ore medie per ticket, produttività, profili, requisiti, certificazioni, fasce RAL, assegnazioni, parametri, zone, nearshore, dotazioni, sede, overhead, KPI e penali, criteri di punteggio, scenari, calc run immutabili, consuntivi.
- Storicizzazione: le tabelle parametriche hanno `valid_from`, `valid_to`, `version_no`, `is_current` ed `ent_id` (identità stabile). Le modifiche aprono una nuova versione invece di sovrascrivere.

## Dati di partenza
- Parametri globali: oneri 40%, indennità H24 4.000 €, 1.600 h e 220 gg per FTE, uplift 35%, supporto sostenibile 6 FTE, margine obiettivo 15%, postazione, energia, sede.
- 4 zone (Milano, Roma, Firenze, Napoli), 2 paesi nearshore (Portogallo, Romania), 6 voci di dotazione, 2 costi di sede, 4 voci di overhead, 10 fonti.
- Progetto **PRJ-2026-0001 «ASPI Managed Service 2027-2030»** (non collegato):
  - gara 50 mesi e base d'asta 2027–2030;
  - 13 servizi, 76 tecnologie, 26 metriche asset;
  - 35 gruppi ticket (15.971 ticket 2025) e ore medie per ticket CTASK 12 h, INC 3 h, SCTASK 1,5 h;
  - 32 profili (26,5 FTE obbligatori, 55,1 FTE in totale);
  - 24 KPI con 235 associazioni ai servizi, 15 criteri (100 punti), 8 scenari.

## Integrazioni
- `app/Version.php`: `PM_VERSION` riallineato a VERSION (era fermo a 1.9.23 e bloccava l'allineamento automatico di `app_settings`).
- `app/Router.php`: `prj_dashboard`, `prj_parameters`, `prj_history` in whitelist.
- `manage_permissions.php`: 7 voci nel gruppo «Gestione Commesse» (3 pagine + 4 permessi virtuali: elenco PRJ, calcolo, collegamento, costi reali).
- `merge_employees.php`: `cm_prj_profile_assignment.employee_id` nella riassegnazione FK.

## QA
- Migration sul Dump 19.80 e sul DB di test 1.9.98: RUN1/RUN2, 98 statement, err=0.
- `tools/verify_v1_9_99.php`: 33 OK, 0 KO.
- `php -l` su tutti i file PHP modificati. Pagine Gestione permessi, Merge anagrafiche, Commesse e DGB verificate con login reale, senza warning.
