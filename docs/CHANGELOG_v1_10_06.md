# CHANGELOG v1.10.06 — Gestione Commesse › Service SOC

Data: 2026-10-06 · Software 1.10.06 · Schema 1.10.06 · Upgrade `sql/migration_v1_10_06.sql` · Cumulativo da 1.10.05

## Nuovo
- **Service SOC** (`service_soc.php`), sotto Gestione Commesse dopo Service Desk, sul pattern della pagina Service Desk:
  filtri (contratto globale, periodo, cliente, commessa SOC, categoria, componente, stato, esito, ricerca), sei indicatori,
  schede Cruscotto · Ticket (elenco + dettaglio con cronologia e moduli) · Team · Clienti e commesse · Ingestion, export XLSX.
- **Ingestion multi-sorgente** (`app/SocIngest.php`):
  - file: export «lista eventi ticket» XLSX o CSV (intestazioni del gestionale, alias tolleranti);
  - DB: connessione in sola lettura al DB SOC (istanza separata, stesso schema del gestionale), password cifrata con APP_SECRET,
    finestra incrementale, prefisso ticket, query di estrazione personalizzabile, anteprima e test;
  - stessa chiave evento per file e DB: le due sorgenti si integrano senza doppioni; reimport idempotente.
- Ticket ricostruiti dagli eventi (`cm_soc_tickets`): stato attuale, chiusura, esito, contatori, riaperture, tempi di risposta al cliente.
- **Aggregazione con il portale** (`app/SocModel.php`): moduli di intervento per codice ticket (ore, costo, ricavo, commesse PM),
  dipendenti abbinati (quota di ore SOC sul totale dei moduli), clienti abbinati, filtro Codice Contratto / PM Project.
- Abbinamenti automatici persone → dipendenti e clienti SOC → clienti, correggibili a mano (le scelte manuali restano).
- `cron_soc_sync.php`: sincronizzazione pianificata dal DB SOC.
- Migration: `cm_soc_events`, `cm_soc_tickets`, `cm_soc_batches`, `cm_soc_source_db`, `cm_soc_people`, `cm_soc_clients`, impostazioni `soc.*`, permessi copiati da Service Desk.

## Correzioni
- `wp_ats_sync.php` (v1.10.05): link a «Pubblica su portali» e «Dossier candidati» con parametri doppiamente codificati (`&amp;amp;`): corretti.

## Dati di collaudo (export allegato SOC_SD20261006)
3.564 eventi, 762 ticket (04/12/2025 – 05/10/2026), 4 clienti ESTAR abbinati, 6 componenti su 8 abbinati a dipendenti,
224 ticket con moduli di intervento nel portale. Sincronizzazione da DB SOC simulato: 3.564 eventi riconosciuti come già presenti (0 doppioni).
