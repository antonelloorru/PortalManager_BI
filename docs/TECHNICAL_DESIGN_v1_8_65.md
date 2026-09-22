# Technical Design — PortalManager v1.8.65

## 1. Il valore di un pulsante non è un dato affidabile

HTML trasmette il `name`/`value` di un `<button type="submit">` solo quando quel
pulsante è il *submitter* dell'invio. È una condizione che dipende da come il
form parte, non da come è scritto.

Casi in cui non si verifica:

- **Invio da tastiera** in un campo di testo: il browser usa il primo pulsante
  submit del form;
- **`form.submit()` da JavaScript**: nessun pulsante è il submitter, e nessun
  `name`/`value` di pulsante viene trasmesso;
- **click su un elemento figlio** del pulsante — l'icona `<i>` — con gestori che
  intercettano e reinviano l'evento.

In tutti questi casi il server riceve il primo pulsante, o niente. Con due
pulsanti che scelgono fra *simula* ed *esegui*, la differenza fra i due esiti è
sostanziale.

## 2. Un campo nascosto viene sempre trasmesso

```html
<form method="post">
  <input type="hidden" name="action" value="sync_all">
  <button class="btn btn-primary">Sincronizza tutto</button>
</form>
```

Nessuna dipendenza dal submitter: il campo fa parte dei dati del form e viene
inviato comunque.

Il costo è un form per azione. Accettabile qui, dove le azioni sono due e non
condividono campi.

## 3. Dove i form non si possono separare

Il riquadro CSV ha un `<input type="file">` condiviso fra *Anteprima* e
*Importa*: separare i form significherebbe duplicare il campo file, e l'utente
dovrebbe scegliere il file due volte.

Lì il campo nascosto è uno solo e viene impostato dal pulsante premuto:

```html
<input type="hidden" name="action" value="preview_csv" id="csvAction_…">
<button onclick="document.getElementById('csvAction_…').value='sync_csv'">Importa</button>
```

Il valore predefinito è quello **non distruttivo**: se il JavaScript non
funzionasse, l'esito sarebbe un'anteprima, non una scrittura non voluta. È lo
stesso principio per cui un interruttore in posizione neutra deve fermare, non
avviare.

## 4. Lettura esplicita lato server

```php
$dry = ($action === 'preview_all');
```

Non `$dry = ($action !== 'sync_all')`, che sembrerebbe equivalente.

La differenza emerge con un valore imprevisto: la prima forma lo tratta come
scrittura, la seconda come anteprima. Sembrerebbe più prudente la seconda, e
invece è peggio — fallirebbe **in silenzio** proprio nel caso in cui qualcosa è
andato storto nella trasmissione, che è la situazione appena osservata.

Meglio che un'azione non riconosciuta si comporti come quella richiesta
esplicitamente, o non faccia nulla, piuttosto che simulare senza dirlo.

## 5. L'esito deve dire che cosa è successo

Il messaggio dell'anteprima era corretto e non è stato letto. Era un blocco di
testo sotto un titolo neutro — *«Esito dell'anteprima»* — in una pagina dove ci
si aspettava una sincronizzazione.

Ora il titolo porta un'etichetta colorata, **ANTEPRIMA** arancione o
**SCRITTURA** verde, che occupa la posizione dove l'occhio arriva per prima.

La modalità è anche nell'event log. Serve a posteriori: se qualcuno segnala che i
dati non si sono aggiornati, il log dice se la sincronizzazione era stata
eseguita in scrittura o solo simulata.

## 6. Il pattern esisteva già

I pulsanti per singolo dataset — nella stessa pagina, poche righe più sotto —
usavano già form separati con campo nascosto:

```html
<form method="post"><input type="hidden" name="action" value="sync_db">…
```

L'incoerenza è nata scrivendo il riquadro della sincronizzazione completa
(v1.8.57) senza riusare il pattern già presente. È il genere di divergenza che
non produce errori finché una condizione al contorno non cambia.
