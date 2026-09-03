# Manuale Utente — v1.8.44

## Che cosa è stato sistemato

Nell'elenco dei dipendenti, esportando con la casella "Tutti i record" spuntata,
si ottenevano solo i primi 25 nominativi. Ora il file contiene l'elenco completo.

Il motivo era la suddivisione in pagine: l'elenco mostra 25 righe per volta, e
l'export riusciva a leggere soltanto quelle della pagina aperta in quel momento.

## Come esportare l'elenco completo

Premere **Esporta**, spuntare **"Tutti i record (ignora i filtri)"** e scegliere
il formato. Il file scaricato contiene tutte le righe, indipendentemente dalla
pagina che state visualizzando e dai filtri impostati.

Se la casella resta vuota, scaricate le sole righe che rispettano i filtri
attivi — anche in questo caso su tutto l'elenco, non solo sulla pagina aperta.

## Come controllare che sia completo

In fondo alla tabella è indicato il numero totale di righe. Aprite il file
scaricato e verificate che le righe siano quel numero più una, l'intestazione.

## Vale anche per gli altri elenchi

La correzione riguarda tutti gli elenchi del portale con filtri ed export:
utenti, documenti, candidati, contratti, certificazioni, log e storico.

## Se vedete ancora 25 righe

Premete **Ctrl+F5** sulla pagina e riprovate: il browser potrebbe aver conservato
la versione precedente. Se il problema persiste, segnalatelo a chi amministra il
portale.
