# Technical Design — PortalManager v1.8.63

## 1. Perché due meccanismi paralleli sono un problema

`CommesseSync` e `SyncDatasets` facevano la stessa cosa in modi diversi da
diciotto release. Non era un costo teorico: la v1.8.62 ha dovuto correggere una
verifica che apparteneva al vecchio flusso e segnalava un problema inesistente.

Il meccanismo del difetto è sempre lo stesso: si evolve uno dei due, l'altro
resta indietro, e le loro assunzioni divergono. Nessuno se ne accorge finché
qualcosa non si rompe in modo confuso.

La rimozione conserva la pagina **Connessione al gestionale** con la sola
configurazione e verifica. Non è un compromesso: i parametri di connessione
servono comunque, e sono l'unica parte di quel flusso che aveva ragione di
esistere separatamente.

## 2. Le divisioni sono la dimensione mancante

Il portale ha `cm_tech_units` dalla v1.8.48 — Presidio, SOC, Service Desk — una
tassonomia interna che va assegnata a mano e che, sui dati attuali, è **vuota per
tutti i 103 profili**. L'analisi per unità organizzativa restituiva una sola riga
*(non classificato)*.

`forms_division` contiene la struttura reale già popolata: otto divisioni fra
funzioni tecniche e società del gruppo. È una dimensione **conforme** — arriva dal
gestionale, si aggiorna con la sincronizzazione, non richiede lavoro manuale.

Le due coesistono e rispondono a domande diverse: le divisioni dicono *a quale
struttura appartiene il lavoro*, le unità tecniche *che tipo di competenza è*.
Sovrapporle sarebbe stato un errore.

## 3. Importare le allerte invece di ricalcolarle

Il gestionale ha otto regole di allerta economica con soglie e gravità: margine,
residuo, tariffa, ritardo di fatturazione. Il portale poteva ricalcolarle.

Non lo fa. Le importa come sono, per tre ragioni:

- le soglie sono decisioni aziendali, e duplicarle nel portale creerebbe una
  seconda verità che diverge alla prima modifica;
- il gestionale conosce dati che il portale non ha, per esempio le tariffe
  standard per tipo di commessa su cui si basa `RNS`;
- una segnalazione già vista dagli operatori sul gestionale, se ricalcolata
  diversamente dal portale, genera confusione invece che informazione.

`resolved_at` distingue le anomalie aperte da quelle chiuse: senza, un conteggio
storico verrebbe scambiato per la situazione corrente. Delle 279 importate, 203
sono ancora aperte.

## 4. Il confronto fra i due sistemi

`v_cm_commesse_allerta` incrocia le anomalie del gestionale con le allerte
calcolate dal portale (v1.8.58, consumo del valore di commessa).

Le due misurano cose diverse — soglie contrattuali contro consumo del budget —
ed è proprio questo a rendere il confronto utile:

| Convergenza | Commesse | Lettura |
|---|---|---|
| confermata da entrambi | 17 | massima confidenza, priorità di intervento |
| solo gestionale | 137 | soglie contrattuali violate senza sforamento di budget |
| solo portale | 12 | budget consumato senza violare soglie contrattuali |

Un indicatore che concorda con un altro costruito su basi diverse è molto più
affidabile di uno solo. E le divergenze non sono rumore: dicono che i due sistemi
guardano aspetti complementari, e vale la pena capire quale sia rilevante per
quella commessa.

## 5. Il DISTINCT che mancava

Il dataset `professionisti` leggeva `dgb_operator` senza `DISTINCT` e produceva
512 righe per 256 operatori.

Il difetto era stato corretto in v1.8.60 sul dataset dei full cost, che legge la
**stessa tabella** — ma non su questo. È il tipo di correzione che si applica dove
il problema si è manifestato invece che dove sta la causa.

Il vincolo UNIQUE sulla chiave lo mascherava: il secondo inserimento aggiornava
la riga già scritta anziché fallire. Nessun duplicato nel risultato, ma il doppio
del lavoro e — più insidioso — un conteggio di righe lette che non corrispondeva
alla realtà.

Da qui una regola: **quando due dataset leggono la stessa tabella sorgente, un
difetto della sorgente li riguarda entrambi**. La correzione va applicata a tutti
i lettori, non a quello in cui è stata notata.

## 6. Undici dataset, un ordine

```
divisioni → commesse → costi fascia → full cost → professionisti → tariffe
→ allocazioni → operazioni → tipi anomalia → anomalie commessa → rapporti
```

`divisioni` per prime perché sono una dimensione pura, senza dipendenze.
`tipi_anomalia` prima di `anomalie_commessa` perché la seconda vi si riferisce.
`rapporti` per ultimi perché si agganciano a commesse e professionisti.

L'ordine non è vincolante per la correttezza — gli agganci per codice avvengono
comunque alla passata successiva — ma evita che una sincronizzazione appena
conclusa mostri righe scollegate.
