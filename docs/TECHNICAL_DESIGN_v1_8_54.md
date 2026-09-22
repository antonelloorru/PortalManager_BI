# Technical Design — PortalManager v1.8.54

## 1. Che cosa rende un controllo utile

Un controllo di qualità del dato vale per il rapporto fra veri positivi e falsi
positivi, non per quante righe segnala. Un elenco con migliaia di segnalazioni
per lo più infondate viene ignorato dopo la seconda consultazione, e con esso
vengono ignorate anche le segnalazioni fondate che contiene.

È il criterio con cui sono stati valutati i tre controlli candidati, e la ragione
per cui uno è stato scartato nonostante fosse quello che produceva più risultati.

## 2. Ore identiche su più commesse

La chiave di raggruppamento è `(operatore, giorno, ore, orario di inizio)`.

L'inclusione dell'orario di inizio è la scelta che rende il controllo selettivo.
Senza, si segnalerebbe chiunque abbia lavorato due ore su una commessa la mattina
e due su un'altra il pomeriggio — situazione ordinaria. Con l'orario nella chiave,
si segnala solo chi ha due righe che affermano di essere iniziate **nello stesso
momento** con la **stessa durata** su commesse diverse: una delle due è
necessariamente inesatta.

```sql
GROUP BY id_operator, DATE(date_start), TIME(date_start), hours
HAVING COUNT(DISTINCT id_contract) > 1
```

Il risultato sui dati reali — 1.459 gruppi su 67.786 allocazioni, il 2,2% — è
nell'ordine di grandezza atteso per un errore di compilazione: abbastanza da
giustificare il controllo, non tanto da suggerire un artefatto.

L'esame dei casi conferma: il primo per gravità è un tecnico con 40 ore in una
giornata, cinque righe da 8 ore tutte con inizio alle 09:00.

## 3. Ore giornaliere fuori scala

Controllo indipendente dal primo, che intercetta il caso in cui le quantità
duplicate sono diverse fra loro e la chiave del primo controllo non scatta.

Due soglie con severità diverse. Oltre le 24 ore l'anomalia è certa: non è una
questione di plausibilità ma di aritmetica. Fra 12 e 24 la segnalazione è a
severità media, perché una giornata lunga con reperibilità notturna è possibile e
trattarla come errore genererebbe attrito.

## 4. Il controllo scartato, e perché

La sovrapposizione temporale — due interventi dello stesso tecnico i cui
intervalli si intersecano — è il controllo che verrebbe in mente per primo.
Produce 14.058 coppie.

Un numero così alto su 67.786 allocazioni impone di chiedersi se il controllo
misuri ciò che si crede. La verifica:

```
attività con durata dichiarata oltre 8 ore: 32.550 su 69.832 (46%)
durata media dichiarata: 5,57 ore
```

Il 46% delle attività dichiara durate oltre l'orario di lavoro. `date_dead_line`
non è l'orario di fine intervento ma, per buona parte dei record, la finestra
entro cui l'intervento andava svolto. Due interventi assegnati alla stessa
giornata risultano quindi quasi sempre "sovrapposti" senza che nulla di anomalo
sia accaduto.

Il controllo è stato escluso. La documentazione riporta la misura che ha
motivato la scelta, così la decisione è rivedibile: se un domani i dati
distinguessero finestra di pianificazione ed esecuzione effettiva, il controllo
diventerebbe valido e andrebbe reintrodotto.

## 5. Viste e non tabella

Le anomalie sono calcolate al momento della lettura.

Una tabella materializzata andrebbe ricostruita a ogni import, e nell'intervallo
fra un import e la ricostruzione mostrerebbe anomalie già corrette o
nasconderebbe quelle appena introdotte. Per un cruscotto di controllo qualità è il
difetto peggiore: chi lo consulta agisce su ciò che vede, e se ciò che vede è
vecchio interviene su problemi che non esistono più.

Il costo è l'esecuzione a ogni apertura della scheda. Le due aggregazioni sono su
`dgb_forms_activity_operator` con join su `dgb_forms_activity`, entrambe indicizzate
sulle colonne di raggruppamento; l'elenco è limitato a 500 righe.

## 6. Il contatore sulla linguetta

Il numero delle anomalie ad alta severità compare sulla linguetta della scheda,
non al suo interno.

È una scelta deliberata: un controllo che richiede di essere aperto per sapere se
c'è qualcosa da vedere viene aperto le prime volte e poi dimenticato. Il contatore
esterno inverte l'onere — non serve ricordarsi di controllare, è il controllo a
farsi notare quando ha qualcosa da dire.

Compare solo se maggiore di zero: un contatore a zero sempre presente diventa
rumore visivo e smette di essere letto.

## 7. Segnalazioni, non verdetti

L'elenco è presentato come materiale da verificare. Le ore identiche su più
commesse possono essere legittime — un intervento che serve davvero più commesse
in parallelo — e il portale non ha modo di saperlo.

La distinzione non è formale. Un elenco presentato come "errori" invita a
correggere i dati senza indagare; presentato come "da verificare" invita a
guardare il caso. Sulla qualità del dato la seconda è quasi sempre la strada
giusta, perché una parte delle anomalie descrive la realtà meglio di quanto la
descriva la regola.
