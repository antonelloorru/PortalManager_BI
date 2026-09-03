# Release Checklist — PortalManager v1.8.74

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.73.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.74` |
| `app/SyncDatasets.php` | **modificato** (15 dataset) | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.73 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.74**

## 2. Verifica delle 15 voci del menu

- [x] Analizzate tutte le pagine e le tabelle lette da ciascuna
- [x] Tabelle classificate in **tre** categorie, non due

| Categoria | Tabelle | Trattamento |
|---|---|---|
| dato del gestionale | **15** | sincronizzato |
| decisione del portale | **8** | non sincronizzato, deliberato |
| registro tecnico | 1 | né l'uno né l'altro |

- [x] `v_cm_copertura_sync` rende la classificazione **esplicita**: dallo schema
      «manca» e «non deve esserci» sono indistinguibili

## 3. Il difetto: `clients`

| | Prima | Gestionale |
|---|---|---|
| Clienti | **305** | 338 |
| Con partita IVA | **0** | 137 |

- [x] Era popolata **per derivazione** dal testo dei rapporti: solo il nome, e
      solo per i clienti con interventi
- [x] Ora dataset da `forms_company`, filtrata su aziende cliente o esecutrici
- [x] Query validata: **7 colonne, mappatura allineata**
- [x] Dataset totali: **14 → 15**
- [x] Ordine: `clienti` in testa dopo `divisioni`, perché commesse e rapporti vi
      si agganciano

## 4. Sette duplicati nella sorgente

- [x] Sette aziende registrate **due volte** con lo stesso nome, tipicamente una
      con partita IVA e una senza
- [x] Chiave sul **nome**: `clients` non ha colonna per l'identificativo del
      gestionale, e la riconciliazione con le 305 righe esistenti può avvenire
      solo così
- [x] Aggregate per nome con `MAX(NULLIF(TRIM(…),''))`: tiene il valore più
      informativo di ciascun campo
- [x] Risultato: **338 → 331 righe, 0 duplicate**
- [x] Verificato che FIUMICINO LOGISTICA conservi `09706451003`, che una scelta
      arbitraria avrebbe perso nel 50% dei casi

## 5. Anagrafiche interne protette

| Tabella | Motivo |
|---|---|
| `cm_tech_units`, `cm_tech_subunits` | tassonomia del portale, assegnata a mano |
| `cm_tech_profiles`, `cm_tech_history` | assegnazioni e storico del portale |
| `cm_rate_bands` + 2 collegate | fasce definite dall'azienda |
| `employees` | contratti e retribuzioni non esistono nel gestionale |
| `cm_import_batches` | registro tecnico |

- [x] Sovrascriverle svuoterebbe le assegnazioni: il gestionale non conosce le
      unità organizzative del portale, e le sue divisioni sono un'altra cosa
      (verificato in v1.8.72)
- [x] **Il legame resta**: `cm_tech_profiles` punta a `employees` o
      `cm_professionals`, entrambi allineati. L'identità viene dal gestionale, la
      classificazione resta del portale

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 7 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 7 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 7 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c74` fresco | 514 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c74` | 514 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c74` | 514 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **509 → 514**

## 7. Nota di metodo

Una richiesta di copertura totale — «tutto deve derivare dalla sincronizzazione»
— si applica bene solo dopo aver distinto che cosa è dato della sorgente e che
cosa è decisione del portale.

Applicarla alla lettera avrebbe svuotato le assegnazioni di unità organizzativa e
le fasce di costo: lavoro manuale non ricostruibile, sostituito da NULL perché
nella sorgente non esiste nulla da mettervi.

## 8. Aperto

- **Il meccanismo per derivazione di `clients` resta attivo** come rete di
  sicurezza per clienti che compaiano in un rapporto prima di essere anagrafati.
  Dopo qualche sincronizzazione vale la pena verificare se produca ancora righe:
  se sì, sono clienti presenti nei rapporti ma non in `forms_company`.
- `DgbSync` resta nel codice ma non è più necessario (v1.8.73).
- L'elenco di `v_cm_sync_schema_check` è ancora cablato: andrebbe derivato da
  `SyncDatasets::keys()`.
