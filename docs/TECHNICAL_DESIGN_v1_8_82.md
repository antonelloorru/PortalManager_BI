# Technical Design — PortalManager v1.8.82

## 1. Il ticket non esiste come riga

`tt_ticket` non è nel dump. Il ticket va **ricostruito** dai suoi messaggi, e la
ricostruzione ha tre punti delicati.

**Il codice.** `WTS_000000003_001` contiene il ticket e il progressivo.
`SUBSTRING_INDEX(code, '_', 2)` estrae `WTS_000000003`. È fragile rispetto a un
cambio di formato, ma è l'unica chiave disponibile — e `id_tt_ticket` la conferma:
3.512 valori distinti su entrambi.

**Lo stato corrente.** È il `status_after` dell'ultimo messaggio, non un massimo:

```sql
(SELECT x.status_after FROM cm_sd_messages x
  WHERE x.ticket_code = m.ticket_code
  ORDER BY x.received_at DESC, x.source_id DESC LIMIT 1)
```

`MAX(status_after)` avrebbe restituito il valore alfabeticamente maggiore —
`WAITING_FOR_SUPPORT_RESPONSE` batte `CLOSED` — cioè un dato plausibile e
sbagliato.

Il secondo criterio di ordinamento (`source_id`) serve ai messaggi con lo stesso
istante di ricezione: senza, l'ultimo sarebbe indeterminato.

**Le date.** `MIN(received_at)` è l'apertura, `MAX` l'ultimo movimento. La durata
fra i due non è il tempo di risoluzione — comprende le attese del cliente — ed è
esposta come `durata_ore` senza chiamarla altrimenti.

## 2. Il team come vista, non come tabella

`v_cm_sd_team` legge `cm_tech_profiles` in tempo reale.

Copiare l'elenco in una tabella sarebbe stato più veloce e avrebbe creato un
secondo registro da tenere allineato: un tecnico spostato di unità nel portale
resterebbe classificato come prima, e la statistica direbbe una cosa mentre
l'anagrafica ne dice un'altra.

Il legame passa dal **nome** perché `cm_tech_profiles` punta a `employees` o
`cm_professionals`, mentre i messaggi portano `id_author` di `dgb_operator`.
Entrambi gli ordini nome/cognome, per l'inversione già vista nella v1.8.77.

Verificato: tutti e quattro i tecnici agganciati, 8.406 messaggi su 8.406 con
operatore risolto.

## 3. Perché tre classi e non due

La regola dice: L1 è l'unità, tutto il resto è escalation. Applicata alla lettera
sui dati produce **1.576 escalation su 2.941 ticket con supporto** — il 54%.

Sarebbe stato un numero drammatico e falso. Guardando dove stanno quei ticket:

| Coda | Ticket senza L1 |
|---|---|
| Sistemi | 2.333 |
| Cybersecurity | 773 |
| Network | 651 |

Sono code specialistiche. Un ticket che nasce su *Cybersecurity* e viene lavorato
dal SOC non è stato "scalato": non è mai passato dal Service Desk.

L'escalation presuppone una **presa in carico precedente**. Da qui la condizione:

```sql
WHEN SUM(msg_type='SUPPORT_MSG' AND livello='L1') = 0
     THEN 'presa in carico diretta da specialisti'
WHEN SUM(msg_type='SUPPORT_MSG' AND livello='L2') > 0
     THEN 'escalation di 2 livello verso specialisti'
```

Il tasso di escalation viene calcolato **solo sui ticket presi in carico da L1**:
105 su 1.470, il 3,0%. È la misura che risponde alla domanda «quanto spesso il
primo livello non basta».

## 4. La quarta classe

571 ticket non hanno alcun messaggio di supporto. Non sono né risolti né scalati:
non hanno mai ricevuto una risposta scritta.

553 di questi sono **chiusi**. Sono notifiche automatiche, o richieste risolte per
telefono e chiuse a mano. Contarli fra i risolti gonfierebbe la produttività;
contarli fra i non gestiti creerebbe un allarme falso.

Restano 18 realmente aperti senza risposta, ed è il solo numero che merita
attenzione operativa.

## 5. Il numero di code come indicatore

Non era previsto, ed emerge dalla vista per operatore:

| Tecnico | Livello | Code |
|---|---|---|
| Mancini, Chiarini, Bressi | L1 | **11** |
| Todisco | L2 | 2 |
| De Caprio | L2 | 4 |

Il primo livello smista trasversalmente, gli specialisti presidiano il proprio
dominio. È una conferma indipendente della classificazione: se un tecnico marcato
L1 operasse su due code soltanto, l'assegnazione all'unità andrebbe verificata.
