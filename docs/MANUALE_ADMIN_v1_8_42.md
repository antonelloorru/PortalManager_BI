# Manuale Amministratore — v1.8.42

## Il difetto corretto

Applicando un filtro in Timesheet si finiva su "pagina non trovata". La causa non
era nel filtro ma nel modo in cui il portale nasconde i nomi dei file.

Le pagine di menu non sono raggiunte con il loro nome reale: al posto di
`timesheet.php` l'indirizzo contiene uno slug opaco nel parametro `r`. Quando si
invia un form di ricerca, il browser ricostruisce l'indirizzo usando **solo** i
campi presenti nel form e scarta il resto. Se il form non porta con sé anche lo
slug, questo si perde e il router non sa più quale pagina servire: 404.

Il problema si vede solo con le pretty-URL disattivate, che è la configurazione di
questa installazione.

## Non era solo Timesheet

La verifica ha esaminato tutte le 90 pagine anonimizzate alla ricerca di form di
ricerca privi dello slug. Ne sono emerse sei:

- Timesheet
- Gantt commesse
- Anagrafica Professionisti
- Export dipendenti
- Scheda commessa, riquadro di ricerca nei rapporti di intervento
- Recruiting, elenco posizioni

Tutte corrette nella stessa release. Dopo la correzione la scansione non trova più
alcun caso.

## Come verificare

Aprire ciascuna delle sei pagine e applicare un filtro qualsiasi: la pagina deve
ricaricarsi con i risultati filtrati. In alternativa, dal sorgente HTML della
pagina, il form dei filtri deve contenere una riga simile a:

```html
<input type="hidden" name="r" value="99865a2241ed1205">
```

Il valore cambia da pagina a pagina e dipende dalla chiave `URL_SECRET` della vostra
installazione: conta che il campo ci sia, non quale valore abbia.

## Per il futuro

Ogni nuova pagina di menu che includa filtri in GET deve avere
`route_slug_field()` subito dopo l'apertura del form. È ora una voce esplicita
della checklist di rilascio.

## Nessun impatto su dati e permessi

La release tocca solo l'HTML dei form. Lo slug era già visibile nell'indirizzo
della pagina che ospita il filtro, quindi riproporlo in un campo nascosto non
espone nulla di nuovo. I controlli di accesso restano invariati: ogni pagina
verifica i permessi del ruolo indipendentemente da come viene raggiunta.
