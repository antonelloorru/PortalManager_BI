# TECHNICAL DESIGN — v1.10.09

## Incaricato del ticket (SocIngest::rebuild)
1. `assignee_name` = ultimo valore non vuoto degli eventi (export «Incaricato», query personalizzata). Valori «non assegnato», «nessuno», «-» → NULL.
2. Non NULL → `assignee_source = 'sorgente'`.
3. NULL → primo evento `supporto` o `nota` con autore valorizzato (ordine `event_at`, `id`):
   `SUBSTRING(MIN(CONCAT(DATE_FORMAT(event_at,'%Y%m%d%H%i%s'), LPAD(id,11,'0'), author_name)), 26)` → `assignee_source = 'dedotto'`.
4. `assignee_employee_id`, `owner_employee_id`, `client_id` da `cm_soc_people` / `cm_soc_clients`.

Ticket senza alcuna risposta o nota di operatori: incaricato NULL (non assegnato).

## Abbinamento persone (SocIngest::autoMap)
Nomi = incaricati ∪ responsabili ∪ autori di eventi `supporto`/`nota`. Regola invariata: tutte le parole del nome SOC contenute in un solo dipendente; abbinamenti manuali preservati.
Effetto a catena: `SocSync::serviceTechnicians()` / `assignUnit()` trovano gli operatori anche con la sola sorgente DB.

## SocModel
- `team()`: `ticket` (incaricato), `dedotti`, `seguiti` = `COUNT(DISTINCT ticket_code)` degli eventi supporto/nota dell'autore nel periodo; righe aggiunte per gli autori senza ticket assegnati; righe dei componenti dell'unità SOC senza attività.
- `where()['tec']`: incaricato OR responsabile OR autore di supporto/nota sul ticket.
- `valori('tec')`, `people()`: includono gli autori.

## Schema ER (delta)
`cm_soc_tickets.assignee_source` VARCHAR(10) NULL (`sorgente`, `dedotto`).

## Fonte dell'incaricato reale dal DB SOC
Se il DB SOC espone l'assegnazione (es. tabella dei ticket), basta una query personalizzata che restituisca la colonna con alias `incaricato` (e `responsabile`): ha la precedenza sulla deduzione.
