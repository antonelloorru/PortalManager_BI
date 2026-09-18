# Manuale Amministratore — v1.8.61

## Il costo direzionale è stato trovato

Nella versione precedente vi avevo detto che il costo direzionale non era nei
dati. **Mi sbagliavo, ed è utile capire perché.**

L'avevo cercato fra i *nomi delle colonne* di tutte le 102 tabelle. Non c'è,
perché non è un attributo: è un **tipo di operazione** registrato nel mastrino
della commessa, il codice `FVCCD`, per **1.794.924,71 €** su 174 operazioni.

Cercare un concetto fra i nomi di colonna trova solo ciò che è modellato come
attributo. Quello che sta nelle righe di una tabella di classificazione richiede
di leggerne i contenuti.

## Il mastrino della commessa

`forms_contract_operation` contiene sei tipi di operazione:

| Codice | Nome | Effetto | Op. | Importo |
|---|---|---|---|---|
| COV | Riporto da contratto precedente | alimenta | 85 | +688.036 |
| COR | Ordine cliente | alimenta | 841 | +26.892.498 |
| REP | Storno | rettifica | 4 | +57.376 |
| FVCCD | Costo Direzione Commerciale | addebita | 145 | −1.232.152 |
| FVCBGS | Acquisto Beni e Servizi | addebita | 872 | −2.355.600 |
| INVMEMO | Promemoria di fatturazione | neutro | 681 | 0 |

**Saldo complessivo: 24.050.158 €.**

### Un dettaglio importante

I tre campi importi della tabella — costo, ricavo e valore finale — **non sono
intercambiabili**: ogni tipo ne usa uno solo. Gli ordini cliente stanno in
*ricavo*, i costi in *costo*, gli storni in *valore finale*.

Il portale normalizza l'importo in fase di import, quindi non dovete
preoccuparvene. Ma se interrogate direttamente il gestionale, sommare il campo
sbagliato restituisce zero.

### I riporti possono essere negativi

30 riporti su 85 hanno importo negativo: un contratto precedente può lasciare un
saldo a debito. Il portale li tratta con il loro segno.

## Il saldo di commessa

```sql
SELECT commessa, linea_servizio, ordini_cliente, costo_direzionale,
       acquisto_beni_servizi, costo_ore, saldo_finale
  FROM v_cm_saldo_commessa
 WHERE n_operazioni > 0 ORDER BY saldo_finale ASC;
```

Il saldo è: **alimentazioni − addebiti − costo delle ore**.

Le tre voci restano separate di proposito. Una commessa in perdita per acquisti
ha un problema di preventivazione; una in perdita per ore eccedenti ha un
problema di esecuzione. Sono situazioni diverse.

Le peggiori sui vostri dati:

| Commessa | Linea | Ordini | Beni | Costo ore | Saldo |
|---|---|---|---|---|---|
| WTS_3018 | NV_FI | 0 | 3.162 | 419.705 | −422.867 |
| WTS_3118 | WTS-AM | 0 | 76.600 | 104.403 | −181.003 |
| WTS_3228 | WTS-PRES | 127.500 | 10.361 | **179.654** | −62.515 |
| WTS_3184 | WTS-CSS | 34.000 | 4.461 | 82.139 | −52.600 |

Le prime due sono linee interne o non classificate: un saldo negativo è atteso,
è costo di struttura. **WTS_3228 e WTS_3184 no**: sono commesse a ricavo che
perdono denaro, e in entrambi i casi il problema è il costo delle ore, non gli
acquisti.

WTS_3184 era già emersa come sforata nell'analisi dei modelli contrattuali: due
indicatori indipendenti la segnalano.

## Una colonna superata

`cm_operator_costs.directional_cost_hour`, che avevo previsto nella versione
precedente per un costo direzionale orario per persona, **non serve**: il costo
direzionale è un importo di commessa, non un costo orario.

Resta nello schema ma inutilizzata. Non l'ho rimossa per non fare una modifica
distruttiva su una struttura appena creata.
