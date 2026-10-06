# CHANGELOG — v1.10.12 (2026-10-06)

Software 1.10.12 · Schema 1.10.12 · Upgrade `sql/migration_v1_10_12.sql` (pacchetto cumulativo da 1.10.06)

## 1. Export multi-formato — Service Desk, Service SOC, Attività & Rendicontazione DGB
- **Report generale** in **DOCX, XLSX, CSV, PDF** dalla barra «Report» sotto il filtro principale.
- **Report singoli per tecnico** (pannello «Report singoli per componente / incaricato»), visibile quando non è attivo un filtro puntuale sul tecnico:
  - per ogni risorsa del perimetro: DOCX · XLSX · CSV · PDF e «apri con il filtro»;
  - «Tutti (ZIP)»: un file per risorsa nel formato scelto.
- Con il filtro sul tecnico attivo, la barra produce il report del tecnico selezionato.
- Contenuto identico in tutti i formati (PmReport): filtri applicati, indicatori, sezioni, tabelle, grafici a barre, note.
  - **Service SOC**: indicatori, ripartizioni (categoria, esito, stato), team, componenti × categoria, consuntivo attività SOC (tipologia, operatore, operatore × tipologia), da presidiare, elenco ticket.
  - **Service Desk**: quadro, classi di gestione, andamento, scheda del componente (esito, attività, moduli per tipologia, code, ticket presi), analisi del team, operatori, codici linea, OBJ_2, da presidiare.
  - **DGB**: KPI attività e ore per classe, dettaglio col raggruppamento della pagina, per incaricato, per tipologia di contratto, per mese, anomalie orarie, elenco attività.
- PDF nativo (nuovo `app/PdfWriter.php`, zero dipendenze): A4 orizzontale, header tabella ripetuto, testo a capo, numerazione pagine.
- Gli export dati esistenti restano («Dati XLSX» / «Dati CSV»); la stampa HTML del Service Desk resta («Stampa report …»).

## 2. Pulizia filtri
- **DGB › Anomalie orarie**: rimosso il blocco filtri proprio (tecnico, tipo, severità, dal/al). Le anomalie usano il filtro principale (periodo, incaricato, contratti) tramite `DgbModel::anomalieWhere()`; il pannello principale è mostrato anche nella scheda Anomalie; riepilogo, elenco, contatore nella scheda, export e report sullo stesso perimetro.
- Service SOC, Service Desk, DGB: nessuna barra filtri automatica (PM_NO_AUTOFILTER, già attivo), nessun filtro secondario; report ed export ereditano solo il filtro principale.

## Sicurezza
Report e ZIP richiedono il permesso **export** sulla pagina; nomi file normalizzati; ZIP e file temporanei rimossi dopo l'invio.
