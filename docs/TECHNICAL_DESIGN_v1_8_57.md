# Technical Design — PortalManager v1.8.57

## 1. Ricostruire le relazioni senza vincoli dichiarati

Il dump non contiene FOREIGN KEY. Le relazioni sono state desunte dalla
nomenclatura: una colonna `id_<nome>` che corrisponde a una tabella `<nome>`,
`forms_<nome>`, `dgb_<nome>` o `tt_<nome>` è trattata come riferimento.

Il metodo ha prodotto 245 relazioni e ha permesso di ordinare le tabelle per
numero di riferimenti in ingresso — l'indicatore che distingue una dimensione da
un fatto. `dgb_installation` con 71 riferimenti è la dimensione principale,
`forms_contract` con 19 è la commessa.

Il limite del metodo va dichiarato: una relazione che non segua la convenzione di
nome non viene vista. È accettabile perché lo schema la rispetta con costanza, ma
non è una garanzia.

## 2. Le tre tabelle scelte, e perché queste

Il criterio non è stato "quali tabelle hanno più dati" ma "quali colmano una
lacuna dell'analisi". Il portale aveva le ore e i ricavi dichiarati; mancava il
**denominatore economico**: quanto vale un'ora su quella commessa e quanto costa
quella persona.

`forms_um_rate_for_contract` fornisce il primo, `forms_cost_range` il secondo.
`dgb_operator_allocations_on_forms_contract` aggiunge la dimensione del
pianificato, che permette di confrontare chi era assegnato con chi ha
consuntivato.

Tabelle con volumi molto maggiori — `dgb_oplog_archive` con 3.073 blocchi INSERT
— sono state scartate: sono log applicativi, non hanno valore analitico.

## 3. Duplicati esatti nella sorgente

`dgb_operator_allocations_on_forms_contract` non ha PRIMARY KEY. Il conteggio:

```
199.458 righe, 99.729 valori distinti di id
```

Esattamente il doppio. Le due righe con lo stesso `id` sono **identiche in ogni
campo**, e la verifica sul file del dump conferma che la tupla vi compare due
volte: è la sorgente, non il caricamento.

Senza `DISTINCT` il portale avrebbe importato 199.458 allocazioni invece di
99.729, e `id` non sarebbe stato utilizzabile come chiave di deduplica. Con
`DISTINCT` le righe identiche collassano e `id` torna univoco — verificato: zero
gruppi duplicati sulla chiave.

È lo stesso genere di problema affrontato nelle v1.8.50 e v1.8.51, questa volta a
monte: nella sorgente anziché nel portale.

## 4. Il fan-out sulle tariffe

Il join iniziale era diretto:

```sql
LEFT JOIN cm_contract_rates rt ON rt.project_code = r.project_code
                              AND rt.rate_nature = 'R' AND rt.rate_unit = 'H'
```

Sembra selettivo — una commessa, una natura, una unità — e non lo è: la tariffa
dipende anche dal **tipo di attività**, e una commessa ne ha in media sei.

Effetto misurato: le righe della vista passavano da 67.723 a **379.247**, con
ogni somma gonfiata di cinque volte. È il fan-out classico, e la sua insidia è
che il risultato resta plausibile: nessun errore, solo numeri sbagliati.

Correzione: aggregare prima di unire.

```sql
LEFT JOIN (
    SELECT project_code, ROUND(AVG(rate_value),4) AS rate_value, COUNT(*) AS n_rates
      FROM cm_contract_rates
     WHERE rate_nature='R' AND rate_unit='H' AND rate_value > 0
     GROUP BY project_code
) rt ON rt.project_code = r.project_code
```

**Media e non minimo o massimo**: non sapendo a quale tipo di attività
l'intervento appartenga, la media è la stima meno distorta. Il minimo
sottostimerebbe sistematicamente il ricavo, il massimo lo sovrastimerebbe.

La colonna `tariffe_disponibili` espone su quante tariffe la media è calcolata:
con una sola è esatta, con sei è una media. Chi legge deve poter sapere quanto è
grossolana la stima.

## 5. Rilevato contro stimato

La vista non sostituisce mai un valore rilevato con uno stimato. La tariffa
interviene **solo** dove il ricavo dichiarato manca:

```sql
COALESCE(NULLIF(client_revenue_import, 0), ROUND(ore * rate_value, 2))
```

E accanto al valore c'è sempre la sua origine — `rilevato`, `stimato da tariffa`,
`non disponibile`. Un'analisi che mescoli i due senza distinguerli produce numeri
che nessuno può difendere in una discussione con il cliente.

Lo stesso vale per il costo, con la fascia oraria al posto della tariffa.

## 6. Ordine di sincronizzazione

```php
$ordine = ['commesse', 'costi_fascia', 'professionisti', 'tariffe', 'allocazioni', 'rapporti'];
foreach (self::keys() as $k) if (!in_array($k, $ordine, true)) $ordine[] = $k;
```

Le commesse per prime perché tariffe, allocazioni e rapporti vi si agganciano per
codice. Costi fascia e professionisti sono indipendenti e stanno prima per
comodità di lettura del riepilogo.

I dataset non elencati finiscono in coda: chi ne aggiunge uno in futuro non è
costretto a toccare l'ordine perché la sincronizzazione continui a funzionare.

## 7. Un dataset che fallisce non ferma gli altri

```php
try { … } catch (Throwable $e) { $esiti[] = ['esito' => 'errore', …]; }
```

Interrompere al primo errore lascerebbe l'insieme a metà: alcune tabelle
aggiornate, altre no, e nessuna indicazione di dove ci si sia fermati. È lo stato
peggiore da diagnosticare.

Proseguendo, il riepilogo mostra esattamente quali dataset sono passati e quali
no, con il messaggio di errore accanto a ciascuno.

## 8. Anteprima limitata, e dichiarata

L'anteprima legge 200 righe per dataset e non scrive nulla. Il riquadro dell'esito
lo dice esplicitamente: senza, i numeri dell'anteprima verrebbero letti come
volumi reali, e su 99.729 allocazioni la differenza è sostanziale.
