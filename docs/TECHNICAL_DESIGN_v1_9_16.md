# Technical Design — PortalManager v1.9.16

## 1. Il dataset che stavo per duplicare

Avevo scritto un dataset `tariffe` con target `cm_um_rate`, e una tabella nuova
per accoglierlo.

`SyncDatasets::keys()` ha mostrato che la chiave esisteva già, con target
`cm_contract_rates` e 35.335 righe sincronizzate. Il mio dataset l'aveva
sovrascritta in memoria senza errore: `all()` restituisce un array, e una chiave
ripetuta vince sulla precedente.

Se avessi consegnato, il portale avrebbe smesso di sincronizzare la tabella vera e
avrebbe popolato quella nuova. Due listini, uno aggiornato e uno fermo.

L'ho trovato perché ho verificato le chiavi dopo l'inserimento. Non era un
controllo pianificato: è stata fortuna, e il controllo andrebbe reso sistematico.

## 2. Quarta volta lo stesso schema

- v1.9.1: formula dedotta dal documento invece che dal gestionale
- v1.9.2: `margin_total` ricostruito senza verificare se esistesse
- v1.9.12: elenco di stati costruito per plausibilità
- v1.9.16: seconda tabella tariffe accanto a una già sincronizzata

Ogni volta: **cercare dove il problema si presenta invece di dove il dato sta**.

Il correttivo non è un controllo automatico. È una domanda da porsi prima di
scrivere: *questo dato esiste già da qualche parte?*

## 3. La deduzione era la moda, non la regola

Fascia C / H: 704 contratti su 1.142 hanno 100,00, la media è 99,53, il massimo
850,00.

La deduzione dal template ha colto il valore modale — quello del contratto che
l'esempio rappresentava — e l'ha presentato come la tariffa. Su 704 contratti
sarebbe stata giusta, su 438 sbagliata.

`tariffa_origine` distingue ora `contratto` da `dedotta da template`, così un
valore letto e uno supposto non si confondono.

## 4. La fascia si legge, non si deduce

`dgb_forms_activity.id_activitytype` porta la fascia. La v1.9.15 la deduceva
dall'ora di inizio, perché non sapevo che il campo esistesse.

Le due strade danno risultati diversi: un'attività classificata fascia B dal
gestionale veniva calcolata come C o D secondo l'orario.

`COALESCE(fa.fascia, <deduzione>)` legge quando può e deduce quando deve.
`fascia_origine` lo dichiara, perché una fascia letta e una supposta hanno
affidabilità diversa e il totale non dice quale sia quale.

## 5. `CEH` tenuta fuori dal calcolo

`rate_nature` vale `C` per `CEH`, `R` per le altre quattro. Il join filtra su `R`.

Includerla avrebbe sommato costi e ricavi in un numero che non è né l'uno né
l'altro — e il totale sarebbe cresciuto in modo plausibile, senza segnali.

La struttura accoglie già la valorizzazione a costo: basta un secondo join con
`rate_nature = 'C'`. Non l'ho aggiunta perché non è stata chiesta, e una colonna
in più che nessuno guarda è un costo di manutenzione senza contropartita.

## 6. Il raccordo per `project_code`

`cm_contract_rates` ha `project_code`, non l'id del contratto del gestionale.

La sincronizzazione esistente ha già risolto quel raccordo, e rifarlo passando da
`forms_contract.id` avrebbe significato una seconda traduzione che diverge dalla
prima al primo caso limite.

Uso la colonna che c'è.
