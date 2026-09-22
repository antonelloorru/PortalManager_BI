# Manuale Amministratore — v1.8.92

## Il codice della linea di servizio

`WTS-ACM` e «Chiavi in mano» sono la stessa linea detta in due modi:

- il **codice** è quello che trovate sui documenti e nel gestionale
- l'**etichetta** è leggibile senza conoscere i codici a memoria

Entrambe le sezioni mostravano solo l'etichetta. Ora il codice è una dimensione a
sé, e si possono usare **insieme**: raggruppando per *Codice linea × Linea di
servizio* ottenete una riga con entrambi.

## Nella Relazione di Servizio IT

Nuovo filtro **Codice linea** e nuova voce in *Raggruppa per*, con grafico
dedicato e foglio nell'export.

| Codice | Interventi | Ore |
|---|---|---|
| WTS-PRES | 3.888 | 29.123,5 |
| NV_AI | 3.409 | 10.861,5 |
| WTS-CSS | 2.760 | 10.790,5 |
| WTS-ACM | 1.442 | 6.673,0 |

**19 codici distinti.** La somma per codice dà 72.034,0 h, esattamente il totale.

## Nel Service Desk

Nuovo riquadro **Moduli di intervento per codice linea**, con barra che distingue
le linee a ricavo da quelle interne:

| Codice | Linea | Ore | Natura |
|---|---|---|---|
| NV_AI | Attività interna - AI | 5.832,5 | **interna** |
| WTS-HD | Help Desk interno | 2.346,0 | **interna** |
| WTS-CSS | Contratto a scalare | 966,5 | a ricavo |
| WTS-SD | Service Desk a canone | 460,0 | a ricavo |

Il riquadro **segue il filtro tecnico**: aprendo la scheda di un componente mostra
solo i suoi codici.

## Perché non bastava il modello contrattuale

Prima il dettaglio era per **modello** — presidio, canone, a scalare — che è un
raggruppamento più grosso: `WTS-MON` e `WTS-SD` sono entrambe a canone ma sono
servizi diversi, con clienti e attività differenti.

Il modello risponde a «quanto lavoro sta su contratti a canone», il codice a «su
quale servizio». La seconda è la domanda operativa.

## Sul campo «Tipo»

La colonna `project_type` esiste in `cm_projects` ma è **vuota su tutte e 1.062 le
commesse**: nessuna sincronizzazione la popola.

**Non l'ho esposta** come filtro: avrebbe un solo valore possibile, «(vuoto)», e
una riga sola in ogni raggruppamento — la forma di un'analisi senza il contenuto.

Se nel gestionale esiste un campo corrispondente, va prima aggiunto ai dataset di
sincronizzazione: mi indichi la tabella e la colonna e lo collego.
