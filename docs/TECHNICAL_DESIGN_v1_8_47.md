# Technical Design — PortalManager v1.8.47

## 1. Gerarchia della pagina

La pagina serve tre usi con frequenze molto diverse: consultare l'elenco (quasi
sempre), filtrarlo (spesso), inserire una commessa a mano (raramente, perché il
grosso arriva dalla sincronizzazione). Prima li trattava allo stesso modo,
impilandoli dall'alto in ordine inverso all'uso.

L'ordine è ora: barra strumenti, pannelli chiusi, elenco. Chi consulta vede
subito i dati; chi filtra o inserisce apre ciò che gli serve.

## 2. Perché `<details>` e non un modale

`<details>`/`<summary>` è nativo: apre e chiude senza JavaScript, è accessibile da
tastiera, e lo stato iniziale si decide lato server con un attributo.

Quest'ultimo punto è quello che conta di più:

```php
<details class="pm-panel" id="panelFilters" <?= $active ? 'open' : '' ?>>
```

Il pannello filtri **si apre da solo quando ci sono filtri attivi**. Senza questo,
chi arriva da un link filtrato vedrebbe un elenco corto senza capire perché — e
concluderebbe che mancano dei dati. Lo stesso vale per il pannello di
inserimento, riaperto tramite `$_SESSION['reopen_new']` quando la validazione
fallisce: un messaggio di errore senza il modulo davanti non è azionabile.

Il minimo JavaScript presente serve solo al pulsante della barra strumenti, che
deve poter agire su un elemento più in basso; se fosse disabilitato, il pannello
resterebbe comunque apribile dal proprio titolo.

## 3. Conteggio dei filtri attivi

```php
foreach ($f as $k => $v) {
    if ($k === 'sort') continue;
    if ($v === '' || $v === 0 || $v === null) continue;
    $active_filters++;
}
```

L'ordinamento è escluso perché ha sempre un valore e non restringe nulla:
contarlo mostrerebbe "1 attivo" su un elenco completo. Lo zero è trattato come
assenza: tutti i filtri numerici usano zero come "non impostato", e nessuno di
essi ha zero come valore significativo — le soglie sugli importi accettano
valori negativi, ma il confronto `=== 0` distingue il numero dalla stringa vuota
solo dopo il cast, che avviene a monte.

Lo stesso conteggio decide se aprire il pannello, così indicazione e
comportamento non possono divergere.

## 4. Copertura dei filtri

Il criterio è che ogni colonna visibile sia filtrabile: se un dato merita una
colonna, merita di poter essere cercato.

Tre filtri non corrispondono a una colonna ma a una domanda ricorrente:

- **in scadenza entro N giorni** — `end_date BETWEEN CURDATE() AND CURDATE()+N`;
- **margine negativo** — `margin_total < 0`, cioè le commesse in perdita;
- **fido superato** — uno dei due sforamenti maggiore di zero.

Sono i filtri che rispondono a "cosa devo guardare oggi", e nessuno dei tre era
esprimibile combinando quelli esistenti.

Le soglie sugli importi sono generate da una tabella, non scritte una per una:

```php
foreach ([
    'margin_min'   => ['p.margin_total >= ?',   'f'],
    'margin_max'   => ['p.margin_total <= ?',   'f'],
    …
] as $k => [$cond, $t]) { … }
```

Aggiungere una soglia è una riga, e il codice di applicazione resta uno solo.

## 5. Sicurezza dei filtri

Tutti i valori passano da placeholder di prepared statement. L'ordinamento, che
non può essere parametrizzato, è risolto tramite mappa a stringhe costanti con
fallback:

```php
$order = [ 'code' => 'p.project_code', … ][$filters['sort'] ?? 'code'] ?? 'p.project_code';
```

Un valore non previsto ricade sull'ordinamento predefinito invece di finire nella
query. La whitelist è duplicata lato pagina (`$SORTS`) per non presentare opzioni
che il model scarterebbe, ma la difesa che conta è quella del model.

Il form dei filtri emette `route_slug_field()` come primo elemento, secondo la
regola introdotta in v1.8.42: senza, il submit perderebbe lo slug del router.

## 6. Due registri di etichette

La tabella conserva i nomi dello standard di export. Non è pigrizia: chi affianca
il portale al file Excel deve ritrovare le stesse diciture, e rinominare
`stato_economico` in "Stato economico" romperebbe quella corrispondenza. Le
abbreviazioni necessarie per la larghezza — `compl. da verif.`, `anom. bloccanti`
— hanno un `title` che le espande.

I filtri seguono la regola opposta, perché lì non c'è nessun file da affiancare e
il nome deve spiegare cosa si sta selezionando. Da qui "Sigla commerciale" invece
di "abbr" e "Stato economico (intero contratto)" invece di "stato_economico" —
quest'ultimo esplicita anche la distinzione dalla variante "a oggi", che a colonna
si capisce dall'accostamento e a filtro no.

## 7. Indici

Otto indici a supporto dei nuovi criteri: `end_date`, `margin_total`,
`residual_total`, `actual_cost`, `billing_freq_months`, `first_billing_date`,
`import_batch_id`, `dgb_contract_id`. Su circa 1.900 commesse il costo in
scrittura è trascurabile rispetto al guadagno sui filtri di elenco, che sono
l'operazione dominante della pagina.

## 8. Export

Invariato rispetto alla v1.8.40 e verificato in questa release. Vale la pena
esplicitare la sequenza, perché l'ordine è ciò che lo rende corretto:

1. `listAll($f)` applica i filtri;
2. il blocco di export intercetta `?export=` **dopo** la query e **prima** di
   `header.php`;
3. i buffer vengono svuotati e `zlib.output_compression` disattivato;
4. `exit` impedisce che il footer venga appeso al binario.

I punti 2 e 4 sono quelli che tipicamente si sbagliano: intercettare l'export
prima della query darebbe un file non filtrato, ometterlo dopo l'header darebbe
un file preceduto da HTML.
