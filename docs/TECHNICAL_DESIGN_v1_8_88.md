# Technical Design — PortalManager v1.8.88

## 1. Parametro di visualizzazione contro filtro dei dati

Nella v1.8.86 `tec` era letto direttamente da `$_GET` nel corpo della pagina e
serviva solo a decidere se mostrare la scheda. Tutto il resto continuava a
interrogare i dati senza di esso.

Il risultato non conteneva dati sbagliati: mostrava dati **giusti riferiti a
un'altra cosa**, accanto a una scheda personale. È il genere di errore che nessun
controllo automatico intercetta, perché ogni singolo numero è corretto.

Spostando `tec` in `normFilters()` diventa parte del filtro, e `where()` — usata
da pannello, ripartizione, scoperti, code ed export — lo applica ovunque.

## 2. L'unità di misura del filtro

```sql
JOIN v_cm_sd_presa_carico pc ON pc.ticket = t.ticket AND pc.tecnico = ?
```

I ticket di un tecnico sono quelli che ha **preso in carico**, non quelli che ha
toccato. È la stessa definizione usata dalla scheda dalla v1.8.86.

Usarne una diversa qui avrebbe prodotto due numeri diversi per la stessa persona
nella stessa pagina — l'inverso del problema che si stava correggendo.

## 3. JOIN e non IN: un difetto dell'ottimizzatore

Il primo tentativo:

```sql
WHERE EXISTS (SELECT 1 FROM v_cm_sd_presa_carico pc
               WHERE pc.ticket = t.ticket AND pc.tecnico = ?)
```

Restituiva **2 ticket** invece di 520. Sospettando un'ambiguità di alias, riscritto
con `IN` non correlato: **ancora 2**.

Riprodotto in SQL puro:

| Forma | Risultato |
|---|---|
| `IN (SELECT pc.ticket …)` | 2 |
| `JOIN … ON pc.ticket = t.ticket` | **520** |

`v_cm_sd_ticket` è una vista che aggrega `v_cm_sd_messaggi`, a sua volta costruita
su `cm_sd_messages` e `v_cm_sd_team`. Attraverso tre livelli di annidamento
l'ottimizzatore materializza la sottoquery in modo scorretto.

Non è un difetto della condizione ma della sua risoluzione. Il join la evita, e ha
il vantaggio di dire con chiarezza che cosa seleziona.

**Lezione**: su viste annidate, verificare sempre una sottoquery contro il join
equivalente. Un filtro che restituisce troppo poco è silenzioso quanto uno che
restituisce troppo.

## 4. `where()` restituisce anche il join

```php
return [implode(' AND ', $w), array_merge($pre, $a), $join];
```

Il join precede la `WHERE` nella query, quindi i suoi parametri precedono gli
altri nell'array: `array_merge($pre, $a)` e non il contrario.

È il genere di dettaglio che produce un errore muto — parametri sostituiti nelle
posizioni sbagliate, query valida, risultato assurdo. Il collaudo lo intercetta
confrontando il pannello filtrato con la scheda.

## 5. Il report è una pagina autonoma

Non un layout alternativo di `service_desk.php` ma un blocco che termina con
`exit` prima di `header.php`.

Includere l'intestazione del portale avrebbe portato menu, barre e temi
personalizzati in un documento destinato a essere stampato e consegnato. Metà del
foglio sarebbe stata occupata da elementi di navigazione inutili su carta.

Il CSS usa **millimetri** per i margini e **punti** per il corpo: sono le unità
del foglio, e un `px` in stampa dipende dalla risoluzione scelta dal browser.

`page-break-inside: avoid` sulle sezioni evita il titolo orfano — un `<h2>` in
fondo alla pagina con la tabella che comincia in quella dopo.

## 6. Le avvertenze viaggiano con i numeri

Il piè di pagina di ogni report ripete che la durata comprende le attese del
cliente e che nessun SLA è definito.

Nel pannello quelle note stanno sotto le tabelle e chi legge è la stessa persona
che ha impostato i filtri. Un documento stampato circola senza di lei: i limiti
devono essere sul foglio, non nella testa di chi lo ha prodotto.

## 7. La sezione dei moduli nel report personale

Interroga `moduliRiepilogo()` e `moduliContratto()` dentro il blocco di stampa,
non prima: se il report è generale quei dati non servono, e caricarli comunque
costerebbe due query per nulla.

Il compromesso è che il codice del report contiene chiamate al modello invece di
ricevere tutto pronto. Accettabile perché il blocco è autonomo e termina con
`exit`.
