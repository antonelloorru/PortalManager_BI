# Manuale Amministratore — v1.9.37

## Aggiornare senza timeout
La fase **Aggiornamento (ZIP) → Applica** esegue un backup completo di file e
database prima di sovrascrivere. Con database di grandi dimensioni (tabelle DGB)
il backup poteva superare il limite di tempo e fermarsi a metà.

Da questa versione il limite di tempo è rimosso per la sola fase di applicazione,
e l'operazione non viene interrotta se il browser o il proxy chiudono la
connessione. Non serve alcuna impostazione: l'aggiornamento va avviato come prima.

Se l'aggiornamento sembra "fermo", è il backup in corso: attendere il
completamento, non ricaricare la pagina.

## Ordinativi Pratix — nuova dimensione dati
La vista di dettaglio delle operazioni Pratix riporta ora, per ogni riga, il
**Commerciale** e il **Cliente** della commessa collegata, ricavati
dall'anagrafica commesse. È la base su cui poggeranno i due filtri a tendina
Commerciale e Cliente della sezione (in arrivo).
