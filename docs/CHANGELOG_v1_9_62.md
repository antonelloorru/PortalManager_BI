# PortalManager v1.9.62 — Fix tab Pratix (Dettaglio commessa)

## Bug
La tab "Pratix" mostrava "Nessun Codice Pratix collegato" pur con dati presenti
(visibili in Ordini Pratix).

## Causa
La vista/relazione univa `v_cm_pratix_righe.commessa` (codice/etichetta testuale) a
`cm_projects.project_code`: non coincidono. In realtà le righe pratix si legano alla
commessa tramite **`commessa_id` = `cm_projects.id`** (è lo stesso link usato da Ordini
Pratix: la riga rimanda a `project_dashboard?id=<commessa_id>`).

## Fix
- Vista `v_cm_pratix_commessa_codici` ridefinita per chiave **`commessa_id`** (esposta
  come `project_id`), 1-a-N per (commessa, order_code), clienti da `cm_pratix_ext` per
  singolo `order_code`.
- `project_dashboard.php`: la query della tab filtra ora per `project_id = $pid`
  (id della commessa corrente), non più per `project_code`.

## QA
- `WHERE project_id = <id>` restituisce tutti i Codici Pratix della commessa con
  Cliente Fatturazione/Effettivo per codice (verificato: 1 commessa → 2 codici con
  clienti distinti). `php -l` OK; migration RUN1/RUN2 err=0; schema_version → 1.9.62.

## File
```
VERSION                       1.9.62
project_dashboard.php         tab Pratix: filtro per project_id (id commessa)
sql/migration_v1_9_62.sql     vista v_cm_pratix_commessa_codici su commessa_id + bump
docs/
```
