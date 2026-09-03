# Release Checklist — PortalManager v1.9.10

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.9.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.10` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 6 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 30 file restanti | invariati da v1.9.9 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.10**

## 2. OBJ_2 — copertura

| Richiesta | Esito |
|---|---|
| Valore totale commesse SD | **19.407.949 €** su 718 commesse |
| N° medio addetti | **tre misure**, tutte esposte |
| Ticket gestiti vs escalati | struttura pronta, dati assenti nel dump |
| % di escalation | sui presi in carico, regola v1.8.84 |

- [x] Perimetro come **parametro**, non elenco nel codice
- [x] `FIND_IN_SET` e non `IN`: `IN` avrebbe richiesto SQL dinamico, impossibile
      in una vista
- [x] `margin_total` dal gestionale, non ricostruito (v1.9.2)

## 3. «Medio» richiede di dire su cosa

- [x] Tre misure: distinti, medi/mese, equivalenti a tempo pieno
- [x] Chi ha fatto **un intervento in un anno** conta come 1 nella prima e come
      1/20 nella terza
- [x] Sceglierne una e chiamarla «media» avrebbe **nascosto la scelta**
- [x] Denominatore dei mesi **dai dati**, non dal calendario: un mese senza
      interventi non è un mese di attività ridotta

## 4. Il tasso di escalation

- [x] Sui **soli presi in carico** — terza volta che la regola si ripresenta
- [x] Al denominatore, un peggioramento del presidio si presenterebbe come un
      **miglioramento dell'escalation**

## 5. OBJ_2.3 — ripartizione

- [x] Sei classi con quote, code, messaggi medi, durata
- [x] **`durata_ore` non è il tempo di prima risposta**: la vista non espone
      quest'ultimo. Chiamarla così avrebbe prodotto una colonna che si legge come
      SLA e non lo è
- [x] Media sui soli presi in carico: la durata di un mai preso misura l'attesa

## 6. Export

- [x] **XLSX**: 6 fogli nuovi, incluse tutte le 718 commesse
- [x] **PDF**: dal report di stampa, con quadro, linee, ticket e ripartizione

## 7. QA sui dati reali

| Verifica | Esito |
|---|---|
| Somma valore per linea = quadro | **19.407.949,3 = 19.407.949,3** |
| Somma margine per linea = quadro | **10.771.749,9 = 10.771.749,9** |
| Somma delle quote | **100,0%** |
| Perimetro configurato = trovato | **5 su 5** |
| Espressioni del template | eseguite, 0 avvisi |
| Bilanciamento `<div>` in stampa | **73 = 73** |
| Metodi inesistenti | **0** |
| Migration RUN1/RUN2/RUN3 | 11 stmt, **err=0** |
| Coda consolidato RUN1/RUN2 | 9 stmt, **err=0** |
| `;` nei commenti SQL | **0** |

## 8. Difetti intercettati

- [x] Un `;` in un commento SQL
- [x] **`ore_prima_risposta` non esiste** in `v_cm_sd_ticket`: la vista espone
      `durata_ore`. Rilevato al primo RUN1

## 9. OBJ_2.1 e OBJ_2.2 non implementati — motivazione

`cm_sd_messages` ha solo `ticket_code`, `msg_type`, `queue_id`, `queue_name`,
`author_name`, `received_at`. **Nessun riferimento a commessa, cliente o
contratto.**

Le strade possibili erano tre — dedurre il contratto dalla coda, dal cliente, o
dal periodo — tutte fondate su un'assunzione non verificabile.

Ciascuna avrebbe prodotto numeri che si sommano e si ripartiscono in percentuali,
e sembrano un'analisi. La v1.9.1 ha mostrato cosa succede: 5,36 milioni di
scostamento annunciati con sicurezza e sbagliati.

**Richiesto al cliente**: regola di raccordo, criterio di riconoscimento di
«Internal Support», listino standard.

## 10. Aperto

- **`cm_sd_messages` è vuota nel dump**: ticket, addetti e ripartizione restano a
  zero. Le quadrature economiche sono verificate sui dati reali; le parti sui
  ticket sono verificate nella struttura.
- **Le viste dei pannelli ricostruiscono ancora il margine** invece di leggere
  `margin_total`: incoerenza nota dalla v1.9.2.
- **WTS-HD ha margine −393,8%**: corretto, ma vale la pena decidere se la linea
  interna appartiene al perimetro.
- Restano gli aperti precedenti: `workload_overview` e `dgb_activities` non
  uniformati, pagine mancanti per presidi e redditività a costo reale.
