# CHANGELOG — v1.9.86

## Posizioni aperte — pannello filtri come «Relazione di Servizio IT»

- Pannello a scomparsa con badge dei filtri attivi e riepilogo (posizioni · candidature), menu a selezione
  multipla con ricerca integrata (componente `pm-multiselect`), etichette con conteggio posizioni.
- Filtri disponibili (prima: solo Stato, Priorità, Brand a valore singolo):

| Gruppo | Filtri |
|---|---|
| Ricerca e date | Cerca ovunque (titolo, reparto, sede, descrizione, skill, cliente, codice LinkedIn) · Aperta dal/al · Target dal/al · Chiusa dal/al · Aperta da almeno N giorni |
| Posizione | Stato (anche «Annullata») · Priorità · Brand · Reparto · Sede di lavoro · Tipo contratto · Modalità di lavoro · Cliente (anche «nessun cliente») |
| Responsabili | Team Leader (anche «non assegnato», nascosto al ruolo TeamLeader) · Richiesta da |
| Pipeline ed evidenze | Candidati in fase · Con/senza candidati · Copertura (assunti ≥ attesi / da coprire) · Data target superata · Codice LinkedIn presente/mancante · RAL indicata/non indicata |

- Nuovo `app/PositionFilter.php`: parsing (array o CSV, whitelist enum, id interi), condizioni WHERE preparate,
  visibilità per ruolo, opzioni con conteggi limitate al perimetro dell'utente.
- **Export XLSX / Stampa PDF** usano gli stessi filtri della pagina (prima solo stato/brand/priorità a valore singolo).
- **Sicurezza**: gli export applicano ora la stessa visibilità per ruolo della pagina (TeamLeader → proprie
  posizioni, Recruiter → aperte/in pausa); prima esportavano tutte le posizioni.
- Compatibilità: i link storici `?f_st=open`, `?f_br=0`, `?f_pr=Alta` e i redirect delle azioni di stato funzionano invariati.

## Nota di sequenza
Basata su `main` (v1.9.81). Merge: 1.9.82 → 1.9.83 → 1.9.84 → 1.9.85 → 1.9.86. `recruiting_posizioni.php` è
modificato anche in 1.9.85 (normalizzazione codice LinkedIn, righe diverse): merge automatico atteso; conflitto solo su `VERSION`.
