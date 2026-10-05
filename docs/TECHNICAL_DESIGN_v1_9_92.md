# TECHNICAL DESIGN — v1.9.92

## Export «Giorni per commessa»
`giorniPer($f, 'commessa')` aggiunge `MAX(cliente)` (v_cm_it_giorni_base: COALESCE(clients.name, cm_projects.client_raw))
e `(SELECT description FROM cm_projects WHERE project_code = commessa LIMIT 1)`. In `it_service.php` (ramo export=xlsx) il foglio
della dimensione `commessa` antepone le due colonne. Nessun effetto sulle altre dimensioni né sulla UI.

## Commesse / Progetti
Solo template HTML della tabella: intestazione «Link SP», nuova colonna «Scheda Progetto» (pulsante verso `project_dashboard`),
rimossa l'ultima colonna. Query, filtri ed export invariati.

## Schema ER
Invariato.
