# Technical Design — PortalManager v1.8.61

## 1. Perché la ricerca precedente aveva fallito

La v1.8.60 cercava il costo direzionale così:

```sql
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE COLUMN_NAME LIKE '%direz%' OR COLUMN_NAME LIKE '%overhead%' …
```

Metodo ragionevole e insufficiente. Il costo direzionale non è un attributo ma
un'**istanza**: una riga di `forms_contract_operation` con
`id_contract_operation_type = 4`. Nessuna colonna lo nomina, perché il nome sta
nei *dati* di una tabella di tipi.

È una lezione generalizzabile: cercare un concetto fra i nomi di colonna trova
solo i concetti modellati come attributi. Quelli modellati come righe di una
tabella di classificazione richiedono di leggere i **contenuti** delle tabelle di
lookup — che nel dump erano 34 tipi di attività, 6 tipi di operazione e altre
simili.

## 2. Tre campi, un importo

`forms_contract_operation` ha `cost`, `revenue` e `final_value`. La tentazione è
sommarli tutti o sceglierne uno. Entrambe sbagliate: ogni tipo ne usa uno solo.

| Verifica sui dati | Esito |
|---|---|
| Ordini cliente (1.145) con `revenue` | 1.106 |
| Ordini cliente con `cost` | **1** |
| Costi direzionali (174) con `cost` | 170 |
| Costi direzionali con `revenue` | **0** |

La distinzione è netta e segue il campo `type` della tabella dei tipi: REC →
`revenue`, FVC → `cost`, REP → `final_value`.

La normalizzazione avviene **in import**, non in lettura:

```sql
CASE t.type
    WHEN 'REC' THEN COALESCE(o.revenue,0)
    WHEN 'FVC' THEN COALESCE(o.cost,0)
    WHEN 'REP' THEN COALESCE(o.final_value,0)
    ELSE 0 END AS `importo`
```

Così nessuna query a valle deve conoscere la semantica, e una query che la
ignorasse produrrebbe zero anziché un numero plausibile e sbagliato.

`signed_amount` porta anche il segno, perché il saldo è una somma e un saldo
calcolato con i segni sbagliati è più insidioso di uno mancante.

## 3. I riporti negativi

30 riporti su 85 hanno importo negativo.

Un `COV` negativo è un saldo a debito trascinato dal contratto precedente:
concettualmente è un'alimentazione, operativamente sottrae. Trattare il tipo come
sempre positivo — per esempio con `ABS()` o con un `sign = +1` applicato a un
valore assoluto — avrebbe sovrastimato l'alimentazione.

Il segno del tipo (`sign`) governa la **direzione concettuale**; il segno del
valore resta quello del dato. I due si moltiplicano, non si sostituiscono.

## 4. Ore e addebiti restano separati

`v_cm_saldo_commessa` espone `costo_ore` e `totale_addebiti` come colonne
distinte, e il saldo finale li sottrae entrambi.

Fonderli in un "costo totale" sarebbe stato più compatto e avrebbe perso
l'informazione che conta: una commessa in perdita per acquisti di beni ha un
problema diverso da una in perdita per ore eccedenti. La prima è un problema di
preventivazione, la seconda di esecuzione.

Su WTS_3228 (presidio): 127.500 di ordini, 10.361 di beni, **179.654 di costo
ore**. Il problema è l'esecuzione, e si vede solo tenendo le due voci separate.

## 5. Il saldo non sostituisce il valore di commessa

`valore_commessa` resta nella vista accanto a `totale_alimentazioni`. Non sono la
stessa cosa: il valore è quanto la commessa vale a contratto, le alimentazioni
sono gli ordini effettivamente emessi.

Su 953 commesse con operazioni, i due numeri divergono spesso — ed è normale, un
contratto quadro può avere ordini progressivi. Ma la divergenza è essa stessa
un'informazione, e sostituire l'uno con l'altro la cancellerebbe.

## 6. Prima tabella senza duplicati

`forms_contract_operation`: 3.889 righe, 3.889 id distinti.

Dopo `dgb_operator_allocations_on_forms_contract` (v1.8.57) e `dgb_operator`
(v1.8.60), è la prima tabella importata a non avere righe duplicate. Il controllo
`COUNT(*)` contro `COUNT(DISTINCT id)` resta parte della procedura per ogni nuova
tabella: è costato due secondi e ha già evitato due errori.

## 7. Cosa resta scoperto

Il dataset produce 2.628 righe su 3.400 operazioni non cancellate. La differenza —
772 righe — sono operazioni il cui contratto non ha un `code`, e senza codice
commessa non sono agganciabili.

Delle 2.628 importate, 2.597 trovano la commessa nel portale. Le 31 restanti
riguardano commesse non ancora sincronizzate.
