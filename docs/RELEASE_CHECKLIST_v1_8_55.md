# Release Checklist — PortalManager v1.8.55

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.54.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.55` |
| `tech_registry.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| altri 5 ROOT + 7 in `app/` | invariati da v1.8.54 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.55**
- [x] Release additiva: l'aggiornamento non modifica dati, le modifiche ai
      profili avvengono solo lanciando la funzione

## 2. Soglia calibrata sui dati, non scelta a priori

| Giornate in reperibilità | Tecnici |
|---|---|
| 1 | 5 |
| 2–3 | 9 |
| 4–9 | **5** ← zona quasi vuota |
| 10 o più | 124 (media 252, max 837) |

- [x] Distribuzione **bimodale**: due popolazioni distinte separate da una valle
- [x] Soglia predefinita 5 giornate, collocata nella valle
- [x] Finestra 12 mesi: evita che ruoli cessati risultino attivi
- [x] Soglia H24 su 5 giornate notturne o festive: 15 tecnici con ≥5 notti,
      30 con ≥5 festive
- [x] La misura è riportata nella migration perché chi rivedrà la soglia possa
      verificare che la valle esista ancora

## 3. QA — proposta sui dati reali

| Verifica | Esito |
|---|---|
| Tecnici con evidenze | 111 |
| Proposti reperibili | **103** |
| Proposti anche H24 | **23** |
| Senza corrispondenza in anagrafica | **0** |

## 4. QA — funzione di applicazione

| Verifica | Esito |
|---|---|
| Prima esecuzione | **103 creati**, 0 aggiornati, 0 saltati |
| Profili con reperibilità / H24 | 103 / 23 |
| **Idempotenza** (seconda esecuzione) | 0 creati, 0 aggiornati, 103 già corretti |
| Totale profili invariato | 103 → 103 |
| Senza creazione profili | 0 creati, 103 saltati — **OK** |

## 5. QA — precedenza dell'identità interna

| Verifica | Esito |
|---|---|
| Tecnici con **entrambi** gli identificativi | **95** su 103 |
| Profili con entrambe le colonne valorizzate | **0** (atteso 0) |
| Profili interni / esterni | 95 / 8 |

- [x] `cm_tech_profiles` ammette una sola identità: la precedenza all'interna
      evita la violazione dei vincoli UNIQUE

## 6. QA — il flag non viene mai rimosso

- [x] Tecnico **sotto soglia** marcato a mano come reperibile e H24: dopo
      l'esecuzione della funzione entrambi i flag **conservati**
- [x] H24 alzato ma mai abbassato (`max()` sul valore precedente)
- [x] Motivazione: l'assenza di evidenze non è evidenza di assenza — un tecnico
      di turno senza chiamate non lascia traccia nei consuntivi

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c55` fresco | 399 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c55` | 399 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c55` | 399 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Un `;` in un commento aveva fatto fallire il primo tentativo: corretto
- [x] Conteggio statement consolidato: **393 → 399**

## 8. Scelte di progetto

- [x] **Proposta e non automatismo**: il consuntivo vede solo gli interventi
      eseguiti, non i turni assegnati. Un flag automatico sovrascriverebbe le
      decisioni di chi conosce l'organizzazione
- [x] **Creazione dei profili mancanti** opzionale ma predefinita: senza, alla
      prima esecuzione — quella in cui serve di più — non applicherebbe nulla
- [x] **Viste e non tabella di stato**: le evidenze si ricalcolano a ogni
      apertura, così non esiste finestra di disallineamento
- [x] Elenco limitato a 200 righe, ordinato per giornate decrescenti
- [x] Ogni esecuzione tracciata nell'event log

## 9. Documentazione

- [x] `CHANGELOG.md` — soglia calibrata, risultati, motivazione della proposta
- [x] `TECHNICAL_DESIGN_v1_8_55.md` — bimodalità, finestra temporale, due ponti
      verso l'anagrafica, precedenza, irreversibilità del flag
- [x] `DEPLOYMENT_v1_8_55.md` — prerequisiti, prima esecuzione, cosa non fa
- [x] `MANUALE_ADMIN_v1_8_55.md`, `MANUALE_UTENTE_v1_8_55.md`
- [x] `RELEASE_CHECKLIST_v1_8_55.md` — questo documento

## 10. Aperto

- Restano 8 tecnici con evidenze sotto soglia e senza profilo: è corretto, non
  sono reperibili ricorrenti. Compariranno se la loro attività aumenterà.
- La funzione popola solo i flag di reperibilità. Unità organizzativa, seniority e
  competenze restano da assegnare a mano: sono informazioni che i consuntivi non
  contengono e nessun automatismo può inferire.
