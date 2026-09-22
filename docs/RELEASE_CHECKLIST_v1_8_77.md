# Release Checklist — PortalManager v1.8.77

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.76.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.77` |
| `app/DatasetSync.php` | **modificato** — risoluzione tecnico | OK |
| `app/SyncDatasets.php` | **modificato** — `link_technician` | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 4 in `app/` | invariati da v1.8.76 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.77**
- [x] **Release che modifica dati**: 69.074 righe aggiornate, backup dichiarato

## 2. Indagine

- [x] Caso segnalato riprodotto: Nushi Irni / WTS_3670 → **34 moduli, 66 ore**,
      settembre 2025 – giugno 2026
- [x] **Non isolato**: su **69.074 rapporti su 69.074** (100%) sia
      `technician_id` sia `technician_professional_id` erano NULL
- [x] 146 tecnici distinti coinvolti, 344.558 ore
- [x] Causa: `DatasetSync` risolveva `link_project` ma **non il tecnico** — la
      risoluzione non era mai stata scritta
- [x] Sintomo confuso perché due famiglie di pagine leggono campi diversi:
      i moduli il testo, i prospetti l'identificativo

## 3. L'anagrafica era già a posto

```
cm_professionals  id=179  Nushi Irni   employee_id=86  match_type=name_swapped
employees         id=86   Irni Nushi
```

- [x] Collegamento professionista → dipendente **già esistente**
- [x] Inversione nome/cognome **già rilevata** dalla riconciliazione
- [x] Mancava solo di riportare il riferimento sul fatto

## 4. Risoluzione in tre passaggi

- [x] nome esatto *Nome Cognome*
- [x] nome esatto *Cognome Nome* — necessario per l'inversione
- [x] sigla, quando presente
- [x] **Nessuna corrispondenza approssimata**: un rapporto attribuito alla
      persona sbagliata sposta ore e costi su chi non li ha sostenuti, e il
      totale complessivo resta giusto quindi nessuno se ne accorge
- [x] Il riferimento al dipendente è **riportato** dall'anagrafica, non
      ricalcolato: una riconciliazione fatta a mano non viene scavalcata

## 5. QA — esecuzione sul database reale del cliente

| | Prima | Dopo |
|---|---|---|
| Rapporti | 69.074 | 69.074 |
| Con professionista | **0** | **69.074** |
| Con dipendente | **0** | 66.939 |
| **Scollegati** | **69.074** | **0** |
| `v_cm_tecnici_scollegati` | — | **0 righe** |

Caso segnalato dopo la migration:

| Verifica | Esito |
|---|---|
| Nushi Irni / WTS_3670 | 34 moduli, 66 ore |
| `technician_id` | **86** (corretto) |
| `technician_professional_id` | **179** (corretto) |

- [x] I 2.135 rapporti senza `technician_id` hanno il professionista: persone non
      ancora riconciliate, **restano visibili** nei prospetti

## 6. Correzione su due fronti

- [x] **Migration**: ripara i 69.074 rapporti esistenti senza attendere una
      risincronizzazione
- [x] **`DatasetSync`**: risolve il tecnico a ogni sincronizzazione, così il
      problema non si ricrea
- [x] Cache dei nomi: 146 tecnici distinti su 69.000 rapporti — senza, fino a
      138.000 query per 146 risposte
- [x] `array_key_exists` e non `isset`: un nome cercato e **non** trovato deve
      restare in cache, altrimenti si ricerca a ogni riga nel caso peggiore
- [x] Indice su `technician_raw` creato per primo: senza, ogni `UPDATE ... JOIN`
      è una scansione completa di 69.074 righe

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_c77` fresco | 11 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_c77` | 11 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_c77` | 11 stmt, **err=0** |
| Migration su **DB reale** | tokenizer reale | `pm_i` | 69.074 righe collegate |
| Consolidato RUN1 | splitter naive | `pm_c77b` fresco | 530 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c77b` | 530 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c77b` | 530 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **521 → 530**

## 8. Nota di metodo

La segnalazione riguardava una persona su una commessa. L'indagine ha mostrato
che riguardava **il 100% dei rapporti**.

Verificare l'ampiezza prima di correggere ha cambiato la natura dell'intervento:
da una riparazione puntuale su un record a una parte mancante della
sincronizzazione. Correggere solo il caso segnalato avrebbe lasciato 145 tecnici
nella stessa condizione.

## 9. Aperto

- **2.135 rapporti** hanno il professionista ma non il dipendente: riconciliarli
  in *Anagrafica Tecnica* completerebbe il quadro. Non urgente, restano visibili.
- La risoluzione avviene **per nome**, che è l'unico dato che la sorgente porta
  sul rapporto. Se il gestionale esponesse l'identificativo dell'operatore sul
  rapporto, il collegamento sarebbe esatto per costruzione: vale la pena
  verificare se `forms_activity_has_dgb_operator.id_operator` possa essere
  propagato fino a `cm_intervention_reports`.
