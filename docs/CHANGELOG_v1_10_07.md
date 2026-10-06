# CHANGELOG v1.10.07 — Service SOC: pipeline unica e Unità Organizzativa SOC

Data: 2026-10-06 · Software 1.10.07 · Schema 1.10.07 · Upgrade `sql/migration_v1_10_07.sql` · Cumulativo da 1.10.06

## Interfaccia
- **Sincronizzazione gestionale** ha due sottosezioni: *Gestionale* (dataset, invariata) e **SOC**.
- In *SOC*: stato della pipeline, **Import da file**, **Sincronizzazione dal DB SOC**, pianificazione e regole,
  **Unità Organizzativa SOC**, abbinamenti persone/clienti, registro delle esecuzioni.
- **Service SOC** resta la vista di analisi (Cruscotto, Ticket, Team, Clienti e commesse): la scheda Ingestion è rimossa;
  in testa ultima sincronizzazione e collegamento a Sincronizzazione gestionale › SOC. Team: colonna Unità e componenti dell'unità SOC senza ticket.

## Pipeline unica (`app/SocSync.php`)
- Un processo, un lock (`pm_soc_sync`), un registro (`cm_soc_sync_runs`): cartella di arrivo → DB SOC → ricostruzione ticket (una volta) → abbinamenti → Unità Organizzativa SOC.
- Il caricamento dalla pagina deposita il file nella **cartella di arrivo** (`uploads/soc_inbox`) e avvia la pipeline; i file elaborati vanno in `archivio` o `scartati`.
  Un processo esterno può depositare gli export nella stessa cartella.
- Inneschi: scheduler del portale senza cron (worker task `soc`, ogni `soc.interval_min` minuti), in coda alla sincronizzazione giornaliera del gestionale,
  `cron_soc_sync.php`, «Esegui ora», caricamento file.

## Unità Organizzativa SOC
- I dipendenti abbinati alle persone che erogano il servizio negli ultimi 12 mesi (responsabili/incaricati di ticket, autori di messaggi o note)
  sono assegnati all'unità `SOC`: scheda tecnica creata se manca, unità impostata se vuota; chi è in un'altra unità resta invariato ed è segnalato
  («Sposta su SOC» per forzare). Disattivabile (`soc.uo_auto`). Ogni assegnazione è annotata nella scheda tecnica e nel log.

## Collaudo
Prima esecuzione sui dati di collaudo: 1 tecnico assegnato (Li Greci Corrado, scheda senza unità), 5 già in SOC, 0 conflitti.
