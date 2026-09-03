# Technical Design — PortalManager v1.8.67

## 1. Il limite di 200 righe non era pigrizia

`readSource()` costruisce l'array completo prima di restituirlo:

```php
$rows = [];
while (($raw = $st->fetch(PDO::FETCH_ASSOC)) !== false) { $rows[] = $this->mapRow($raw, $map); }
return $rows;
```

È corretto per la scrittura, che ha bisogno di iterare più volte e di conoscere
il totale. Ha un costo misurabile: 36 MB per 69.326 righe, circa 540 byte a riga.

Su undici dataset e ~165.000 righe si arriva intorno ai 100 MB, contro un
`memory_limit` che su XAMPP è tipicamente 128 MB. Il margine non è sufficiente:
l'esaurimento avverrebbe a metà lavoro, con un messaggio che non spiega nulla.

## 2. Streaming: memoria costante

```php
while (($raw = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
    $total++;
    …
    $stExists->execute([$keyVal]);
    if ($stExists->fetchColumn()) $upd++; else $ins++;
    $stExists->closeCursor();
}
```

Nessun accumulo: ogni riga viene esaminata e scartata. Misurato: **6 MB** contro
36, e il valore non cresce con il volume — è il buffer del driver, non i dati.

Il costo si sposta sulle query: una `SELECT ... LIMIT 1` per riga, per stabilire
se la chiave esiste già. Su decine di migliaia di righe sono decine di migliaia
di query, ed è la ragione per cui l'anteprima integrale richiede minuti mentre
quella su 200 righe è istantanea.

È un compromesso esplicito: tempo in cambio di memoria. Per un'operazione che
l'utente lancia deliberatamente e attende, è il verso giusto.

`closeCursor()` dopo ogni verifica non è decorativo: senza, il driver mantiene
aperto il result set e la query successiva sulla stessa connessione fallisce.

## 3. previewAll non riusa writeRows

Si sarebbe potuto invocare `writeRows()` con `$dryRun = true`. Non è stato fatto:
`writeRows` riceve un array, quindi avrebbe richiesto comunque l'accumulo — il
problema che si voleva evitare.

Il costo è una parziale duplicazione della logica di conteggio. È accettabile
perché `previewAll` non scrive: la sua unica responsabilità è contare, e un
errore lì produce un numero sbagliato, non un dato corrotto.

## 4. Il profilo orario: ripartire, non attribuire

La domanda «in quale ora si lavora» ha una risposta ovvia e sbagliata: guardare
`HOUR(date_start)`.

Sui dati produce un picco del **64%** sull'ora 9. Non è un dato sul lavoro: è un
dato su come viene *registrato* il lavoro, cioè con inizio 09:00 per convenzione.

La ripartizione calcola, per ogni ora del giorno, la sovrapposizione fra
l'intervallo dell'intervento e quella fascia:

```sql
GREATEST(0, LEAST(TIME_TO_SEC(TIME(date_dead_line)), (h.n+1)*3600)
          - GREATEST(TIME_TO_SEC(TIME(date_start)), h.n*3600))
/ NULLIF(TIMESTAMPDIFF(SECOND, date_start, date_dead_line), 0)
```

È la stessa espressione della classificazione ordinario/reperibilità della
v1.8.53, applicata a 24 finestre invece che a due. La `WITH RECURSIVE` genera le
24 ore e il `CROSS JOIN` le incrocia con gli interventi.

Il risultato è verificabile per confronto indipendente: le ore fuori fascia
risultano il **13,8%**, contro il **13,48%** che la v1.8.53 calcola per altra
via. Due misure che convergono senza essere state costruite l'una sull'altra.

## 5. La scala del colore

La cella massima vale 2,9 volte la media, e il 52% delle celle sta sotto il 15%
del massimo. Con una scala lineare metà della matrice sarebbe indistinguibile dal
bianco e leggibile solo il picco.

```php
$t = sqrt($v / $max);
```

La radice comprime l'alto e distende il basso: le differenze fra celle piccole,
che con la scala lineare sparirebbero, restano visibili.

È una scelta di rappresentazione, non di calcolo: il valore esatto è nel
suggerimento di ogni cella, la scala serve solo a rendere leggibile la forma.

## 6. Perché solo in vista giornaliera

Sulle dodici barre mensili una distribuzione oraria non avrebbe un asse su cui
svilupparsi: le ore di un mese intero appiattite su 24 fasce perderebbero la
struttura che rende la matrice utile — vedere *quali giorni* hanno avuto lavoro
notturno, non solo quanto ce n'è stato in totale.

Il caricamento è condizionato a `$gran === 'day'`, quindi la vista mensile non
paga il costo di una query che non userebbe.
