# Manuale Utente Finale — v1.7.97

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
timesheet), vai in *Sistema → Cestino*: lì trovi gli elementi eliminati e puoi **Ripristinarli**
con un clic, riportandoli allo stato originale.

## Recupero esteso a tutto il portale
Dalla v1.7.97 il Cestino non riguarda più solo le commesse: quando elimini per errore un record del
portale — una certificazione, una lingua o un titolo di studio di un dipendente, una dotazione
(telefono, SIM, notebook, veicolo, carte), un reparto e così via — questo finisce nel Cestino e può
essere ripristinato. Restano esclusi solo gli elementi di sistema (log, permessi, preferenze).

## Importare un professionista tra i dipendenti
Se un professionista è in realtà una persona da inserire tra i dipendenti, aprila in *Anagrafica
Professionisti* e usa **In Dipendenti**: verrà creata la scheda dipendente. Se la persona non è più
attiva, indica la data di cessazione nel campo accanto al pulsante.

## Unire schede con nomi incompleti
Se sospetti che la stessa persona sia registrata due volte perché in una scheda manca il secondo nome o
parte del cognome, in *Verifica & Merge* scegli il criterio **Stesso nome (simile)**: elencherà le coppie
con nome/cognome parzialmente diversi, pronte per l'unione.

## Completare le email aziendali
Se alcune schede hanno solo l'email personale, in *Verifica & Merge* scegli **Email aziendale mancante**:
vedrai l'elenco dei casi e potrai decidere tu su quali copiare la personale nell'email aziendale.
