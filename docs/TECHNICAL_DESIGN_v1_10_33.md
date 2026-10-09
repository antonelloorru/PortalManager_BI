# TECHNICAL DESIGN — v1.10.33

## `ItServiceModel::controlloReperibilita`
Condizione sugli interventi in reperibilità:
```
(TIME(start_at) >= '18:01:00' OR TIME(start_at) < '09:00:00')
AND ( repFlagSql()                                    -- modalità Reperibilità OR ir.on_call = 1
      OR (end_at > start_at AND end_at <= TIMESTAMP(DATE(start_at) + INTERVAL (TIME(start_at) >= '18:01:00') DAY, '09:00:00')) )
```
Il limite è le 09:00 del giorno di inizio se l'intervento parte dopo la mezzanotte, altrimenti le 09:00 del giorno dopo.

`repFlagSql()` usa la stessa regola di `oreClassi()['repC']`.

Il modulo del giorno successivo aggiunge:
- `cliente` = `COALESCE(clients.name per ir.client_id, ir.client_raw, cliente della commessa)`, la stessa derivazione di `v_cm_it_servizio.cliente`;
- `tipo` = `cm_projects.service_line`, pari a `linea_servizio` della vista.

## `TechReport`
- `H_REP` passa a 11 colonne.
- `rep()` restituisce data/ora come «inizio–fine» e i campi Cliente / Codice Commessa / Tipo per entrambi gli interventi.
- I filtri di colonna `cf[0..10]` seguono il nuovo indice.

## Schema ER
Nessuna modifica.
