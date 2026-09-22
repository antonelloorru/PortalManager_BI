# Technical Design — PortalManager v1.9.3

## 1. Agire sul DOM invece che sulle pagine

Lo script cerca `table.data-table` e la avvolge in un contenitore scorrevole.

L'alternativa era modificare le quindici viste, aggiungendo un `<div>` attorno a
ogni tabella. Più esplicito, e quindici occasioni di dimenticarne una — oltre a
quindici file da toccare di nuovo alla prossima vista aggiunta.

Il costo è che il comportamento non si legge dal sorgente della pagina. È
mitigato dal fatto che si applica a una classe che tutte le tabelle già usano.

## 2. `sticky` e non un secondo `<table>`

La tecnica classica per l'intestazione fissa è separare `<thead>` e `<tbody>` in
due tabelle sovrapposte.

Richiede di sincronizzare le larghezze delle colonne via JavaScript, e le due si
disallineano al primo contenuto più largo del previsto — un nome cliente lungo,
un importo a sette cifre.

`position: sticky` lascia una tabella sola: le colonne restano allineate perché
sono le stesse.

## 3. `z-index` a tre livelli

```
intestazione          3
prima colonna         2
incrocio delle due    4
```

Scorrendo in diagonale, la cella in alto a sinistra appartiene a entrambi i
contesti. Con un solo valore scompare sotto l'altro elemento sticky.

Il 4 sull'incrocio non è un numero arbitrario: deve superare entrambi.

## 4. `box-shadow` invece di `border`

Il bordo di una cella `sticky` non viene ridisegnato durante lo scorrimento: si
vede la riga sottostante passare sotto l'intestazione senza separazione.

`box-shadow: inset` è disegnato dentro il riquadro della cella e resta visibile.

## 5. Altezze in `vh`

La richiesta era «adattabile alla dimensione e risoluzione dello schermo». Un
valore in pixel non lo è: 500px sono metà schermo su un portatile e un quarto su
un monitor.

Quattro scaglioni via `@media (max-height)` e `(min-height)`: su uno schermo basso
la tabella è il contenuto principale e merita proporzionalmente più spazio; su uno
alto si può mostrare di più senza rendere la pagina interminabile.

## 6. La colonna fissa è condizionata

```js
if (tab.scrollWidth > box.clientWidth + 4) tab.classList.add('pm-fix1');
```

Bloccare la prima colonna su una tabella che sta già nello schermo sottrae spazio
e aggiunge un bordo senza dare nulla.

La verifica si ripete al ridimensionamento, con un ritardo di 150 ms: senza, ogni
pixel di trascinamento della finestra scatenerebbe un ricalcolo.

## 7. La stampa era il rischio vero

Un contenitore con `overflow: auto` **stampa solo la porzione visibile**. Le
righe oltre il bordo non escono dalla stampante, e il foglio sembra completo.

Sarebbe stato un difetto silenzioso: chi stampa un elenco di duecento commesse
riceve venti righe senza alcun segnale che ne mancano centottanta.

`@media print` rimuove il limite di altezza, disattiva `sticky` — che in stampa
non ha senso — e imposta `display: table-header-group` perché l'intestazione si
ripeta a ogni pagina.

## 8. La soglia delle otto righe

Su cinque righe l'intestazione non esce mai dalla vista, e il contenitore aggiunge
un bordo e un'ombra a una tabella che non ne ha bisogno.

Otto è il punto in cui una tabella comincia a essere scorsa invece che letta.

## 9. I nomi: la v1.8.91 aveva corretto l'ordinamento, non la forma

`v_cm_nomi` costruiva la chiave di ordinamento `Cognome Nome` lasciando intatta la
forma mostrata, che era la scelta giusta: gli utenti riconoscono le persone come
il gestionale le scrive.

Ma `DgbModel` **costruisce** il nome, non lo legge: concatenava `first_name` +
`second_name` e produceva la forma opposta a quella dei moduli di intervento.

La stessa persona compariva quindi in due modi a seconda della schermata — e la
correzione dell'ordinamento non poteva vederlo, perché riguardava le query di
elenco e non quelle che alimentano i menu.

Tre occorrenze, tutte in `DgbModel`. L'ordinamento `ORDER BY name` segue
automaticamente la nuova forma.
