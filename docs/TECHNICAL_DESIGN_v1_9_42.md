# Technical Design — v1.9.42

## riepilogoContratto() / dettaglioCommessa()
Sorgente invariata (`dgb_forms_activity` diretta, `_operator` in LEFT). Modifiche:

- `JOIN dgb_forms_contract c` → `LEFT JOIN` (tabella vuota se non sincronizzata:
  l'INNER azzerava il risultato).
- `JOIN dgb_operator op` → `LEFT JOIN`.
- `clients` unito su `COALESCE(c.id_customer_comp, a.id_customer_comp)`.
- `cm_projects` unito tramite sottoquery aggregata (una riga per `dgb_contract_id`)
  per evitare fan-out sui totali.
- Chiave di raggruppamento/identità: `a.id_contract` (non più `c.id`).
- Etichetta: `COALESCE(NULLIF(c.code,''), p.project_code, CONCAT('Contratto #', a.id_contract))`;
  nel riepilogo le componenti d'etichetta sono in `MAX()` con `GROUP BY a.id_contract`.

Nessun cambiamento di schema. Verifica su dati reali: 409 contratti nel periodo di test.
