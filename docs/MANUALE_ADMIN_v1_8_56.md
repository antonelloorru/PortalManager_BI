# Manuale Amministratore — v1.8.56

## Filtri nelle Anomalie orarie

La sezione **Attività & Rendicontazione DGB → Anomalie orarie** ha ora un riquadro
**Filtri** con cinque criteri combinabili:

- **Tecnico** — ricerca parziale, con elenco a discesa dei nominativi presenti
  nelle anomalie. È una ricerca per parte del nome, non una selezione esatta: i
  nomi arrivano dal gestionale e la loro forma non è sempre uniforme.
- **Tipo di anomalia** — ore identiche su più commesse, oppure ore giornaliere
  fuori scala.
- **Severità** — alta o media.
- **Dal giorno / Al giorno** — intervallo sulla data dell'anomalia.

Accanto ai pulsanti è indicato quante segnalazioni soddisfano i filtri.

Cliccando una delle schede di riepilogo in alto, il filtro per tipo si applica
**mantenendo** gli altri già impostati: non serve reimpostare tecnico e periodo.

## Export

I pulsanti **Esporta XLSX** e **Esporta CSV** producono un file con le stesse
colonne della tabella — severità, tipo, tecnico, giorno, ore, righe, commesse
coinvolte, rilievo, dettaglio — e con gli stessi filtri applicati.

**L'export contiene tutte le righe che soddisfano i filtri**, mentre a video ne
compaiono al massimo 500 per non appesantire la pagina.

La differenza è dichiarata nell'intestazione della tabella: se leggete *«500 di
546 righe»*, il file conterrà 546 righe. Non è un errore, è il comportamento
voluto: chi esporta sta portando il dato in un foglio di calcolo e la prima
pagina non gli serve.

## Colonne aggiunte a video

La tabella mostra ora anche **Tipo** e **Commesse**, che prima comparivano solo
nell'export.

Il tipo serve quando il filtro per tipo è disattivato e l'elenco mescola le due
famiglie di anomalie; il numero di commesse coinvolte è il dato che qualifica la
gravità di un'anomalia di ore duplicate — due commesse è un caso, cinque è un
altro.

## Uso pratico

Per lavorare le segnalazioni di un singolo tecnico: impostare il nome nel campo
Tecnico, severità *alta*, ed esportare. Il file contiene solo le sue segnalazioni
gravi, pronte da girare a chi deve verificarle.

Per una revisione periodica: impostare l'intervallo del mese, esportare, e usare
il file come lista di controllo.
