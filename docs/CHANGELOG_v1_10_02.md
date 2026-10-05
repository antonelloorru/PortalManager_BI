# CHANGELOG — v1.10.02
Progetti PRJ — fase 5 di 6: KPI e penali, punteggio, storico, confronti tra progetti, integrazione con le commesse SP.

## Scheda progetto PRJ — nuove tab
- **KPI & Penali**:
  - catalogo dei 24 KPI con livello atteso, penale, importi per priorità, unità e servizi associati (modifica versionata);
  - simulatore delle penali per mese o anno: ticket fuori SLA per priorità A/M/B a blocchi di 5, punti % per la patch compliance, sforamento in € per i target di spesa, giorni, minuti, risorse, finestre, una tantum; totale in € e % del canone del periodo;
  - banda volumi ±20% con conguaglio: valore unitario del ticket, soglie, ticket fuori banda, importo.
- **Punteggio**:
  - criteri A–I con punti, formula e segnalazione delle incongruenze del bando (modifica versionata);
  - simulatore tecnico: coefficienti dei criteri discrezionali; Pi di referenze e CV; S e C per servizio con N servizi; certificazioni aziendali; RTNc; H;
  - simulatore economico PE (K1 canone, K2 Uncommitted, w, n1, n2), con punteggio totale e confronto con il ribasso massimo a pareggio;
  - gli input si salvano e si ricalcolano.
- **Storico**:
  - calcoli salvati con confronto tra due run (totali, anni, servizi; delta e %);
  - modifiche ai dati (EntityChangeLog del progetto, scenari, profili e tabelle versionate);
  - versioni vigenti e storiche per tabella;
  - collegamenti.
- **Scenari**: grafico per anno di costo, canone netto e margine.

## Altre pagine
- **Scenari & confronti progetti** (`prj_history.php`, menu Gestione Commesse dopo «Report direzionale»):
  - calcoli salvati di tutti i progetti, con filtri per periodo, progetto, stato, società, commessa SP al calcolo, codice commessa, scenario, zona, solo l'ultimo calcolo per scenario;
  - confronto affiancato di 2 o più calcoli con delta e %, e grafico del costo per anno;
  - andamento dei calcoli di un singolo progetto;
  - export XLSX con i fogli Calc run, Confronto e Filtri.
- **Scheda commessa SP**: nuova tab «Progetti PRJ» con badge. Elenca i PRJ collegati con lo stimato dello scenario di riferimento (FTE, costo annuo, canone, % canone, margine) accanto al consuntivo sincronizzato della commessa; consente di collegare e scollegare un progetto (permesso «Collegamento PRJ - commessa SP»).
- **Elenco commesse SP**: colonna «Progetti PRJ», filtro «Progetti PRJ collegati» e colonna `progetti_prj` in coda all'export XLSX/CSV. Le 29 colonne standard restano invariate.
- `PmCharts::groupedBars`: grafico a barre raggruppate in SVG lato server.

## QA
- `tools/verify_v1_10_02.php`: 20 OK, 0 KO su Dump 19.80 e DB di test. Le verifiche delle release precedenti falliscono solo sui controlli di versione.
- Test con login reale: simulatore penali (11.100 € nel mese), punteggio (32 tecnico + 4,59 economico), storico e confronto, tab della commessa con collegamento, filtro ed export dell'elenco commesse, confronto ed export dei calcoli; nessun warning PHP.
- Migration RUN1/RUN2 err=0; `php -l` su tutti i file.
