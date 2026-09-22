# Manuale Utente — v1.8.78

## Ore doppie: risolto

Alcuni moduli d'intervento risultavano registrati due volte per lo stesso
tecnico, facendo raddoppiare le ore consuntivate.

Erano 77 casi in tutto, per 362,5 ore. Sono stati eliminati, e un controllo del
database impedisce che si ripetano.

## Nuove colonne nel dettaglio ore

Per ogni tecnico e per ogni giorno trovate ora:

- **ore consuntivate** — il totale
- **ore ordinarie** e **ore extra** — la ripartizione contrattuale
- **ore in orario** — quelle svolte fra le 9 e le 13 o fra le 14 e le 18
- **ore fuori orario** — tutte le altre, comprese quelle del fine settimana

Attenzione a non confondere le due coppie: *ordinarie/extra* dice come sono
retribuite, *in orario/fuori orario* dice quando sono state svolte.

Un intervento dalle 9 alle 19 ha 8 ore in orario e 1 fuori, anche se
contrattualmente parte di quelle ore è straordinario.

## I totali caleranno leggermente

Di 362,5 ore su oltre 344.000 — lo 0,1%. Non si è perso nulla: erano ore contate
due volte.
