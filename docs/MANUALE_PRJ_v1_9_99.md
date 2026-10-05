# MANUALE — Progetti PRJ (v1.9.99)

## Amministratore
- **Permessi** (Gestione permessi → Gestione Commesse):
  - Progetti PRJ (elenco), ↳ Scheda progetto PRJ, ↳ Calcolo scenari PRJ, ↳ Collegamento PRJ - commessa SP, ↳ Costi reali dipendenti (PRJ), Parametri dimensionamento, Scenari & confronti progetti;
  - assegnazione iniziale: Super Admin, Resp. Commerciale, Direttore IT, Coordinatore Tecnico, Finance (vedi Technical Design §4);
  - «Costi reali» va agli stessi ruoli che vedono la Compensation dei dipendenti.
- **Parametri globali**: sono in `cm_prj_param`, `cm_prj_zone`, `cm_prj_nearshore`, `cm_prj_equipment`, `cm_prj_site_cost`, `cm_prj_overhead`. Da v1.9.101 si modificano dalla pagina «Parametri dimensionamento». Ogni modifica crea una nuova versione datata.
- **Indennità H24**: si applica solo ai profili con flag H24. Nel progetto ASPI il profilo P12 «Sistemista Senior Telecomunicazioni» non ha H24.
- **Merge anagrafiche**: le assegnazioni dei dipendenti ai profili PRJ vengono riassegnate al record master.

## Utente finale
- In questa release non ci sono nuove pagine: sono presenti i dati del progetto PRJ-2026-0001 (gara ASPI), pronti per il motore di calcolo (v1.9.100) e per la scheda progetto (v1.9.101).
- Il codice PRJ (es. PRJ-2026-0001) identifica il progetto di gara. Il **codice commessa SP** (colonna Codice di «Commesse / Progetti») si collega al PRJ quando la commessa compare nel gestionale.
