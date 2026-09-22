# Manuale Amministratore — v1.9.45

## Relazione di Servizio IT — export e stampa selettivi
Nel pannello Filtri, il gruppo **"Dettagli da includere (stampa / export)"** permette
di spuntare quali sezioni esportare/stampare. La scelta è propagata ai pulsanti
**Stampa**, **XLSX** e **Word**.

## Word (.docx)
Il pulsante **Word** produce un documento .docx nativo con le sezioni selezionate
(quadro, andamento, giorni per operatore, costi, riepilogo per contratto, dettaglio
per commessa). Non richiede componenti aggiuntivi oltre all'estensione PHP zip.

## Grafico Andamento mensile
- La **linea target** (verde tratteggiata) rappresenta le ore ordinarie lavorative di
  riferimento: per default la media mensile del periodo; si può fissare un valore
  aggiungendo `?target=<ore>` all'URL.
- Le **ore di reperibilità** sono evidenziate in viola.
