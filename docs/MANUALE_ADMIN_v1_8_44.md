# Manuale Amministratore — v1.8.44

## Il difetto corretto

Nell'Anagrafica dipendenti, spuntando "Tutti i record" ed esportando, si
ottenevano comunque solo 25 righe.

La causa stava nella paginazione. L'elenco è gestito da DataTables, che mostra 25
righe per pagina e — questo è il punto — **rimuove dalla pagina** le righe delle
altre schermate anziché limitarsi a nasconderle. L'export leggeva le righe dalla
pagina, quindi ne trovava 25 e si fermava lì: le altre, in quel momento, non
esistevano nel documento.

La casella "Tutti i record" introdotta con la versione precedente rimuoveva
correttamente il filtro, ma agiva su un insieme che era già ridotto a monte.

## Non riguardava solo l'Anagrafica

Il portale ha 13 elenchi con paginazione, di cui **10 dotati anche di filtri ed
export**: Anagrafica dipendenti, Utenti, Tecnologie brand, Catalogo
certificazioni, Documenti, Candidati, Contratti recruiting, Report
certificazioni, Log di sistema, Storico. Tutti troncavano allo stesso modo.

La correzione è dentro il componente dei filtri, quindi tutte e dieci sono
sistemate insieme.

## Come verificare

Aprire l'Anagrafica dipendenti e leggere il totale indicato dalla paginazione in
fondo alla tabella. Poi Esporta → spuntare "Tutti i record" → CSV, e contare le
righe del file: devono essere quante il totale, più l'intestazione.

Un secondo controllo utile: spostarsi a pagina 2 e ripetere l'export. Il
risultato deve essere identico, perché l'export non dipende più dalla pagina che
si sta guardando.

## Anche i filtri erano coinvolti

Lo stesso troncamento riguardava i filtri per colonna: venivano applicati alle
sole righe della pagina corrente. Ora agiscono sull'intero elenco, quindi il
conteggio delle righe filtrate e l'export filtrato sono coerenti con tutti i dati.

Resta una differenza da conoscere: la suddivisione in pagine continua a essere
gestita da DataTables e non tiene conto dei filtri per colonna di ListFilter.
Conteggio ed export sono corretti; la navigazione fra le pagine mostra ancora
tutte le righe. Per una lettura a schermo del solo insieme filtrato conviene usare
il campo di ricerca della tabella, che agisce sulla paginazione.
