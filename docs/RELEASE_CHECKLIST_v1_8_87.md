# Release Checklist — PortalManager v1.8.87

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.86.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.87` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 4 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 7 in `app/` | invariati da v1.8.86 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.87**

## 2. Il ponte fra le due nomenclature

- [x] Ticket: `Sebastiano Chiarini` · Moduli: `Chiarini Sebastiano`
- [x] Join diretto darebbe **zero ore**, che somiglia a «non fa interventi»: un
      dato plausibile e falso, senza alcun errore
- [x] `v_cm_sd_nome_moduli` concilia **entrambi gli ordini**, riusando le due
      forme già esposte da `v_cm_sd_team` (v1.8.82)
- [x] Vista e non colonna: l'inversione è una caratteristica dei dati sorgente
- [x] Verificato: **4 su 4** componenti agganciati

## 3. Ticket e moduli affiancati, mai sommati

| Componente | Ticket presi | Moduli | Ore | A ricavo |
|---|---|---|---|---|
| Enrico Mancini | 613 | 1.070 | 1.702,0 | 34,4% |
| Sebastiano Chiarini | 520 | 1.130 | 1.402,0 | 36,2% |
| **Emanuele Bressi** | **278** | 1.219 | **6.565,5** | 28,4% |
| Greta Ferrante | 49 | 648 | 2.239,0 | 10,8% |

- [x] Un ticket può generare un modulo: il totale unico sarebbe doppio conteggio
- [x] Nessun legame esplicito fra le tabelle: la sovrapposizione **non è
      quantificabile**, quindi nessun totale complessivo
- [x] Intestazione a due livelli e bordo di separazione
- [x] Bressi: **meno ticket di tutti, più ore di tutti** — un indice sintetico lo
      avrebbe messo in fondo alla classifica

## 4. Tipologia di contratto

- [x] `has_revenue` **riusato** da `cm_contract_models` (v1.8.58), non ricostruito
- [x] `COALESCE(has_revenue, 1)`: le linee non classificate contate come a
      ricavo — contarle interne gonfierebbe la quota non fatturabile, che è il
      numero su cui si decide
- [x] Oltre due terzi delle ore su commesse **interne**: dato sulla struttura del
      servizio, dichiarato come tale
- [x] Ore extra come colonna **«di cui»**, non sommate (regola v1.8.78)

## 5. La quota sulla coda

| Coda (Chiarini) | Ticket | Quota |
|---|---|---|
| Supporto interno | 250 | **60,0%** |
| Sistemi | 117 | 7,6% |

- [x] 117 e 250 sembrano comparabili, sono il 7,6% e il 60% delle rispettive code
- [x] Denominatore = ticket **distinti** della coda, non messaggi: un tecnico
      prolisso avrebbe una quota gonfiata
- [x] Soglie: verde ≥ 40% (presidio), ambra ≥ 15%

## 6. QA — quadrature a tre livelli

| Livello | Verifica | Esito |
|---|---|---|
| totale | somma dei quattro = sorgente | **11.908,5 = 11.908,5** |
| per persona | ore a ricavo + interne = totale | rispettata su 4/4 |
| per contratto | somma righe = totale persona | rispettata su 4/4 |

- [x] La terza intercetta gli errori di `GROUP BY`: chiave incompleta →
      moltiplicazione delle righe → somma superiore al totale

## 7. QA — collaudo del template

| Verifica | Esito |
|---|---|
| Chiamate a metodi inesistenti | **0** |
| Errori sulle 4 schede complete | **0** |
| Quota coda oltre il 100% | **0** |
| Barra a ricavo/interne oltre il 100% | **0** |
| Contratti per componente | 6–14 |
| Code per componente | 5–12 |

- [x] Espressioni del template eseguite con avvisi convertiti in eccezioni
      (metodo v1.8.86)
- [x] Verifiche sui limiti: colgono gli errori di scala, che non producono
      eccezioni ma un riquadro visibilmente sbagliato

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` (dati reali) | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c87` fresco | 587 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c87` | 587 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c87` | 587 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **581 → 587**

## 9. Nota di metodo

Il caso di Bressi mostra perché le due attività vanno affiancate e non sintetizzate:
è ultimo per ticket presi in carico e primo per ore consuntivate. Qualunque
punteggio unico avrebbe descritto una persona diversa da quella reale.

## 10. Aperto

- **La sovrapposizione fra ticket e moduli non è misurabile**: manca un legame
  esplicito fra `cm_sd_messages` e `cm_intervention_reports`. Se il gestionale
  esponesse il riferimento al ticket sul modulo, si potrebbe quantificare quanti
  ticket generano un intervento.
- Le ore dei moduli seguono `report_date`, i ticket `received_at`: su periodi
  brevi le due grandezze possono riferirsi a insiemi leggermente diversi.
- Restano aperti: SLA non definiti, `tt_ticket` e `tt_ticket_act` non esportate.
