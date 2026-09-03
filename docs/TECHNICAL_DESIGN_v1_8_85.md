# Technical Design — PortalManager v1.8.85

## 1. Un errore che il controllo di sintassi non vede

```php
Router::hiddenParams('service_desk')
```

Sintatticamente ineccepibile. PHP non verifica a tempo di analisi che `Router`
definisca `hiddenParams`: potrebbe arrivare da `__callStatic`, da un trait, da un
autoload. La verifica avviene all'invocazione, e lì è fatale.

`php -l` su questo file dava esito positivo. La pagina non si apriva.

## 2. Perché il collaudo del rendering non è bastato

Il collaudo della v1.8.84 eseguiva `SdModel`, i calcoli del grafico e le
quadrature, con gli avvisi convertiti in eccezioni. Ha dato zero errori.

Verificava però **il modello**, non il template: il form dei filtri sta nella
parte HTML della pagina, che quel collaudo non attraversava. Un controllo che
copre il 90% del codice e non il 10% che contiene l'errore dà una falsa sicurezza
peggiore di nessun controllo.

## 3. Il controllo aggiunto

```php
preg_replace('~//.*$~m', '', $s);      // via i commenti di riga
preg_replace('~/\*.*?\*/~s', '', $s);  // via i blocchi
preg_match_all('/([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', …)
```

Per ogni `Classe::metodo()` trovato, cerca la definizione in `app/Classe.php`.

Lo spoglio dei commenti è necessario e non ovvio: il commento che spiega la
correzione **cita il metodo inesistente**, e senza rimuoverlo il controllo
avrebbe segnalato un difetto già risolto — esattamente come era accaduto con
`$NAT` nella v1.8.72.

Le classi non presenti nel pacchetto vengono saltate: sono invariate sul server e
il pacchetto non le contiene.

## 4. Eseguito su tutte le pagine

Non solo su quella corretta. Se una chiamata inventata è stata possibile una
volta, il posto giusto dove cercarne altre è tutto il resto della release.

Esito: **zero metodi statici inesistenti** su tutti i file.

## 5. Perché è successo

Scrivendo la pagina serviva conservare lo slug nel form GET. Invece di guardare
come lo fanno le pagine esistenti, ho scritto la chiamata che *sembrava* giusta:
`Router` gestisce le rotte, quindi un metodo per i parametri nascosti sarebbe
plausibile.

Le altre pagine usano `route_slug_field()`, una funzione globale in
`UrlHelper.php`. Bastava aprire `workload_overview.php` — cosa che ho fatto per
copiare l'impalcatura, ma non per questo dettaglio.

La regola operativa: quando serve un'utilità che le pagine esistenti già usano,
il primo posto da guardare è una pagina esistente.
