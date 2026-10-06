# MANUALE AMMINISTRATORE — v1.10.09

## Dopo l'aggiornamento
Sincronizzazione gestionale › SOC › **Esegui ora**: ricostruisce i ticket (incaricati dedotti) e gli abbinamenti; i tecnici trovati sono assegnati all'unità SOC.

## Abbinamento persone → dipendenti
Ora elenca anche gli operatori presenti solo come autori dei messaggi. Abbinare a mano chi resta «— nessuno —» (es. omonimie o nomi non presenti in anagrafica), poi Esegui ora.

## Incaricato reale dal DB SOC
Se il DB SOC contiene l'assegnazione del ticket, aggiungere alla query di estrazione (Connessione › Query di estrazione) una colonna con alias `incaricato` (e facoltativamente `responsabile`): prevale sulla deduzione.

## Regola di deduzione
Incaricato = primo operatore che risponde al cliente o scrive una nota interna sul ticket. Precisione misurata sull'export: 92%.
