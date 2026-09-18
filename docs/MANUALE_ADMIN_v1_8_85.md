# Manuale Amministratore — v1.8.85

## L'errore corretto

```
Fatal error: Call to undefined method Router::hiddenParams()
in service_desk.php on line 122
```

La pagina invocava un metodo che **non esiste**. Serviva a conservare
l'identificativo della pagina quando il form dei filtri viene inviato.

La funzione corretta è `route_slug_field()`, la stessa usata da *Carico &
Sovrapposizioni* e dalle altre pagine con filtri.

**È stato un errore mio**: ho scritto una chiamata plausibile invece di verificare
come lo fanno le pagine esistenti.

## Perché non l'avevo intercettato

Il controllo automatico di sintassi (`php -l`) **non può rilevarlo**: chiamare un
metodo inesistente è sintatticamente valido, e PHP se ne accorge solo eseguendo.

Il collaudo che avevo fatto verificava i calcoli e le quadrature — e ha dato zero
errori — ma non arrivava fino al form dei filtri. Un controllo che copre quasi
tutto e non la parte con l'errore dà una sicurezza falsa.

## Il controllo che ho aggiunto

Ora verifico che ogni metodo invocato dalle pagine esista davvero nella classe che
dovrebbe definirlo. Eseguito su **tutti** i file della release, non solo su quello
corretto:

```
metodi statici inesistenti: 0
```

## Cosa fare

Sovrascrivere `service_desk.php` in ROOT e `app/Version.php`. La migration è un
semplice allineamento di versione.

Poi verificare che **Gestione Commesse → Service Desk** si apra, e che premendo
**Filtra** la pagina si ricarichi su sé stessa invece di tornare all'elenco
commesse: era proprio quello il compito della chiamata sbagliata.
