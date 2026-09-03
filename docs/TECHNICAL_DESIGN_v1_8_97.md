# Technical Design — PortalManager v1.8.97

## 1. Il nome che inganna

`employees.valore_tabp` sembra il campo giusto e non lo è: contiene 46,48 €, il
valore unitario del buono pasto. È un **ingresso** del calcolo, non il risultato.

Cercando «TotCostoTab» fra le colonne non si trova nulla, perché il risultato non
è mai stato materializzato. La somiglianza dei nomi ha fatto sembrare il campo
vuoto quando in realtà era pieno di un dato diverso.

## 2. Perché una tabella e non un calcolo al volo

`CostModel::compute()` è invocabile e restituisce il valore corretto. Usarlo
direttamente nelle viste era impossibile — SQL non chiama PHP — e usarlo nel
modello avrebbe significato migliaia di invocazioni per pagina.

Ma la ragione decisiva è un'altra: **il calcolo usa i parametri correnti**.
`refs($year)` legge i riferimenti dell'anno, ma RAL e overhead vengono dalla
scheda del dipendente com'è oggi. Applicare il costo di oggi a un intervento del
2024 significa che un aumento di stipendio riscrive il margine di commesse chiuse.

Un report stampato il mese scorso deve restare riproducibile.

## 3. Il ripiego, e perché non lasciare vuoto

```sql
LEFT JOIN cm_employee_cost_year cp
       ON cp.year = (SELECT MAX(x.year) … WHERE x.year < a.year …)
      AND c.year IS NULL
```

L'alternativa era nessun costo finché il dato non c'è. Avrebbe lasciato l'anno
corrente senza redditività per mesi — da gennaio fino a quando il bilancio
precedente viene chiuso.

Il ripiego usa l'ultimo esercizio **precedente**, mai uno successivo: un costo del
2026 applicato al 2025 sarebbe un anacronismo.

`costo_origine` qualifica sempre il valore — «consolidato» o «stimato da 2025» —
perché un margine costruito su stime e uno su consuntivi hanno la stessa forma e
affidabilità diversa. `copertura_pct` lo aggrega per commessa.

## 4. Parametri per anno, non globali

`cm_cost_year_params` ha una riga per esercizio.

Un valore globale sarebbe bastato per il calcolo corrente e avrebbe reso
impossibile ricostruire un calcolo passato: cambiando i giorni da 220 a 254, tutti
i costi storici si sposterebbero del 15%.

`is_closed` è la protezione esplicita: un esercizio chiuso non si ricalcola senza
`--force`.

## 5. Costo aziendale e costo di vendita non si sostituiscono

Sui dati: 3,1 M€ contro 7,9 M€. Il secondo è 2,5 volte il primo.

Sarebbe stato semplice sostituire `company_cost_import` con il costo calcolato e
presentare un solo margine. Sarebbe stato anche sbagliato: le due grandezze
rispondono a domande diverse.

Il **costo aziendale** dice quanto costa erogare il servizio. Il **costo di
vendita** dice quanto è stato addebitato. La differenza è il ricarico applicato, e
`scarto_costo_pct` la misura: dove è bassa o negativa, gli addebiti non coprono il
costo di erogazione.

Nasconderla dietro un margine unico avrebbe eliminato proprio l'informazione per
cui il calcolo è stato chiesto.

## 6. Il difetto intercettato

```php
$res = $cm->compute($e, $anno);
$tot = $res['tot_costo_tab'] ?? null;   // sempre null
```

`compute()` restituisce `['used' => …, 'calc' => …, 'errors' => …]`. I valori
derivati stanno sotto `calc`.

Il difetto non produceva errori: 286 dipendenti processati, 286 «senza dati
economici», zero eccezioni. Un guasto silenzioso che si vedeva solo confrontando
il risultato con l'attesa — e l'attesa era che almeno qualche dipendente avesse
un costo.

È la stessa categoria del `Router::hiddenParams` e del filtro `IN` sulle viste
annidate: codice sintatticamente valido che restituisce il tipo giusto e il
contenuto sbagliato.
