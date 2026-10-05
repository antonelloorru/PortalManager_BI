# CHANGELOG — v1.9.92

Due modifiche indipendenti.

## 1. Export XLSX — Relazione di Servizio IT
- Foglio **«Giorni per commessa»**: aggiunte le colonne **Cliente** e **Descrizione** prima di **Commessa**.
  Stessi dati di «Commesse / Progetti»: cliente = anagrafica cliente (in mancanza il cliente indicato sulla commessa),
  descrizione = `cm_projects.description`.
- Solo l'export: tabella a video, Word e stampa invariati. Gli altri fogli «Giorni per …» invariati.
- `ItServiceModel::giorniPer()` con dimensione `commessa` restituisce anche `cliente` e `descrizione`.

## 2. UI — Commesse / Progetti (`manage_projects.php`)
- Colonna **link** rinominata **Link SP**.
- Il pulsante della scheda (ultima colonna, icona senza testo, «Apri la scheda della commessa») diventa
  **«Scheda Progetto»** ed è spostato nella colonna a fianco di **Link SP**. L'ultima colonna vuota è rimossa: il numero di colonne resta 31.
- Export XLSX/CSV della lista commesse invariato (intestazioni dello standard «Lista commesse»).
