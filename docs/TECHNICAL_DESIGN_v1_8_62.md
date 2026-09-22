# Technical Design — PortalManager v1.8.62

## 1. Una verifica rimasta indietro di sedici release

`CommesseSync`, introdotto in v1.8.45, assumeva un modello semplice: una tabella
sorgente, una mappa di colonne, due colonne obbligatorie. La verifica di
connessione controllava esattamente quello.

La v1.8.46 ha sostituito quel modello con `SyncDatasets` — query complete con
join, un dataset per entità — ma la verifica è rimasta agganciata al vecchio.

Il risultato è il caso peggiore per una diagnostica: **un test che fallisce quando
il sistema funziona**. Chi lo vede o perde tempo a cercare un problema
inesistente, o impara a ignorare i messaggi di quella pagina — e a quel punto non
noterà nemmeno quelli veri.

## 2. Verificare eseguendo, non ispezionando

```php
$st = $src->query(rtrim($d['sql'], "; \n") . ' LIMIT 0');
```

La query di ogni dataset viene **eseguita** sulla sorgente con `LIMIT 0`: nessuna
riga trasferita, ma il motore risolve tabelle, join, alias e funzioni.

Un controllo sui nomi di colonna non intercetta un join verso una tabella
rinominata, un alias che non corrisponde alla mappatura, una funzione non
supportata dalla versione del server. `LIMIT 0` li intercetta tutti, al costo di
otto query vuote.

Il confronto fra colonne prodotte e mappatura dichiarata completa la verifica: una
query può essere valida e produrre nomi diversi da quelli attesi, e in quel caso
la sincronizzazione girerebbe scrivendo nulla.

## 3. Due livelli di diagnosi

La verifica distingue tre esiti, in ordine di gravità:

| Esito | Significato |
|---|---|
| `tabelle mancanti` | una tabella richiesta non esiste nello schema |
| `query non eseguibile` | la tabella c'è ma la query fallisce |
| `colonne non prodotte` | la query gira ma i nomi non corrispondono |

Il primo si ricava dall'inventario senza eseguire nulla, ed è utile perché dà il
nome esatto della tabella assente. Gli altri due richiedono l'esecuzione.

Provato con `forms_um_rate_for_contract` rimossa dall'inventario: 7 dataset su 8
restano `ok` e il difetto è attribuito al solo dataset che usa quella tabella. La
localizzazione dell'errore è la proprietà che rende una diagnostica utilizzabile.

## 4. Un fallimento parziale non è un fallimento

Il riquadro dichiara esplicitamente che i dataset validi funzionano comunque.

È coerente con il comportamento della sincronizzazione completa (v1.8.57), che
prosegue sui dataset validi e riporta l'errore per quelli in difetto. Presentare
un fallimento parziale come un blocco totale porterebbe a non lanciare la
sincronizzazione, perdendo anche gli aggiornamenti che sarebbero riusciti.

## 5. `source_table` non serve più

Il parametro indicava l'unica tabella da leggere. Con quattordici tabelle non ha
più un significato utile, e l'UPDATE per allinearlo — previsto nella prima
stesura di questa migration — è stato rimosso.

Non era solo superfluo: la tabella `cm_source_db` è introdotta dalla v1.8.45 e su
un'installazione che non l'avesse ancora l'UPDATE avrebbe interrotto la
migration. Il tentativo di renderlo condizionale con `PREPARE`/`EXECUTE` ha
fallito il collaudo — il tokenizer non li gestisce come statement singoli — e la
soluzione corretta si è rivelata la più semplice: non fare la modifica affatto.

Il campo resta nello schema, inerte per la verifica, usato solo dalla parte
residua del vecchio flusso di import.
