# Technical Design — PortalManager v1.8.71

## 1. Una sincronizzazione che non rimuove è metà del lavoro

`writeRows()` fa `INSERT` e `UPDATE`. Non ha mai fatto `DELETE`, ed è una scelta
prudente: cancellare sulla base di un'assenza è pericoloso, perché un'assenza può
significare "rimosso alla fonte" oppure "la query di oggi non l'ha letto".

La conseguenza è che il portale accumula. Il caso della v1.8.70 — 67.786 rapporti
con codici di un formato dismesso — è il sintomo estremo di questo accumulo.

La riconciliazione risolve rendendo esplicito ciò che era implicito: confronta e
**mostra**, poi rimuove solo se qualcuno lo chiede.

## 2. Chiavi in memoria, righe in streaming

```php
$vive = [];
$st = $src->query(rtrim($d['sql'], "; \n"));
while (($raw = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
    …
    $vive[(string)$v] = true;
}
```

Si accumulano le **chiavi**, non le righe. Su 70.000 record il costo è di pochi
megabyte, contro i 36 MB che l'accumulo delle righe complete costerebbe per il
solo dataset delle allocazioni (misurato in v1.8.67).

L'array associativo dà la verifica di appartenenza in tempo costante: scorrendo
poi il target, ogni riga richiede un `isset()` e non una query.

## 3. `import_batch_id` come criterio di appartenenza

La domanda "questa riga viene dal gestionale?" ha una risposta già in tabella:
`import_batch_id` è valorizzato solo dalle righe scritte da una sincronizzazione.

```php
$wProt = $hasBatch ? "`import_batch_id` IS NOT NULL" : "1=1";
```

Il ripiego `1=1` per le tabelle senza quella colonna è deliberato: se non si può
distinguere l'origine, si tratta tutto come sincronizzato. È la scelta più
aggressiva delle due, ma dopo la v1.8.64 tutte e dodici le destinazioni hanno la
colonna, quindi il ripiego non si attiva. `columnExists()` lo verifica invece di
presumerlo.

## 4. Perché la verifica è separata dall'applicazione

Due azioni distinte — `reconcile_check` e `reconcile_apply` — e il pulsante di
rimozione compare **solo dopo** una verifica che abbia trovato orfane, riportando
il numero esatto.

Un'operazione che cancella righe non deve essere raggiungibile in un clic da uno
stato di ignoranza. Il numero nel pulsante è la parte che conta: «rimuovi 67.786
righe» è un'informazione, «riallinea» non lo è.

## 5. Campioni di chiave nell'esito

Il riquadro mostra fino a cinque chiavi orfane per dataset.

Un conteggio dice quanto, non che cosa. Vedere `DGB-14470, DGB-22675, …` permette
di riconoscere immediatamente un pattern — in quel caso, un formato di codice
dismesso — mentre «1.204 orfane» lascia solo la scelta fra fidarsi e non
procedere.

## 6. Rimozione a blocchi

```php
foreach (array_chunk($orfane, 500) as $blocco) {
    $ph = implode(',', array_fill(0, count($blocco), '?'));
    …
}
```

Una `IN` con decine di migliaia di segnaposto supera i limiti del protocollo e
degrada il piano di esecuzione. Cinquecento per volta è un compromesso conservativo.

Il tutto in una transazione con rollback, secondo lo schema della v1.8.64: un
errore a metà non deve lasciare la rimozione parziale.

## 7. Cosa la riconciliazione non può fare

Non riconosce righe **duplicate sotto chiavi diverse**. Se lo stesso intervento
esiste due volte con due codici che il gestionale conosce entrambi, per la
riconciliazione sono due righe legittime.

Era esattamente il caso della v1.8.70, dove i codici `DGB-<id>` non esistevano
nella sorgente — e per questo la riconciliazione li avrebbe individuati. Ma se un
giorno la sorgente contenesse essa stessa il doppione, servirebbe un controllo
sulla semantica e non sulla chiave.

Va detto perché una funzione chiamata "riallinea" può dare l'impressione di
garantire più di quanto garantisca.
