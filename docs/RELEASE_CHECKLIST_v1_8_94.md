# Release Checklist — PortalManager v1.8.94

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.93.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.94` |
| `dir_report.php` | ROOT, **NUOVO** | OK |
| `app/DirModel.php` | **NUOVO** | OK |
| `app/dir_report_print.php` | **NUOVO** | OK |
| `app/MenuManager.php` | modificato — voce di menu | OK |
| `app/Router.php` | modificato — slug | OK |
| `app/Version.php` | modificato | OK |
| 8 ROOT + 7 in `app/` | invariati da v1.8.93 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.94**
- [x] `dir_report` in `Router::PAGES`

## 2. Una pagina per due destinazioni

- [x] Senza `agente` → report direzionale; con `agente` → scheda personale
- [x] Due pagine avrebbero significato **due serie di query** che divergono alla
      prima modifica, con numeri diversi sulla stessa commessa

## 3. Margine solo sulle commesse a ricavo

- [x] 986 su 1.062 hanno `has_revenue = 1`; le altre consumano ore **per
      costruzione** senza produrne
- [x] `has_revenue` riusato da `cm_contract_models` (v1.8.58), non ricostruito
- [x] Le ore delle interne restano contate **a parte**: sono capacità impiegata

## 4. Rischio solo sulle commesse aperte

| | Tutte | Solo aperte |
|---|---|---|
| Commesse | 1.062 | **575** |
| Sforate | 490 | **216** |
| Ferme 90 gg | 505 | **168** |

- [x] **274 delle sforate e 337 delle ferme sono CHIUSE**: ferme perché concluse
- [x] Un quadro con il 46% del portafoglio in sforamento, di cui metà è storia,
      non fa agire nessuno
- [x] Flag `aperta` in **un punto solo**: ripeterlo in dieci indicatori
      significherebbe ricordarsi di aggiornarlo in dieci posti
- [x] I valori economici comprendono tutto: il margine di una commessa chiusa è
      realizzato

## 5. Divergenza invece di avanzamento

- [x] `consumo_valore_pct − avanzamento_pct`
- [x] Un indicatore mediato darebbe **60% sia** per 80/40 **sia** per 40/80 — due
      situazioni opposte con lo stesso numero
- [x] Il segno si legge: positiva = costi in anticipo, negativa = commessa lenta
- [x] **`NULL` quando mancano le date**: con zero risulterebbe in divergenza
      massima e comparirebbe in cima ai rischi per un difetto di anagrafica

## 6. Le commesse da presidiare

| Motivo | Commesse | Valore |
|---|---|---|
| Budget sforato | 216 | 11.009.961 |
| Nessun movimento da 90 gg | 168 | 5.913.413 |
| In scadenza entro 30 gg | 22 | 3.746.119 |
| Margine sotto il 20% | 16 | 365.613 |
| Consumo in anticipo | 8 | 170.565 |

- [x] **Motivo in chiaro**: con cinque criteri e 200 righe, farlo ricostruire a
      chi legge significa che non verrà fatto
- [x] `priorita` per ordinare fra motivi diversi; a parità, per valore
- [x] Una commessa può comparire più volte: sono problemi distinti

## 7. Le schede non confrontano

- [x] `$agenti = $ag === '' ? …agenti() : []` — il metodo esiste, non viene
      chiamato
- [x] Una scheda che riporta la classifica diventa **strumento di valutazione**
      invece che di lavoro
- [x] **Perimetro dichiarato**: «300 su 1.062 (28,2%), 57,6% del valore» — chi
      legge deve sapere cosa non sta vedendo
- [x] Nota sotto la tabella agenti: il numero di commesse non misura il
      rendimento, perché una colonna ordinabile invita a leggerla come classifica

## 8. QA — quadrature

| Verifica | Esito |
|---|---|
| Somma valore agenti = totale | **27.346.626 = 27.346.626** |
| Somma commesse agenti = totale | **575 = 575** |
| Sforate «tutte» = sforate «aperte» | **216 = 216** |
| Scheda mostra un solo agente | verificato su 4 |
| Valore scheda ≤ valore totale | rispettata |
| Barre oltre la larghezza | **0** |
| Chiamate a metodi inesistenti | **0** |
| Avvisi o errori PHP | **0** |

- [x] Il collaudo esegue **le espressioni del template** (metodo v1.8.86)

## 9. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` (dati reali) | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c94` fresco | 612 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c94` | 612 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c94` | 612 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **606 → 612**

## 10. Nota di metodo

Il difetto delle commesse chiuse è emerso solo guardando i numeri prodotti: 490
sforate su 1.062 è un dato formalmente corretto e praticamente inutilizzabile.

Un indicatore va provato sui dati veri prima di consegnarlo: la formula giusta su
un perimetro sbagliato produce allarmi che nessuno può usare.

## 11. Aperto — l'alerting email

Non è in questa release. Servono tre decisioni:

1. **Le soglie**: suggerisco 75% attenzione / 90% allarme sul budget, margine
   sotto il 20%. Sono valori aziendali, non tecnici.
2. **I destinatari**: l'agente solo le sue? il direttore tutte? in copia?
3. **La configurazione SMTP**: il portale **non ce l'ha**. Serve server, porta,
   credenziali, mittente — oppure si ripiega su una coda in tabella, che è meno
   utile.

La struttura proposta resta valida: tre tabelle (`cm_alert_rules`,
`cm_alert_events`, `cm_alert_sent`) con separazione fra rilevazione e invio, e il
vincolo che **un alert già inviato non si reinvia finché la condizione non
cambia** — altrimenti in due settimane il destinatario smette di leggerle.
