# Technical Design — PortalManager v1.8.46

## 1. Il problema della doppia definizione

Un meccanismo che accetta sia una connessione diretta sia un CSV rischia di
diventare due implementazioni da mantenere allineate a mano: si aggiunge una
colonna alla query e si dimentica il parser, oppure il tracciato CSV documentato
smette di corrispondere a quello letto.

`app/SyncDatasets.php` risolve alla radice: ogni dataset dichiara una volta sola
query, intestazioni e mappatura verso il portale.

```
                    SyncDatasets::all()
                            │
        ┌───────────────────┼───────────────────┐
        ▼                   ▼                   ▼
  ['sql']              ['map'] chiavi      ['map'] valori
  query sul            intestazioni         campo + tipo
  gestionale           attese nel CSV       di destinazione
        │                   │                   │
        ▼                   ▼                   │
  readSource()          readCsv()               │
        └─────────► writeRows() ◄───────────────┘
```

Le due letture normalizzano verso la stessa struttura, quindi `writeRows()` non
sa da dove arrivino i dati. È questa convergenza a rendere il CSV una vera
alternativa: non c'è un percorso privilegiato e uno secondario.

## 2. Lo schema reale del gestionale

La v1.8.45 assumeva una tabella `contract` piatta, ipotesi ragionevole guardando
solo il CSV esportato ma sbagliata. Lo schema vero è normalizzato:

```
forms_company ──┐
                ├──► forms_contract ──► forms_place ──► forms_activity
forms_contract_type ┘        │                                │
dgb_operator (salesman) ─────┘                                ▼
                                        forms_activity_has_dgb_operator
                                                  │
                                        dgb_operator ──► forms_cost_range
```

Il costo dell'errore sarebbe stato alto: la sincronizzazione avrebbe fallito al
primo tentativo in produzione, o peggio avrebbe scritto colonne vuote. Da qui la
scelta di **validare le query contro lo schema del dump** prima del rilascio, con
`LIMIT 0`: si verificano sintassi, tabelle e colonne senza leggere dati.

Il controllo ha ripagato subito, intercettando `ao.planned_quantity` (inesistente:
le ore pianificate sono su `forms_activity`) e un campo di destinazione assente.

## 3. Granularità dei rapporti di intervento

`forms_activity` è l'attività; `forms_activity_has_dgb_operator` è il contributo
del singolo tecnico. L'export ufficiale ha granularità per **allocazione**, non
per attività: due tecnici sullo stesso intervento producono due righe.

Questo confligge con `cm_intervention_reports.report_code`, che è univoco. La
soluzione:

```sql
CASE WHEN nalloc.n > 1 THEN CONCAT(a.code, '/', ao.id_operator) ELSE a.code END
```

Il suffisso compare solo quando serve, così i codici degli interventi a tecnico
singolo restano identici a quelli del gestionale, riconoscibili da chi li cerca.
Ed è deterministico: la stessa allocazione produce sempre lo stesso codice, quindi
le sincronizzazioni successive aggiornano invece di duplicare.

La chiave di aggiornamento è però `dgb_source_id`, l'identificativo
dell'allocazione: univoco per costruzione e indipendente da come si compone il
codice leggibile.

## 4. Conversioni

I tipi sono dichiarati nella mappatura, quindi la conversione è guidata dai dati
e non da elenchi di campi scritti a mano.

**Date.** Accettate sia `GG/MM/AAAA` (formato dell'export) sia `AAAA-MM-GG`
(formato nativo). Serve perché la connessione diretta usa `DATE_FORMAT` per
restare identica al CSV, ma un file ritoccato in Excel può presentarsi in ISO.

**Decimali.** `1.234,56` e `1234.56` sono entrambi validi. La discriminante è la
presenza di una sola virgola insieme ad almeno un punto: in quel caso il punto è
separatore di migliaia e va rimosso.

**Stati.** `toStatus()` accetta sia la forma inglese sia quella già tradotta, così
un CSV esportato dal gestionale e uno rielaborato dal portale sono entrambi
leggibili.

## 5. Aggiornamento non distruttivo

```php
foreach ($rec as $f => $v) {
    if ($f === $keyF || $v === null || $v === '') continue;
    $set[] = "`$f`=?"; $par[] = $v;
}
```

Le celle vuote non azzerano dati esistenti. Un CSV parziale — poche colonne, per
correggere un campo su molte righe — è quindi utilizzabile senza rischio di
perdere il resto.

## 6. Filtri sulla sorgente

Le commesse eliminate sono escluse. I rapporti sono limitati agli stati
`APPROVED`, `CLOSED`, `COMPLETED`: bozze e attività in corso non appartengono al
consuntivo e includerle gonfierebbe ore e costi con dati non definitivi.

Il filtro è nella query, non a valle: le righe scartate non attraversano
nemmeno la rete.

## 7. Aggancio alla commessa

I rapporti portano il codice commessa come testo. `writeRows()` lo risolve in
`project_id` interrogando `cm_projects`, e conta gli agganci riusciti. Se la
commessa non esiste ancora, il rapporto viene comunque scritto con il solo codice
testuale: sincronizzando prima le commesse e poi i rapporti l'aggancio si completa,
e l'ordine dei riquadri nella pagina lo suggerisce.

## 8. Sicurezza

Ereditata da `SourceDb` (v1.8.45): password cifrata AES-256-GCM con chiave in
`.env.php`, sessione in sola lettura, sole istruzioni `SELECT`, identificatori
validati.

Le query dei dataset sono **costanti nel codice**, non costruite da input utente:
la superficie di iniezione è nulla. I permessi della nuova pagina sono derivati da
quelli di Import Commesse DB, così l'introduzione non allarga gli accessi già
concessi.
