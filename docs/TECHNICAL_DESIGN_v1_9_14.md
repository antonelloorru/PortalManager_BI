# Technical Design — PortalManager v1.9.14

## 1. Una funzione, tre punti di uso

`$svgLinee` sostituisce due generatori distinti e affianca quello del riquadro a
video, che resta inline perché ha un secondo asse — il tasso di escalation in
percentuale a destra — che le altre due resi non hanno.

Sarebbe stato possibile generalizzare anche quello. Non l'ho fatto: la firma
avrebbe dovuto accogliere un asse secondario opzionale con scala propria, per un
solo chiamante. Il costo della generalizzazione supera il beneficio quando il caso
generale ha un utente solo.

## 2. Barre o linee: la forma dice qualcosa

Le barre impilate affermano che le serie si sommano in un totale con significato.

Per i ticket è vero — risolti + escalation + diretti + mai presi è il totale del
periodo — ma il riquadro mostra accanto il tasso di escalation, che è una
percentuale: impilarla sopra dei conteggi non produce nulla di interpretabile.

Le linee affermano meno: che ogni serie è una grandezza osservata nel tempo. È
un'affermazione più debole e sempre vera per questi dati.

## 3. Le assenze restano impilate

La tentazione era di uniformare tutto. Ferie, permessi, recuperi e malattia si
sommano in un totale che ha significato — le ore di assenza complessive — e la
pila lo mostra direttamente.

Convertirle a linee avrebbe reso il totale non leggibile: si sarebbe dovuto
sommare a occhio quattro linee.

La coerenza che serve è fra rappresentazioni della stessa grandezza, non fra tutti
i grafici del portale.

## 4. Il raggio dei marcatori

```php
$r = count($dati) > 40 ? 1.4 : 2.5;
```

Su 92 punti in 650 pixel, ogni punto dista 7 pixel: con raggio 2,5 i cerchi
occupano 5 pixel su 7 e la linea scompare.

La soglia a 40 è dove i punti cominciano a distare meno di 16 pixel.

## 5. Il caso di tutte le serie a zero

`$mx` parte da 0.01 e non da 0. Con dati tutti a zero, `$yAt` dividerebbe per zero
e produrrebbe `INF` nelle coordinate — un SVG che il browser non disegna, senza
alcun errore visibile.

Con 0.01 tutte le linee si appiattiscono sull'asse, che è la lettura corretta.

## 6. La legenda che mentiva

Nella scheda personale il colore delle note era `#cbd5e1` nella legenda e
`#94a3b8` nel grafico: due grigi vicini, differenza invisibile a occhio ma reale.

L'ho trovata solo perché la conversione a linee mi ha fatto rileggere entrambi i
punti. È il genere di difetto che nessun controllo automatico intercetta e che
nessun utente segnala, perché non produce un errore — produce solo una legenda
leggermente sbagliata.
