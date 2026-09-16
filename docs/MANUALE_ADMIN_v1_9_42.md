# Manuale Amministratore — v1.9.42

## Riepilogo per Codice Contratto — ripristino visualizzazione
Il riepilogo e il dettaglio per commessa tornano a mostrare i dati anche quando la
tabella di lookup dei contratti DGB (`dgb_forms_contract`) non è sincronizzata: i
contratti vengono identificati dal loro id attività e etichettati con il codice
commessa (`cm_projects.project_code`) quando disponibile.

Consiglio: ripristinare comunque la sincronizzazione dei contratti DGB per avere
codici e denominazioni contrattuali complete al posto dell'etichetta "Contratto #id".
