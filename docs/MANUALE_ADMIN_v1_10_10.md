# MANUALE AMMINISTRATORE — v1.10.10

## Categoria dal DB SOC
Automatica con la query predefinita (campo «Query di estrazione» vuoto): richiede `SELECT` sull'utente del DB SOC anche per `tt_ticket` e `tt_category`.
```sql
GRANT SELECT ON `<db_soc>`.* TO '<utente>'@'<host_portale>';
```
Dopo l'aggiornamento: Sincronizzazione gestionale › SOC › **Esegui ora** (rilettura completa una tantum). Il registro riporta «categoria da tt_ticket.id_tt_category → tt_category.<colonna>».
Messaggio «categoria non disponibile: …» → tabella o colonna assente o non leggibile dall'utente.

Query personalizzata: aggiungere la colonna con alias `categoria`, ad esempio:
```sql
LEFT JOIN tt_ticket tk ON tk.id = a.id_tt_ticket
LEFT JOIN tt_category c ON c.id = tk.id_tt_category
-- nel SELECT:  c.name AS `categoria`
```
Rilettura completa manuale: impostare `soc.full_resync = 1` (Sistema › Impostazioni) e Esegui ora.
