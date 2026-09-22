# Technical Design — PortalManager v1.8.89

## 1. Una riga per intervento, tutte le dimensioni

`v_cm_it_servizio` unisce sei fonti su ogni riga: il modulo, la commessa, il
modello contrattuale, il profilo tecnico, la sede e l'allocazione DGB.

È costosa da calcolare ma paga: ogni aggregazione della sezione — per incaricato,
per linea, per settore, per modalità, o per qualunque combinazione — è un
`GROUP BY` su questa vista, senza join da riscrivere ogni volta.

## 2. La modalità è esclusiva, e l'ordine conta

```sql
WHEN during_availability = 1 THEN 'reperibilita'
WHEN smart_working = 1       THEN 'smart working'
WHEN from_remote = 1         THEN 'da remoto'
WHEN trip_hours > 0          THEN 'presso cliente'
ELSE 'in sede'
```

I flag della sorgente **non sono mutuamente esclusivi**: un intervento può essere
insieme in reperibilità e da remoto.

Una classificazione esclusiva richiede un ordine di precedenza, e quello scelto va
dal più specifico al più generico: la reperibilità è una condizione contrattuale
che qualifica l'intervento indipendentemente da dove venga svolto.

L'alternativa — colonne booleane separate — avrebbe reso impossibile la
ripartizione percentuale, perché le quote avrebbero superato il 100%.

## 3. Giornate-uomo e non giorni

```sql
COUNT(DISTINCT CONCAT(incaricato, '|', giorno)) AS giornate_uomo
```

Tre misure diverse, spesso confuse:

| Misura | Significato |
|---|---|
| `COUNT(*)` | interventi |
| `COUNT(DISTINCT giorno)` | giorni di calendario coperti |
| `COUNT(DISTINCT incaricato|giorno)` | **giornate-uomo** |

Solo la terza permette di calcolare le ore medie per giornata. Sui dati dà **7,25
ore**, che è plausibile e conferma il calcolo — con una delle altre due il
risultato sarebbe stato assurdo e lo avremmo notato.

## 4. I km NULL e non zero

```sql
d.km_one_way AS km_andata,
CASE WHEN d.km_one_way IS NOT NULL THEN ROUND(d.km_one_way * 2, 2) END AS km_percorsi
```

Se la coppia sede-cliente non è geocodificata, il valore è NULL.

Con zero, `SUM()` funzionerebbe ugualmente ma `AVG()` no: la media chilometrica
includerebbe le trasferte non misurate come se fossero state a distanza nulla,
abbassandola in proporzione alla copertura.

La copertura è esposta come indicatore proprio perché il totale dei km, da solo,
non dice quanto sia attendibile.

## 5. Cache delle distanze per coppia

`cm_it_distances` ha una riga per `(location_id, client_id)`, con vincolo di
unicità.

La distanza fra due indirizzi non cambia: calcolarla a ogni intervento
significherebbe migliaia di chiamate a un servizio esterno a pagamento per
ottenere lo stesso numero.

`source` distingue `manuale`, `geocodifica` e `stimata`: una distanza inserita a
mano ha un'affidabilità diversa da una calcolata, e chi legge deve poterlo sapere
senza chiedere.

## 6. Filtri come liste, non valori singoli

```php
'linee' => $arr($q['linee'] ?? []),
```

La richiesta era di poter aggregare più elementi — «ad esempio più linee di
servizio». Ogni dimensione accetta quindi una lista.

La semantica: **più valori sulla stessa dimensione si sommano** (OR dentro un
`IN`), **dimensioni diverse si restringono** (AND fra le clausole). È quello che
ci si aspetta da un pannello di filtri, ed è verificato: tre linee danno 3.937
interventi, le stesse tre più «da remoto» ne danno 613.

## 7. Il raggruppamento è validato contro un elenco chiuso

```php
$gb = array_values(array_intersect($gb, array_keys(self::DIM)));
```

Le dimensioni arrivano dalla richiesta HTTP e finiscono in un `GROUP BY`: non
possono essere interpolate senza controllo.

L'elenco chiuso è anche documentazione — dice quali combinazioni la sezione
supporta — e permette di etichettare le colonne senza una seconda mappa.

## 8. Che cosa resta

La pagina non è in questa release. Il modello espone già tutto ciò che le serve:
`totali()`, `aggrega()`, `perDimensione()`, `andamento()`, `valori()`,
`statoKm()`, `distanzeMancanti()`.

La costruzione della pagina è lavoro di presentazione — grafici, filtri, export,
stampa — su fondamenta che sono verificate.
