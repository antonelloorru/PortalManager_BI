# Technical Design — PortalManager v1.9.8

## 1. Estrarre invece di copiare

Gli stili del pannello erano in un `<style>` dentro `manage_projects.php`.

Copiarli nelle altre quattro viste sarebbe stato immediato e avrebbe creato
quattro copie divergenti: la prima modifica alla griglia le avrebbe allineate solo
in parte, e nessuno saprebbe quale sia la versione buona.

`assets/pm-filters.css` incluso da `header.php`: una definizione, cinque
consumatori. `manage_projects.php` non è stato modificato — continua a funzionare
con i propri stili inline, che ora sono ridondanti ma innocui.

Rimuoverli avrebbe significato toccare la vista di riferimento in una release
dedicata all'allineamento delle altre.

## 2. `<details>` invece di JavaScript

Il pannello si apre e chiude senza script. Lo stato non va sincronizzato con
nulla, non serve una libreria, e funziona anche se lo script fallisce.

`open` è deciso lato server in base ai filtri attivi: è una decisione sui dati,
non sull'interazione.

## 3. Il contatore dei filtri attivi

```php
$attivi = ($ag !== '') + ($f['q'] !== '') + … ;
```

Un pannello chiuso che nasconde filtri attivi fa credere di guardare tutti i dati.

Il contatore risolve il caso in cui l'utente apre un collegamento con filtri
nell'URL — da un segnalibro, da una email — e non ha impostato nulla di persona.

## 4. Griglia adattiva invece di colonne fisse

`manage_projects` ha quattro colonne perché ha sedici filtri: si dispongono in
quattro righe pulite.

Il Service Desk ne ha sei, la Relazione IT dieci. Quattro colonne fisse avrebbero
lasciato buchi nella prima e righe monche nella seconda.

`repeat(auto-fit, minmax(165px, 1fr))` lascia decidere al browser. I 165px sono il
punto sotto il quale un `<select>` con nomi di persone diventa illeggibile.

## 5. I menu multipli hanno bisogno di altezza

```css
.pm-grid select[multiple] { height:auto; min-height:62px }
```

Un `<select multiple>` senza altezza esplicita mostra una riga sola: sembra un
menu normale, e chi fa clic su una seconda voce perde la prima senza capire
perché.

L'etichetta `(multipla)` accanto al nome del campo dice cosa aspettarsi prima di
provare.

## 6. Il controllo pannello ↔ modello

```php
preg_match_all('/name="([a-z_]+)(\[\])?"/', $pannello, $m);
// … ogni campo deve comparire fra le chiavi di normFilters()
```

È il controllo che vale più degli altri in questa release.

Un `<input name="cliente">` in un pannello il cui modello non conosce la chiave
`cliente` produce un filtro che si compila, si invia, ricarica la pagina e **non
cambia nulla**. Nessun errore PHP, nessuna eccezione, nessun avviso: solo un
utente convinto di aver ristretto i dati.

È lo stesso genere di difetto del filtro `IN` sulle viste annidate (v1.8.88) e
della lettura di `$res['tot_costo_tab']` alla radice (v1.9.7): codice valido che
restituisce silenziosamente il risultato sbagliato.

Esito: 19 campi, nessuno non riconosciuto.

## 7. La ricerca libera su tre colonne

```sql
(commessa LIKE ? OR denominazione LIKE ? OR cliente LIKE ?)
```

Sono i tre modi in cui una commessa viene nominata a voce: il codice, il nome del
progetto, il nome del cliente.

Cercare solo sul codice avrebbe richiesto di conoscerlo; cercare su tutte le
colonne avrebbe restituito corrispondenze su campi che nessuno cerca — una nota
interna, un identificativo tecnico.
