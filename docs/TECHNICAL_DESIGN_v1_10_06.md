# Technical Design v1.10.06 — Service SOC

## 1. Scopo
Rendicontare l'attività del SOC (ticket del sistema di gestione SOC, istanza separata del gestionale) affiancandola ai dati del portale:
moduli di intervento, commesse, dipendenti, clienti. Stesso impianto di Service Desk: filtri, indicatori, ripartizioni, andamento, team, presidio, export.

## 2. Flusso dati
```
Export XLSX/CSV «lista eventi ticket» ──┐
                                         ├─► SocIngest::normalize ─► cm_soc_events (chiave sha1) ─► rebuild ─► cm_soc_tickets
DB SOC (tt_article, tt_queue,           │        (mapHeaders, kind,                                     │        autoMap
 dgb_operator) via SourceDb (RO) ───────┘         parseDateTime, parseDuration)                        ▼
                                                                                 cm_soc_people → employees · cm_soc_clients → clients
SocModel ── cm_soc_tickets / cm_soc_events ⨝ cm_intervention_reports(ticket) ⨝ cm_projects ⨝ employees ⨝ clients ─► service_soc.php
```
Lotti in `cm_soc_batches` (fonte, origine, esito, righe lette/nuove/aggiornate/invariate/scartate, periodo).

## 3. Normalizzazione
| Campo | Intestazioni accettate |
|---|---|
| event_at | Data evento, Data, Ricevuto il, received_at |
| ticket_code | Codice, Codice ticket, Ticket (il codice messaggio `WES_…_001` viene ridotto al ticket) |
| event_label → event_kind | Evento: supporto (Messaggio del supporto / SUPPORT_MSG), cliente (Risposta del cliente / CUSTOMER_MSG), nota (Annotazione interna / INTERNAL_NOTE), apertura (APERTO / OPEN / NEW), altro |
| status_before / status_after | Stato prima / Stato dopo (maiuscolo) |
| author_name, subject, queue_name, mailbox | Autore, Titolo, Coda, Casella di posta |
| owner, assignee, type, category, resolution, client, soc_contract | Responsabile, Incaricato, Tipo, Categoria, Risoluzione, Cliente, Commessa |
| duration_min | Durata («292g 19h 49m 5s», «HH:MM:SS», minuti) |

Chiave evento = `sha1(ticket | istante | tipo | stato prima | stato dopo | progressivo dei duplicati)`. L'autore non entra nella chiave
(il gestionale lo scrive in forme diverse fra export e DB). Precedenza: campi dell'evento = primo valore; campi del ticket = ultimo valore non vuoto.

## 4. Ricostruzione ticket (`SocIngest::rebuild`)
- opened_at = primo evento; last_event_at, last_kind = ultimo evento; opened_by = tipo del primo evento.
- status_now = ultimo `stato dopo` non vuoto; is_closed = status_now in `soc.closed_states` (default CHIUSO, CHIUSO DAL CLIENTE);
  closed_at = ultimo passaggio da stato aperto a chiuso (in mancanza, ultimo evento).
- Attributi (categoria, cliente, commessa, responsabile, incaricato, esito, tipo, coda, casella) = ultimo valore non vuoto.
- Contatori: eventi, supporto, cliente, note, riaperture (da chiuso a non chiuso).
- reply_min (evento cliente) = minuti al primo messaggio del supporto successivo; avg_reply_min = media per ticket.
- resolution_min = chiusura − apertura; duration_min = durata riportata dal gestionale.

## 5. Indicatori (SocModel)
| Indicatore | Calcolo |
|---|---|
| Ticket del periodo | ticket con almeno un evento nel periodo (perimetro di ogni riquadro); aperti = primo evento nel periodo; chiusi = closed_at nel periodo |
| Risposta al cliente | mediana di reply_min dei messaggi del cliente ricevuti nel periodo; % entro `soc.sla_risposta_ore`; «in attesa» = messaggi del cliente senza risposta su ticket ancora aperti |
| Tempo di chiusura | mediana di resolution_min dei ticket chiusi nel periodo; risolti = esito RISOLTO; riaperture |
| Backlog a fine periodo | opened_at ≤ fine periodo e (aperto o chiuso dopo la fine) |
| Da presidiare | aperto, (mai preso in carico o ultimo evento del cliente) e ultimo evento oltre `soc.presidio_ore` |
| Moduli di intervento | `cm_intervention_reports.ticket` = codice ticket, report_date nel periodo: moduli, ore, costo, ricavo |
| Team | per incaricato: ticket, chiusi, aperti, messaggi supporto e note scritti (autore), risposta media; dipendente abbinato: ore moduli SOC / totali e quota |
| Clienti e commesse | per cliente SOC (+ cliente portale), per commessa SOC, per commessa PM dai moduli |

Filtro globale contratti: `PmContractFilter::sql('ticket', 't.ticket_code')` (ticket con moduli/attività sulle commesse scelte).

## 6. Schema (ER)
```
cm_soc_batches 1─N cm_soc_events N─1 cm_soc_tickets (ticket_code)
cm_soc_tickets.assignee_name/owner_name ─ cm_soc_people.name ─ employees.id
cm_soc_tickets.client_name ─ cm_soc_clients.name ─ clients.id
cm_soc_tickets.ticket_code ─ cm_intervention_reports.ticket ─ cm_projects.id
cm_soc_source_db (1 riga attiva) ── SourceDb (sola lettura, password AES-256-GCM con APP_SECRET)
```

## 7. Sicurezza
Solo SELECT verso il DB SOC (SourceDb::query rifiuta altro, utenza RO consigliata); password mai restituita al browser; configurazione
connessione solo Super Admin; import/sincronizzazioni/abbinamenti con permesso `edit`; export con `export`; CSRF su ogni POST; PRG.

## 8. Moduli
`service_soc.php` (pagina), `app/SocIngest.php` (ingestion, rebuild, abbinamenti), `app/SocModel.php` (letture), `app/soc_ticket_table.php` (tabella ticket),
`cron_soc_sync.php` (CLI), `tools/verify_v1_10_06.php`.
