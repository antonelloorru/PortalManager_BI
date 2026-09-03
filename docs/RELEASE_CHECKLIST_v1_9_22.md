# Release Checklist — PortalManager v1.9.22

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.21.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.22` |
| `app/Version.php` | modificato | OK |
| 32 file restanti | invariati da v1.9.21 | OK |

- [x] **Nessun file applicativo modificato**
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.22**

## 2. La relazione corretta

- [x] La richiesta la descriveva **al contrario**
- [x] `main_order.code` = `operation.order_code`, non il viceversa
- [x] `forms_contract_operation` **non ha** una colonna `code`

## 3. I dati

| | |
|---|---|
| Ordinativi | **896** |
| Operazioni | 1.186 |
| Commesse | 878 |
| Importo totale | **36.620.926 €** |
| Su più commesse | 135 |
| Con codici multipli | **15** |

## 4. La regola riscritta durante il collaudo

- [x] La prima versione cercava separatori espliciti
- [x] Il collaudo ha trovato **`C2501 C2500 C2499 C1401`**: quattro codici
      separati da soli spazi, che passavano per singoli
- [x] Criterio ora **strutturale**: due o più sequenze lettera+cifre
- [x] Corregge **entrambi** i casi: riconosce i separati da spazi e smette di
      segnalare `Ordine A2245 - FT 1921/H`, che è un codice solo

## 5. La validazione senza termine di confronto

- [x] `forms_contract_main_order` **vuota nel dump**
- [x] `esito_validazione` ha **tre** valori: il terzo è «totale non disponibile»
- [x] Trattarlo come scostamento avrebbe reso **896 ordinativi su 896 anomali**
- [x] `cm_pratix_orders` pronta ad accogliere il totale

## 6. Le celle multiple segnalate, non divise

- [x] Dividerle richiederebbe di ripartire l'importo fra i codici
- [x] Ogni scelta — parti uguali? tutto al primo? — produrrebbe **numeri precisi
      e inventati**

## 7. Tre difetti SQL intercettati

- [x] **`l''importo`**: l'apostrofo raddoppiato spezza lo splitter naive
- [x] **`[[:space:]]`**: la classe POSIX contiene un `;` letto come fine
      istruzione
- [x] **`INSERT IGNORE` sulla regex**: la regola migliorata non si applicava dove
      il parametro esisteva già. Ora `ON DUPLICATE KEY UPDATE` sulla regex, che è
      una regola; `INSERT IGNORE` sulla tolleranza, che è una scelta

## 8. QA

| Verifica | Esito |
|---|---|
| Quadro sui dati reali | 896 / 36.620.926 € |
| Classificazione codici multipli | **4 casi su 4** |
| Migration RUN1/RUN2/RUN3 | 11 stmt, **err=0** |
| **Consolidato completo** | **766 stmt, err=0** |
| `;` nei commenti SQL | **0** |

## 9. Aperto

- **La pagina non è inclusa**: le viste sono verificate, il riquadro si costruisce
  su queste.
- **Il totale dichiarato non è sincronizzato**: serve un dataset per
  `forms_contract_main_order`, senza il quale la validazione resta impossibile.
- **Gli importi sono quasi tutti previsti** e non consolidati: il totale di
  36,6 M€ è una previsione.
- Restano gli aperti precedenti: `fascia_letta_pct`, risincronizzazione dopo la
  v1.9.12, valorizzazione a costo (`CEH`).
