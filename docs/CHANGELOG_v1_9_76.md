# PortalManager v1.9.76 — Reperibilità: grafici e tabelle

## Dati
I record di reperibilità esistono in entrambe le fonti (maggio–settembre 2026: 100 righe
operatore DGB con `during_availability = 1`, 91 rapportini con `on_call = 1`).
**79 dei 91 interventi in reperibilità sono anche da remoto**: reperibilità e modalità sono
informazioni indipendenti.

## Issue 1 — i grafici non mostravano la reperibilità

### Attività & Rendicontazione DGB
- Il grafico "Distribuzione carico" legava all'etichetta «reperibilita» la serie delle ore
  **fuori fascia** (calcolate per differenza), non i record di reperibilità: la reperibilità
  vera non veniva mai disegnata e ogni sera di straordinario appariva come reperibilità.
- Ora tre serie disgiunte (`DgbModel::temporalDistribution`): **ordinario** (blu),
  **fuori orario** (arancione), **reperibilità** (viola, ore dei record `during_availability`).
  La somma resta pari alle ore consuntivate. Tooltip, legenda ed export CSV/XLSX con la
  nuova colonna.
- La mappa oraria usava «reperibilità» per le ore fuori fascia: ora «fuori fascia»; il testo
  esplicativo distingue le due cose.

### Relazione di Servizio IT
- La reperibilità era riconosciuta solo da `modalita` della vista: gli interventi in
  reperibilità **da remoto** risultavano «da remoto» e sparivano da grafici e conteggi.
- Ora, con il rapportino agganciato (v1.9.75), vale anche il flag `on_call` del rapportino.

## Issue 2 — visualizzazione nelle tabelle (Relazione IT)
- Valori tecnici stampati grezzi: «reperibilita» (senza accento, minuscolo) nelle barre per
  modalità, nel filtro e nell'export → «Reperibilità», «Da remoto», «Presso cliente»…
- Colonna «Reper.» della tabella tariffe: flag stampato così com'era → badge «Sì» / «—»
  (a schermo e in stampa), «Sì»/«No» nell'export Word; qualunque forma (1/0, S/N, sì/no).
- Colore della reperibilità uniformato al viola in tutti i grafici.
- Tabella aggregata: la colonna «Reperibilità» conta tutti gli interventi in reperibilità
  (prima solo quelli con modalità «reperibilita»), più il totale delle ore.

## QA (dati reali)
| | prima | dopo | fonte |
|---|---|---|---|
| DGB, serie reperibilità (mag–set) | assente | 28 · 49 · 56 · 50 · 37 h | record `during_availability` |
| DGB, luglio giornaliero | — | 15 giorni con reperibilità | 15 giorni nei dati |
| Relazione IT, reperibilità mensile | 6 · 9 · 15 · 5 · 1 h | 28 · 49 · 56 · 52 · 37 h | 28 · 49 · 56 · 52 · 37 h |
| Relazione IT, interventi in reperibilità | 12 | 91 | 91 |

Totali mensili DGB invariati (le ore sono solo attribuite alla classe corretta).
Formattazione 18/18. `php -l` OK; migration RUN1/RUN2 err=0; schema_version → 1.9.76.
