# Manuale Amministratore — v1.9.44

## Sincronizzazione gestionale più robusta
Il dataset "Ore per operatore su attività DGB" non interrompe più la sincronizzazione con
l'errore "Duplicate entry ... for key 'uq_dfao_activity_operator'". Quando la sorgente
ripresenta la stessa coppia attività/operatore sotto un identificativo diverso, la riga
già presente viene aggiornata invece di generare un errore.

La sincronizzazione è ora ripetibile senza effetti collaterali su tutti i dataset: rilanci
successivi aggiornano i dati esistenti anziché tentare inserimenti in conflitto.
