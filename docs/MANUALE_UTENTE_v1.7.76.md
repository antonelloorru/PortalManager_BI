# Manuale Utente Finale — v1.7.76

## Consultare le commesse
1. Menu **Gestione Commesse → Commesse**: elenco con codice, cliente, azienda esecutrice,
   stato, valore e margine. Filtro per codice/nome.
2. Icona **grafico** su una riga → apre la **scheda commessa**.

## Scheda commessa
- **Anagrafica**: informazioni generali e periodo.
- **Team**: chi lavora sulla commessa e con quante ore.
- **Redditività**: valore, costi e margine previsto della commessa.
- **Consuntivo**: rapporti di intervento registrati, con ore, ricavo e costo.

## Note
- I valori economici mostrati derivano dai dati importati.
- Le colonne "Rep." indicano interventi in reperibilità.
- Se un dato non è disponibile è mostrato come "—".

Per creare/modificare commesse, gestire tariffe o importare file servono privilegi
amministrativi: rivolgersi all'amministratore di sistema.

## Perché alcuni rapporti non risultano collegati
Un rapporto importato può non essere collegato alla commessa o al tecnico se il valore presente nel
file non corrisponde ancora a un record del portale. Gli amministratori dispongono della sezione
*Controllo & Riconciliazione* per completare le corrispondenze: una volta impostate, i rapporti
risultano collegati anche retroattivamente, senza rifare l'import.

## Timesheet
Nella sezione *Gestione Commesse → Timesheet* trovi il cartellino mensile. Le ore dei tuoi rapporti di
intervento compaiono già valorizzate giorno per giorno; puoi aggiungere voci manuali (ferie, permessi,
formazione, trasferte) se abilitato. La colonna finale indica la percentuale di saturazione del mese.

## Gantt delle commesse
*Gestione Commesse → Gantt commesse* mostra l'andamento temporale delle commesse: la barra chiara è il
periodo pianificato, quella piena il periodo effettivamente lavorato secondo i rapporti. Aprendo una
commessa, il tab *Gantt* aggiunge le fasi e il carico mensile.

## Carico e sovrapposizioni
*Gestione Commesse → Carico & Sovrapposizioni* mostra, mese per mese, quante ore ciascuna persona ha
dedicato alle commesse e quando questi impegni si sovrappongono: sia quando una stessa persona lavora
su più commesse contemporaneamente, sia quando due commesse si contendono le stesse persone.

## Recuperare un record cancellato per errore
Se elimini per sbaglio un record nel modulo Gestione Commesse (per esempio una fase o una voce di
timesheet), vai in *Gestione Commesse → Cestino*: lì trovi gli elementi eliminati e puoi **Ripristinarli**
con un clic, riportandoli allo stato originale.
