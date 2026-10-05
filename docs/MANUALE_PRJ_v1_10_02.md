# MANUALE — Progetti PRJ (v1.10.02)

## Utente finale

### Scheda progetto
- **KPI & Penali**
  - In alto il catalogo dei KPI, modificabile con decorrenza.
  - Nel simulatore inserire le quantità fuori soglia del periodo e premere «Simula»:
    - per i KPI di presa in carico e risoluzione, i ticket fuori SLA per priorità A, M, B (la penale scatta ogni 5 ticket);
    - per la patch compliance, i punti % mancanti;
    - per i target di spesa, lo sforamento in €.
  - Il risultato mostra la penale per KPI, il totale e la % sul canone del mese o dell'anno.
  - «Banda volumi»: indicare i ticket reali dell'anno per vedere il conguaglio.
- **Punteggio**: compilare i simulatori tecnico ed economico e premere «Salva e ricalcola».
  - I criteri con l'icona di avviso hanno un'incongruenza nel bando (formula A.1, parametri w/n, punti H).
  - w, n1 e n2 vanno ipotizzati.
- **Storico**: selezionare A e B tra i calcoli salvati e premere «Confronta». Sotto ci sono le modifiche ai dati con autore e data.

### Altre pagine
- **Gestione Commesse → Scenari & confronti progetti**: tutti i calcoli salvati.
  - Filtrare per progetto, stato, zona, periodo, commessa.
  - Spuntare 2 o più calcoli e premere «Confronta i selezionati»: il primo è la base dei delta.
  - Con un solo progetto filtrato compare l'andamento dei calcoli.
  - Il pulsante XLSX esporta i calcoli, il confronto e i filtri.
- **Scheda commessa → Progetti PRJ**: i progetti di gara collegati alla commessa, con lo stimato (FTE, costo, canone, % canone, margine) accanto al consuntivo della commessa. Da qui si collega o scollega un progetto.
- **Elenco commesse**: colonna «Progetti PRJ» con link alla tab e filtro «Progetti PRJ collegati». L'export aggiunge la colonna `progetti_prj` in coda.

## Amministratore
- Il permesso «Scenari & confronti progetti» (`prj_history.php`) governa la nuova voce di menu e l'export.
- La tab «Progetti PRJ» della commessa è visibile a chi vede la scheda PRJ o ha il permesso di collegamento. Collegare e scollegare richiede «Collegamento PRJ - commessa SP».
- La simulazione delle penali non salva dati. La simulazione del punteggio salva gli input del progetto, sostituendo i precedenti, con traccia nello storico.
