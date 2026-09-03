# Technical Design — PortalManager v1.8.42

## 1. Il meccanismo di anonimizzazione degli URL

`app/Router.php` sostituisce il nome del file con uno slug deterministico:

```php
$hash = substr(hash_hmac('sha256', $page, $secret), 0, 16);
```

Lo slug viaggia in `r`. Con le pretty-URL attive l'URL assume forma di path
riscritto; con le pretty-URL disattive resta `r.php?r=<slug>`.

## 2. Perché un form GET rompe la rotta

Il punto è nel comportamento standard dei browser: al submit di un form GET la
query string viene **ricostruita** dai soli controlli del form. Non è un merge con
l'URL corrente, è una sostituzione.

```
prima del submit:  r.php?r=99865a2241ed1205
form GET con campi m, y, proj
dopo il submit:    r.php?m=8&y=2026&proj=12      <-- r sparito
```

`r.php` non trova la rotta e risponde 404. Il POST non ha lo stesso problema:
l'action conserva l'URL corrente, quindi `r` sopravvive nella query string.

Ne consegue che il difetto colpisce **solo** i form GET, e solo nelle pagine
anonimizzate. Le pagine RESTRICTED (console di sistema, manutenzione), che non
vengono mai anonimizzate, sono immuni per costruzione.

## 3. L'helper e la sua applicazione

`route_slug_field()` (in `app/UrlHelper.php`, introdotto in v1.8.18) restituisce:

```html
<input type="hidden" name="r" value="99865a2241ed1205">
```

Emette la stringa vuota se la pagina non è routabile, quindi è sicuro anche nelle
pagine non anonimizzate. In modalità pretty-URL il campo è ridondante ma innocuo,
perché `r.php` legge comunque `$_GET['r']`.

La correzione consiste nell'inserirlo come primo elemento di ogni form GET delle
pagine routabili.

## 4. Come sono state trovate tutte le occorrenze

Correggere il solo Timesheet avrebbe lasciato in piedi lo stesso difetto altrove.
La ricerca è stata automatizzata: estrazione di `Router::PAGES`, isolamento di ogni
blocco `<form method="get">…</form>` e verifica della presenza di
`route_slug_field()` o di un campo `r` esplicito nel corpo del form.

Esito prima della correzione: 6 form affetti su 90 pagine. Dopo: nessuno.

Il criterio accetta anche un `<input name="r">` scritto a mano, così le pagine che
già gestivano la rotta in modo autonomo non vengono segnalate come falsi positivi.

## 5. Verifica del giro completo

Non basta che il campo compaia: lo slug deve anche tornare a risolvere la pagina
di partenza. Per le sei pagine è stato verificato il ciclo
`pagina → slug → pagina`:

| Pagina | Slug | Risoluzione inversa |
|---|---|---|
| `timesheet` | `99865a2241ed1205` | `timesheet` |
| `project_gantt` | `d9a93eaaaad5f73c` | `project_gantt` |
| `professionals` | `fa4ace417d605c28` | `professionals` |
| `export_employees` | `7105e9baecbb3753` | `export_employees` |
| `project_dashboard` | `ceed8de4905638f2` | `project_dashboard` |
| `recruiting_posizioni` | `cbe0b8f29a0d378c` | `recruiting_posizioni` |

Gli slug dipendono da `URL_SECRET`: i valori sopra valgono per la configurazione di
prova e differiranno in produzione, ma la corrispondenza biunivoca è la proprietà
che conta ed è deterministica per costruzione.

## 6. Sicurezza

L'anonimizzazione degli URL è una misura di riduzione della superficie informativa,
non un controllo di accesso: ogni pagina continua a verificare i permessi con
`can()` a prescindere da come è stata raggiunta. Aggiungere il campo `r` non
introduce quindi alcuna esposizione: lo slug è già presente nell'URL della pagina
che ospita il form, e il valore è HTML-escaped in uscita.

## 7. Prevenzione

Ogni nuova pagina di menu con filtri in GET deve includere `route_slug_field()`
subito dopo il tag di apertura del form. Il controllo è meccanico e conviene
inserirlo nella checklist di rilascio, come fatto in questa release.
