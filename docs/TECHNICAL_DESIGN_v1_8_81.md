# Technical Design — PortalManager v1.8.81

## 1. Una categoria che non esiste nello schema

La richiesta includeva una serie "Visite". Nei tipi di impegno non c'è: sono
sette, e nessuno si chiama così.

Cercando nelle **descrizioni** emergono 173 impegni per 573 ore, distribuiti su
quattro tipi. La stessa causale — «visita medica» — è registrata come permesso da
alcuni, come recupero da altri, come ferie da altri ancora.

Tre strade:

1. dire che la serie non è realizzabile;
2. crearla come categoria, riclassificando i 173 impegni;
3. costruirla come vista **trasversale** sulla descrizione.

La prima ignora un dato che esiste. La seconda modifica dati che il gestionale
possiede e che tornerebbero come sono alla prima sincronizzazione.

La terza espone il dato senza alterarlo, al prezzo di una serie che **si
sovrappone** alle altre. Il prezzo è accettabile se dichiarato: la serie è
tratteggiata, la legenda lo dice, e la documentazione spiega perché.

## 2. Palette separata, non un'estensione

Le risorse usano dodici colori assegnati ciclicamente. Le assenze ne usano
quattro scelti fuori da quella scala.

Non è una questione estetica: due serie con colori della stessa famiglia
suggeriscono che siano confrontabili. Il carico di una risorsa e le ferie
aziendali condividono l'asse dei tempi e nient'altro — sommarle o confrontarle
non ha significato.

## 3. La commutazione non ridisegna

```javascript
g.style.display = nascosto ? '' : 'none';
```

Le serie restano nel documento; cambia solo la visibilità del gruppo SVG.

L'alternativa — rimuovere e ricostruire, o ricaricare la pagina con un parametro
— avrebbe richiesto una richiesta al server per un'operazione che è puramente
visiva, e avrebbe perso lo scorrimento della pagina a ogni clic.

Il costo è che tutte le serie vengono comunque calcolate e trasmesse. Su quattro
serie e dodici mesi è trascurabile.

## 4. L'asse Y deve contenere anche le assenze

```php
foreach ($absSeries as $serie) foreach ($serie as $v) if ($v > $yMax) $yMax = $v;
$yAt = fn($v) => $padT + $plotH - ($yMax > 0 ? ($v / $yMax) * $plotH : 0);
```

`$yAt` viene ridefinita **dopo** l'aggiornamento di `$yMax`: le closure in PHP
catturano il valore al momento della definizione, non un riferimento.

Senza la ridefinizione, i punti delle assenze sarebbero stati calcolati con la
scala vecchia e usciti dal grafico o schiacciati.

## 5. Il filtro per operatore: id, non nome

Il primo tentativo confrontava `operator_name LIKE '%nome%'`. Non funzionava, e
non produceva errori: `normFilters()` normalizza `operator` a **intero**, e la
conversione `(string)` di un intero dà `"0"`, che `trim()` lascia non vuoto — ma
il confronto sul nome non trova nulla.

Il `catch` che protegge il blocco inghiottiva tutto, e il risultato era zero
assenze **in ogni caso**, anche senza filtro.

È il difetto insidioso: un `catch` largo trasforma un errore in un dato mancante,
e un dato mancante somiglia a un dato assente.

La correzione usa `operator_id`, che è anche il legame corretto: confrontare i
nomi esporrebbe alle differenze di forma fra gestionale e anagrafica, già viste
con l'inversione nome/cognome della v1.8.77.

## 6. Il container della matrice

Il blocco usava margini propri e risultava più stretto degli altri grafici della
colonna. La tabella aveva larghezza naturale, quindi variava con il numero di
giorni del mese.

`width:100%` con `table-layout:fixed` la rende stabile: le colonne si dividono lo
spazio disponibile invece di dimensionarsi sul contenuto, e un mese di 28 giorni
occupa la stessa larghezza di uno di 31.
