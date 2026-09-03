# Release Checklist — PortalManager v1.8.92

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.91.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.92` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `it_service.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 2 metodi** | OK |
| `app/ItServiceModel.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.91 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.92**

## 2. Codice ed etichetta come dimensioni distinte

- [x] `WTS-ACM` (documenti e gestionale) e «Chiavi in mano» (leggibile)
      rispondono a **domande diverse**
- [x] Combinabili: `Codice × Etichetta` produce una riga con entrambi
- [x] Il **modello contrattuale** è un raggruppamento più grosso: `WTS-MON` e
      `WTS-SD` sono entrambe a canone ma servizi diversi

## 3. Relazione di Servizio IT

| Codice | Interventi | Ore |
|---|---|---|
| WTS-PRES | 3.888 | 29.123,5 |
| NV_AI | 3.409 | 10.861,5 |
| WTS-CSS | 2.760 | 10.790,5 |
| WTS-ACM | 1.442 | 6.673,0 |

- [x] **19 codici**; filtro a selezione multipla, raggruppamento, grafico, foglio
      nell'export

## 4. Service Desk

- [x] Riquadro **Moduli per codice linea**: 14 codici, **11.908,5 h**
- [x] Barra proporzionale verde (a ricavo) / grigio (interne)
- [x] **Segue il filtro tecnico** (principio v1.8.88): con la scheda aperta ogni
      riquadro deve riferirsi a quella persona
- [x] Codice aggiunto anche alla tabella per tipologia di contratto e al report
      di stampa

## 5. `project_type` non esposto

- [x] La colonna esiste ed è **vuota su 1.062 commesse su 1.062**
- [x] Esporla darebbe un filtro con un solo valore — «(vuoto)» — e una riga sola
      in ogni raggruppamento: la forma di un'analisi senza il contenuto
- [x] Caso **opposto** agli SLA (v1.8.83): lì la struttura è predisposta perché il
      dato arriverà; qui nulla indica che qualcuno lo popoli
- [x] Dichiarato in manuale con la richiesta della tabella sorgente

## 6. QA — quadrature

| Verifica | Esito |
|---|---|
| Service Desk, totale per codice | **11.908,5 = 11.908,5** (v1.8.87) |
| Somma codici di ogni componente = suo totale | rispettata |
| Filtro su Bressi | 6.565,5 h < totale |
| Relazione IT, somma per codice | **72.034,0 = 72.034,0** |
| **Codice × etichetta = totale** | **72.034,0 = 72.034,0** |
| Righe: solo codice / codice+etichetta | **19 = 19** |
| Filtro su 3 codici | 3.695 ≤ 16.937 |
| 3 codici **AND** da remoto | 598 ≤ 3.695 |
| Chiamate a metodi inesistenti | **0** |
| Avvisi o errori PHP | **0** |

- [x] La quadratura *codice × etichetta* intercetta un `GROUP BY` mal costruito:
      aggiungendo una dimensione funzionalmente dipendente, il numero di righe
      non deve cambiare. Se aumentasse, a uno stesso codice corrisponderebbero
      etichette diverse — un difetto in `cm_contract_models`

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c92` fresco | 603 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c92` | 603 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c92` | 603 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Conteggio statement consolidato: **602 → 603**

## 8. Aperto

- **`project_type` resta inutilizzabile** finché non viene popolato: serve
  l'indicazione della tabella e colonna sorgente nel gestionale.
- Restano gli aperti della v1.8.90: chilometri in attesa degli indirizzi, settore
  tecnologico dipendente dalle assegnazioni, colori in stampa soggetti alla
  preferenza del browser.
