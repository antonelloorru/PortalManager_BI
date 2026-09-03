# Release Checklist — PortalManager v1.9.2

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.1.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.2` |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.1 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.2**

## 2. L'errore corretto

- [x] Avevo letto «più valore consuntivato» come somma algebrica
- [x] Il foglio di esempi mostra **Margine totale (M) = A − G**: WTS_3814,
      67.126 − 30.000 = 37.126
- [x] Le altre voci sono **orizzonti** — maturato, da maturare, FY — non addendi
- [x] La mia formula C dava 1.303.605 dove il gestionale dice 452.476 su WTS_3201

## 3. L'errore a monte

- [x] **Ho ricostruito un calcolo senza cercare se il risultato esistesse già**
- [x] `margin_total` e `margin_todate` popolati su **1.092 su 1.092**
- [x] Stesso errore evitato nella v1.8.93 con `exec_company_id` e ripetuto qui
- [x] La verifica costava una query; il ricalcolo è costato una release sbagliata
      e un numero comunicato con sicurezza

## 4. Il numero corretto

| Tipo | Diverse | Ricostruito | Gestionale | Scarto |
|---|---|---|---|---|
| Contr. Servizi Scalare | **162/168** | 4.299.557 | 2.429.231 | **−1.870.326** |
| Presidio | 12/50 | 3.763.893 | 4.373.203 | +609.310 |
| Servizio Gestito SOC | 1/16 | 1.174.630 | 1.369.630 | +195.000 |
| **TOTALE** | **194/1.092** | 22.519.194 | **21.223.876** | **−1.295.318** |

- [x] Il margine reale è **inferiore** di 1,3 milioni, non superiore di 5,36
- [x] I numeri della v1.9.1 sono da scartare, ed è dichiarato

## 5. La formula D confermata

- [x] Mia formula D: scarto −1.879.777 · gestionale: −1.870.326
- [x] Coincidono a meno di **9.451 euro su 168 commesse**
- [x] 162 su 168 divergono: su questo tipo «valore meno costi» è sistematicamente
      sbagliato

## 6. Verifica sugli esempi

| Esempio | Foglio | Portale |
|---|---|---|
| WTS_3814 (SOC) | 37.126,00 | **37.126,00** |
| WTS_3925 (GES) | 1.350,00 | **1.350,00** |
| WTS_4100 (PRES) | 32.533,76 | 32.954,96 |
| WTS_4042 (SD) | 20.040,56 | 20.803,09 |

- [x] Le differenze su PRES e SD dipendono dalla **data di riferimento**: foglio
      al 26/08, dump al 19/08

## 7. Quality Assurance SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_real` (1.092 commesse) | 7 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_real` | 7 stmt, **err=0** |
| Migration RUN3 | `pm_real` | 7 stmt, **err=0** |
| Coda del consolidato RUN1 | `pm_real` | 5 stmt, **err=0** |
| Coda RUN2 (idempotenza) | `pm_real` | 5 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `php -l` su tutti i file: OK

## 8. Nota di metodo

Un numero comunicato con sicurezza e sbagliato è peggio di nessun numero.

La v1.9.1 annunciava +5,36 milioni con tabelle, percentuali e concentrazione per
tipo — tutto costruito su una lettura errata di una riga di documento, e nessuna
delle verifiche interne poteva rilevarlo perché confrontavo il mio calcolo con sé
stesso.

Il controllo che mancava era il più semplice: **confrontare con il dato che il
gestionale già forniva**.

## 9. Aperto

- **Le viste dei pannelli usano ancora `value_total - actual_cost`**: sostituirle
  con `margin_total` è ora una modifica giustificata dai dati, da fare dopo
  verifica.
- La **base di costo** della tabella di riferimento resta rilevante per il lavoro
  sulla redditività a costo reale (v1.8.97): il full cost si applica a 10 linee
  su 20.
- Restano gli aperti precedenti: pagine mancanti, riepiloghi cadenzati, copertura
  dei costi consolidati al 22,5%.
