# Release Checklist — PortalManager v1.9.5

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.4.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.5` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 4 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.4 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.5**

## 2. Le cinque viste

| Vista | Contenuto |
|---|---|
| `v_cm_sd_moduli` | riga elementare: modulo con tutte le dimensioni |
| `v_cm_sd_team_quadro` | i sei indicatori di squadra |
| `v_cm_sd_team_dettaglio` | ticket e moduli affiancati per componente |
| `v_cm_sd_team_fascia` | interventi e ore per fascia |
| `v_cm_sd_team_contratto` | contratto **incrociato** con la fascia |

- [x] Una riga elementare, cinque aggregazioni: costruirle sui join avrebbe
      ripetuto sei volte la stessa catena

## 3. Ticket e moduli affiancati — terza volta

- [x] **Non esiste una colonna che leghi un modulo al ticket** che lo ha
      originato: la sovrapposizione non è misurabile
- [x] Sommarli produrrebbe un numero che non corrisponde a nulla, e nessuno
      saprebbe di quanto sbaglia
- [x] Intestazione doppia TICKET / MODULI, con bordo di separazione
- [x] Dichiarato che, se il gestionale esponesse il riferimento, la somma
      diventerebbe possibile

## 4. La fascia oraria

- [x] **Riusata** dalla v1.8.53 e da `v_cm_it_servizio`, non riscritta
- [x] **Fine settimana valutato per primo**, prima dell'ora: un intervento
      domenicale alle 10 cadrebbe altrimenti nella finestra 09–13
- [x] Verificato: modulo del **20/06/2026, sabato** → fuori orario
- [x] **`non rilevata` come terza categoria**: assegnarla a una delle due
      gonfierebbe o sgonfierebbe quella quota con casi ignoti
- [x] Se `non rilevata` cresce, il collegamento moduli-attività si degrada:
      indicatore utile

## 5. Contratto incrociato con la fascia

- [x] Due viste separate avrebbero risposto a due domande e non alla terza:
      **su quali contratti si lavora fuori orario**
- [x] Un canone erogato fuori orario costa più di quanto il canone preveda

## 6. QA sui dati

| Verifica | Esito |
|---|---|
| Quadratura fasce = totale | **17,0 = 17,0** |
| Quadratura contratti = totale | **17,0 = 17,0** |
| Quadratura dettaglio = quadro | **17,0 = 17,0** |
| Ordinamento per cognome | Bressi, Chiarini, Ferrante, Mancini |
| Sabato fuori orario | **sì** |
| Filtro tecnico | 7,0 h < 17,0 h |
| Chiamate a metodi inesistenti | **0** |
| Avvisi o errori PHP | **0** |

- [x] Le tabelle `cm_intervention_reports` e `cm_sd_messages` sono **vuote nel
      dump caricato**: il collaudo ha usato righe costruite, poi rimosse
- [x] La struttura è verificata; i **valori** dipenderanno dai dati reali

## 7. Quality Assurance SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_real` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_real` | 9 stmt, **err=0** |
| Migration RUN3 | `pm_real` | 9 stmt, **err=0** |
| Coda del consolidato RUN1 | `pm_real` | 7 stmt, **err=0** |
| Coda RUN2 (idempotenza) | `pm_real` | 7 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `php -l` su tutti i file: OK

## 8. Aperto

- **I dati di Service Desk non sono nel dump caricato**: `cm_sd_messages` e
  `cm_intervention_reports` sono vuote. Le viste sono verificate nella struttura e
  nelle quadrature, ma i numeri reali si vedranno solo in produzione.
- **Il legame ticket-modulo non esiste**: se il gestionale lo esponesse, si
  potrebbe misurare quanti ticket generano un intervento.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`
  invece di `margin_total`, pagine mancanti per presidi e redditività a costo
  reale, riepiloghi cadenzati.
