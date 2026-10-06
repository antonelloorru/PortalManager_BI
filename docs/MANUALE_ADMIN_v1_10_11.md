# MANUALE AMMINISTRATORE — v1.10.11

## Perimetro del Consuntivo SOC
Componenti attivi dell'Unità Organizzativa con codice `soc.uo_code` (default `SOC`), gestita in Unità Organizzative Tecniche e alimentata dalla pipeline (Sincronizzazione gestionale › SOC).
- Aggiungere o togliere un componente: scheda tecnica del dipendente (unità) oppure «Sposta su SOC» nella scheda SOC della sincronizzazione.
- Tipologia di contratto = linea di servizio della commessa (cm_projects.service_line) con etichetta e modello da `cm_contract_models` (stessa configurazione della Relazione di Servizio IT).
- Ore non classificate: moduli senza rapportino agganciato né fascia oraria: verificare la sincronizzazione delle attività DGB.

## Permessi
Visibile con `view` su Service SOC; export con `export`. Il collegamento alla Relazione IT compare a chi ha `view` su it_service.php.
