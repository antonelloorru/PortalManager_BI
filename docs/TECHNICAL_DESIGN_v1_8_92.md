# Technical Design — PortalManager v1.8.92

## 1. Due dimensioni per la stessa cosa

`linea_servizio` e `linea_label` descrivono la stessa entità. Esporle entrambe
sembra ridondante e non lo è: rispondono a domande diverse.

Chi confronta il portale con un tabulato del gestionale ha in mano dei codici e
cerca `WTS-ACM`. Chi legge un report senza conoscere la codifica ha bisogno di
«Chiavi in mano».

Costringere il primo a tradurre mentalmente, o il secondo a imparare venti codici,
è un costo che si evita con una colonna in più.

Sono anche combinabili: `Codice linea × Linea di servizio` produce una riga con
entrambi, che è la forma giusta per un allegato destinato a due lettori diversi.

## 2. Il modello contrattuale non sostituisce la linea

Nel Service Desk il dettaglio era per modello — presidio, canone, a scalare.

Il modello è un raggruppamento **più grosso**: `WTS-MON` (monitoraggio) e
`WTS-SD` (service desk) sono entrambe a canone ma sono servizi diversi, con
clienti e attività differenti.

Raggruppare per modello rispondeva a «quanto lavoro sta su contratti a canone»;
il codice risponde a «su quale servizio». La prima domanda è economica, la seconda
operativa, ed è quella che un responsabile di servizio si pone.

## 3. Il riquadro segue il filtro tecnico

```php
$st = $this->pdo->prepare("… WHERE r.`report_date` BETWEEN ? AND ?"
    . ($f['tec'] !== '' ? " AND b.`nome_ticket` = ?" : "") . " …");
```

La condizione è concatenata solo se il filtro è attivo, e il parametro aggiunto di
conseguenza.

È il principio della v1.8.88: con la scheda di un componente aperta, ogni riquadro
della pagina deve riferirsi a lui. Un riquadro che mostrasse il totale del Service
Desk accanto alla scheda personale sarebbe letto come suo.

## 4. `project_type` esiste e non si usa

La colonna c'è ma è vuota su 1.062 commesse su 1.062.

Esporla come dimensione avrebbe prodotto un filtro con un solo valore — «(vuoto)»
— e una riga sola in ogni raggruppamento: la forma di un'analisi senza il
contenuto.

È il caso opposto a quello degli SLA (v1.8.83): lì la struttura è stata predisposta
vuota perché il dato arriverà; qui non c'è indicazione che qualcuno lo popoli, e
predisporre un filtro inutile aggiunge rumore all'interfaccia.

## 5. Le quadrature come rete

| Livello | Verifica |
|---|---|
| Service Desk | totale per codice = 11.908,5 h, il valore della v1.8.87 |
| per componente | somma dei suoi codici = sue ore totali |
| Relazione IT | somma per codice = totale del periodo |
| due dimensioni | codice × etichetta = totale |

La quarta è quella che intercetta un `GROUP BY` mal costruito: aggiungendo una
dimensione che dipende funzionalmente dalla prima, il numero di righe non deve
cambiare — 19 righe con il solo codice, 19 con codice ed etichetta insieme.

Se fossero diventate di più, significherebbe che a uno stesso codice corrispondono
etichette diverse, cioè un difetto nei dati di `cm_contract_models`.
