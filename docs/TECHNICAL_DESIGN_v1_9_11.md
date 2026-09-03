# Technical Design — PortalManager v1.9.11

## 1. Il raccordo esisteva, da un'altra parte

La v1.9.10 si era fermata perché il ticket non porta la commessa. Era vero e
irrilevante: il **modulo di intervento** la porta.

Il difetto era nel punto di partenza. Ho cercato il raccordo nella tabella dei
ticket perché l'obiettivo parlava di ticket, invece di chiedermi quale altra
tabella contenesse la stessa attività con più informazioni.

È la stessa forma dell'errore della v1.9.2: ricostruire `margin_total` senza prima
verificare se esistesse già. Cercare dove il problema si presenta, invece di dove
il dato sta.

## 2. L'unità organizzativa come criterio dichiarato

`cm_tech_units` → «Service Desk» ha 4 profili.

La v1.8.96 sui presidi aveva dovuto costruire una soglia di esclusività perché
l'unità non era popolata — un criterio osservato, con una convenzione da
dichiarare. Qui il criterio dichiarato c'è, ed è preferibile: l'appartenenza a
un'unità è una decisione aziendale, la soglia è una mia interpretazione.

Il join prova sia `nome` sia `employee_id`, perché i moduli scrivono il nome come
testo e il collegamento all'anagrafica può mancare.

## 3. La natura viene dalla commessa

```sql
CASE WHEN COALESCE(cm.has_revenue, 1) = 1 THEN 'fatturabile' ELSE 'interna' END
```

`has_revenue` è già popolato in `cm_contract_models`: non serviva una regola nuova,
e inventarne una avrebbe creato una seconda definizione di «fatturabile»
divergente dalla prima.

Il `COALESCE` a 1 tratta le linee non classificate come fatturabili: è la scelta
prudente, perché contare come interna un'attività a ricavo gonfierebbe il costo
non addebitato.

## 4. Due valorizzazioni affiancate

`valore_addebitato` è `company_cost_import`, esposto solo dove è maggiore di zero:
zero in quella colonna significa «non valorizzato», non «gratis».

`valore_listino` è ore × tariffa, e si applica a tutti i moduli. È l'unico modo di
valorizzare quelli interni, che per definizione non hanno addebito.

Sostituire l'uno con l'altro avrebbe eliminato l'informazione più interessante: la
divergenza fra quanto è stato addebitato e quanto varrebbe a listino.

## 5. Tariffe NULL e non zero

```sql
tariffa_ora decimal(10,2) DEFAULT NULL
  COMMENT 'NULL = non stabilita. Zero significherebbe gratis'
```

Con zero come predefinito, il valore a listino sarebbe stato `0,00` su tutte le
righe — un numero che si somma, si mostra in una colonna e sembra un dato.

Con NULL, la colonna resta vuota e il pannello segnala quante linee mancano.

Verificato: rimossa la tariffa di ACM, `valore_listino` diventa NULL e non 0.

## 6. Una funzione per due nature

`obj2xDettaglio($f, $natura)` serve sia OBJ_2.1 sia OBJ_2.2.

Le colonne sono le stesse e il filtro cambia di un valore: due metodi separati
avrebbero significato correggere due volte ogni modifica, e la seconda volta
dimenticarne una.

La quota percentuale è calcolata **in PHP** sul totale delle righe restituite, non
in SQL su una sottoquery: con il filtro di periodo attivo, una sottoquery avrebbe
dovuto ripetere tutte le condizioni, e ripeterle è il modo in cui divergono.

## 7. «Interventi» e non «ticket»

L'obiettivo chiedeva un numero di ticket. Dai moduli si contano moduli.

Un ticket può generare più moduli — una richiesta lavorata in tre sessioni — e un
modulo può coprire più ticket, quando il tecnico consuntiva una giornata su più
richieste.

Chiamare `ticket` il conteggio dei moduli avrebbe prodotto un numero che si
confronta con quello della sezione ticket e non torna, senza che nessuno sappia
perché.
