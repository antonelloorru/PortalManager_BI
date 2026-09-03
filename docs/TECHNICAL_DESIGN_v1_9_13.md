# Technical Design — PortalManager v1.9.13

## 1. La soglia in giorni, non in mesi

«Tre mesi» non è una durata: gennaio-marzo dura 90 giorni, maggio-luglio 92,
dicembre-febbraio 90 o 91 secondo l'anno.

Contare i mesi avrebbe richiesto di decidere cosa fare di un periodo che va dal 15
gennaio al 20 aprile — tre mesi e cinque giorni. Contare i giorni elimina la
domanda.

92 è il trimestre più lungo: con 90, due periodi che l'utente chiama entrambi «tre
mesi» si sarebbero comportati in modo diverso, e la differenza sarebbe stata
invisibile guardando il filtro.

## 2. `giorniPeriodo` conta gli estremi

Il 1 e il 31 gennaio distano 30 giorni ma il periodo ne comprende 31: è la
differenza fra «quanto tempo passa» e «quanti giorni guardo», e qui serve la
seconda.

Restituisce 0 su periodo assente o invertito, e il chiamante ricade sulla grana
mensile — la scelta prudente, perché un grafico con troppe barre è peggio di uno
con poche.

## 3. La chiave resta `ym`, la grana viaggia con i dati

Cambia il formato, non il nome: `2026-06` o `2026-06-15`.

Rinominare il campo avrebbe richiesto di toccare la pagina, i due report e
l'export — quattro punti, quattro occasioni di dimenticarne uno.

`grana` accompagna ogni riga. L'alternativa sarebbe stata dedurla da
`strlen($ym) === 10`, che funziona finché nessuno introduce un formato
settimanale: è il tipo di accordo implicito che si rompe quando chi lo ha stabilito
non c'è più.

## 4. Il formato in una variabile PHP dentro la query

```php
$fmt = $perGiorno ? '%Y-%m-%d' : '%Y-%m';
$st = $this->pdo->prepare("SELECT DATE_FORMAT(t.`aperto_il`, '$fmt') AS ym, …");
```

`$fmt` è interpolato nella stringa SQL invece di essere un parametro. È
accettabile perché il valore non viene mai dall'esterno: è uno di due letterali
scelti da un booleano, e non c'è percorso per cui l'utente possa influenzarlo.

Un parametro avrebbe funzionato ugualmente, ma `DATE_FORMAT` con formato
parametrizzato impedisce a MariaDB di usare l'indice sulla data.

## 5. Il grafico si adatta alla densità

Con i punti da 3 pixel su 92 date la linea sparisce sotto i cerchi: il raggio
scende a 1,6.

Le etichette passano da 1 su 8 a 1 su 12 — su 92 punti, 12 etichette sono una ogni
8 giorni, che è leggibile senza sovrapposizioni.

## 6. Il limite: nessun riempimento delle date vuote

L'asse mostra solo le date che hanno almeno un ticket. Su un servizio con attività
quotidiana non si nota; su uno con ticket sparsi, due punti adiacenti possono
distare una settimana e la linea suggerisce una continuità che non c'è.

Riempire richiederebbe di generare il calendario — una tabella di date o una
ricorsione — e unirlo ai dati. È una modifica circoscritta ma non gratuita, e non
l'ho fatta senza sapere se il fenomeno si presenta sui dati reali.

Dichiarata nel deployment.
