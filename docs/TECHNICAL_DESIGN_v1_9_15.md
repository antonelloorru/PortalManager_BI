# Technical Design — PortalManager v1.9.15

## 1. Le tariffe ricostruite invece che chieste

Il template non dichiarava le tariffe: dava solo ore e valori. Dividendo l'uno per
l'altro su nove righe, i risultati convergono su quattro valori — 100,00, 87,50,
81,25, 120,00 — con precisione al centesimo.

La convergenza è la prova. Nove divisioni indipendenti che danno quattro valori
esatti non sono una coincidenza: sono il listino.

Ho comunque registrato l'origine in `cm_sd_tariffe.origine`, che vale `dedotta da
template`. È il correttivo all'errore ripetuto tre volte in questa serie di
release — dedurre e presentare il risultato come se fosse un dato.

Qui la deduzione c'è, ed è dichiarata nel dato stesso.

## 2. Scaglioni e non pacchetti

Tre mezze giornate valgono 1.225,00 nel template. A pacchetto — 3 × 4h × 87,50 =
1.050,00 — non torna. A ore — 14 × 87,50 = 1.225,00 — torna esattamente.

Le 14 ore su tre interventi sono 4,67 di media: non sono tre pacchetti da 4 ore,
sono tre interventi di durata varia sopra la soglia.

La conferma decisiva è la riga «Fascia C 5 ore»: 5 × 87,50 = 437,50. Un pacchetto
di mezza giornata non può valere 5 ore.

## 3. La soglia sta fra 4 e 5

«2 ore» è a 100,00 €/h, «5 ore» a 87,50. Il documento dice «1/2 giornata (fino a
4h)», che si accorda con l'osservazione leggendo la mezza giornata come lo
scaglione che parte dopo le prime 4 ore.

Le soglie sono parametri: se l'interpretazione fosse sbagliata, si correggono
senza toccare le viste.

## 4. Le due sezioni condividono le viste

`ItServiceModel::costiRiepilogo` interroga `v_cm_sd_costi_valorizzati`, la stessa
del Service Desk.

Duplicare la logica avrebbe dato due definizioni degli scaglioni, e la prima volta
che le due sezioni avessero mostrato cifre diverse sullo stesso intervento nessuno
avrebbe saputo quale credere.

Cambia solo il perimetro dei filtri: la Relazione IT ha `incaricati` a selezione
multipla dove il Service Desk ha `tec` singolo.

Il nome della vista resta `v_cm_sd_*` anche se serve entrambe. Rinominarla
avrebbe richiesto di toccare tutti i punti che la usano per un guadagno solo
nominale.

## 5. `costiQuery` come corpo comune

Le tre interrogazioni — riepilogo, per commessa, quadro — differiscono solo per
SELECT e GROUP BY. Il filtro è identico.

Ripeterlo tre volte avrebbe significato correggere tre volte ogni modifica al
periodo o al tecnico, e la terza volta dimenticarne una.

## 6. La fascia oraria riusata dalla v1.8.53

Terza sezione che la usa, dopo Relazione IT e analisi del team. Il fine settimana è
valutato prima dell'ora, per la ragione già nota.

Un modulo senza attività DGB collegata non ha l'ora di inizio e viene trattato come
fascia C. È una scelta: contarlo D lo valorizzerebbe a 120,00 invece di 100,00, e
gonfiare la tariffa più alta su casi ignoti è peggio che sgonfiarla.

Nella Relazione IT la stessa mancanza produce la fascia «non rilevata», che è una
terza categoria. Qui non è possibile: il calcolo deve produrre un valore, e «non
rilevata» non ha una tariffa.

L'incoerenza è reale e va dichiarata: la stessa condizione produce una categoria
distinta in una vista e ricade su C nell'altra.

## 7. Il layout dell'export

I fogli riproducono la struttura del template — riga di intestazione, righe per
fascia, riga TOTALE, riga vuota fra i blocchi.

Un export che il destinatario deve rimpaginare a mano vanifica metà del lavoro.
Riprodurre il layout costa un ciclo con un accumulatore e rende il file
utilizzabile così com'è.
