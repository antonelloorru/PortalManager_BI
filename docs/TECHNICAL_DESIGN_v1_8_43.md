# Technical Design — PortalManager v1.8.43

## 1. Export: ambito filtrato contro ambito completo

ListFilter filtra lato client: le righe che non superano i criteri ricevono la
classe `lf-hidden`, restando nel DOM. `collectVisibleData()` le scartava sempre,
quindi l'export coincideva per definizione con la vista corrente.

La funzione accetta ora un parametro di ambito:

```js
function collectVisibleData(includeAll) {
    ...
    if (!includeAll && row.classList.contains('lf-hidden')) return;
    if (row.classList.contains('lf-noskip')) return;
```

Il secondo predicato resta incondizionato: `lf-noskip` marca le righe di servizio
(totali, messaggi di elenco vuoto), che non sono dati e non vanno mai esportate,
qualunque sia l'ambito.

Poiché il filtro è client-side e le righe sono già tutte nel DOM, l'ambito
completo non richiede alcuna chiamata al server: cambia solo quali righe vengono
raccolte. La conseguenza è che l'export completo è disponibile in tutte le pagine
che usano il componente, senza modifiche alle singole pagine.

## 2. Perché un unico schema per template e import

Il rischio, in un meccanismo template + import, è che le due parti si allontanino
nel tempo: si aggiunge una colonna al template e si dimentica il parser, oppure si
estende il parser e il template resta indietro. Verificare a posteriori
l'allineamento è possibile, ma è una garanzia debole, valida fino alla modifica
successiva.

`app/EmployeeImportSchema.php` elimina il problema alla radice: la definizione è
una sola e tutti la consumano.

```
                    EmployeeImportSchema::COLUMNS
                                 │
        ┌────────────────────────┼────────────────────────┐
        ▼                        ▼                        ▼
   labels() + exampleRow()   headerMap()            columns()
   template XLSX/CSV         parser di import       elenco colonne
                                                    mostrato in pagina
```

Aggiungere una colonna significa aggiungere una voce all'array: template, parser e
interfaccia si aggiornano insieme.

Ogni voce dichiara etichetta, campo logico, tipo, sinonimi accettati, valore di
esempio, nota per il foglio Istruzioni e un indicatore `sensitive` per le colonne
retributive.

## 3. Normalizzazione delle intestazioni

Il riconoscimento è tollerante: maiuscole, accenti, spazi e punteggiatura sono
appiattiti su una chiave canonica. La regola richiede però una cautela emersa in
collaudo:

```php
$h = str_replace('%', ' perc ', $h);
$h = preg_replace('/[^a-z0-9]+/u', '_', $h);
```

Scartando il simbolo `%`, `"% Part-time"` e `"Part-time"` producevano la stessa
chiave `part_time`. La mappa è costruita per assegnazione progressiva, quindi la
seconda voce sovrascriveva la prima: la percentuale sarebbe stata interpretata
come booleano. Tradurre `%` in `perc` mantiene le due chiavi distinte.

`collisions()` verifica che nessuna intestazione o sinonimo punti a due campi
diversi: è una guardia permanente contro il ripetersi del problema quando il
tracciato verrà esteso.

La stessa funzione di normalizzazione è usata dal parser: applicare due regole
diverse ai due lati del confronto vanificherebbe la mappa.

## 4. Tipi e conversioni

Le normalizzazioni sono guidate dal tipo dichiarato nello schema, non da un
elenco di campi scritto a mano:

```php
foreach (EmployeeImportSchema::fieldsOfType('date') as $f) {
    $rec[$f] = parse_date_flex($rec[$f] ?? null);
}
```

Una nuova colonna di tipo `date` è convertita correttamente senza toccare il
codice di import. I tipi previsti: `text`, `date`, `int`, `bool`, `decimal`,
`enum`, `lookup`.

Gli `enum` hanno mapper dedicati e tolleranti: `map_contract_type()` riconosce
"Tempo indeterminato" come "Indeterminato", `map_classificazione()` accetta i
prefissi "dir"/"ind", `map_status()` accetta le forme italiane (attivo, cessato,
sospeso). `map_status()` restituisce `null` quando la colonna è assente o vuota,
lasciando che lo stato sia dedotto dalla data di cessazione come nel tracciato
precedente: la retro-compatibilità dei file esistenti è preservata.

## 5. Lookup organizzativi

Modalità di lavoro, dipartimento e sotto-categoria sono risolti per nome contro
le rispettive tabelle. La sotto-categoria è cercata solo entro il dipartimento
già risolto, coerentemente con il vincolo di unicità `(department_id, name)`.

La risoluzione è deliberatamente **non bloccante**: un valore non trovato lascia
il campo vuoto e la riga viene comunque importata. Un import di anagrafica che
fallisse perché una modalità di lavoro non è ancora configurata sarebbe più
dannoso che utile; azienda e sede mantengono invece il comportamento preesistente,
con auto-creazione opzionale.

Le query di lookup sono protette da `try/catch`: su installazioni dove
`work_modes` non è ancora stata creata, il campo resta semplicemente nullo.

## 6. Aggiornamento non distruttivo

In aggiornamento la clausola `SET` include solo i campi valorizzati:

```php
foreach ($fields as $k => $v) {
    if ($v !== null && $v !== '') { $set[] = "`$k` = ?"; $par[] = $v; }
}
```

Una cella vuota nel file non azzera il dato già registrato. È la proprietà che
rende utilizzabile un template a 32 colonne per aggiornamenti parziali: si
compilano le sole colonne da modificare, lasciando le altre vuote. Verificato in
collaudo: importando una riga con solo mansione e livello, la RAL preesistente
resta invariata.

Il riconoscimento avviene per codice fiscale e, in mancanza, per matricola.

## 7. Colonne retributive e permessi

RAL e premio concordato sono marcate `sensitive` e incluse solo se l'utente ha il
permesso Compensation, lo stesso che governa la colonna RAL nell'elenco. Il
template segue quindi la visibilità già in vigore nell'anagrafica: 32 colonne per
chi ha il permesso, 30 per gli altri, con ordine identico sul prefisso comune.

## 8. Integrità dell'output binario

L'endpoint del template svuota i buffer e disattiva `zlib.output_compression`
prima di emettere il file, secondo la regola consolidata dalla v1.8.39: qualsiasi
byte spurio prima del binario produce un XLSX che Excel segnala come danneggiato.
