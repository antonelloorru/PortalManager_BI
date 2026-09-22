# Release Checklist — PortalManager v1.8.89

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.88.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.89` |
| `app/ItServiceModel.php` | **NUOVO** | OK |
| `app/Version.php` | modificato | OK |
| 8 ROOT + 7 in `app/` | invariati da v1.8.88 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.89**
- [x] **Release parziale, dichiarata come tale** in CHANGELOG e deployment

## 2. Le viste

| Vista | Contenuto |
|---|---|
| `v_cm_it_servizio` | **67.723 righe** — un intervento con tutte le dimensioni |
| `v_cm_it_scheda` | aggregato per incaricato × settore × linea |
| `v_cm_it_distanze_mancanti` | coppie sede-cliente da geocodificare |

- [x] 145 incaricati, 21 linee di servizio, 338.403,5 ore
- [x] `technician_id` popolato su **66.614 su 67.723** (v1.8.77)
- [x] Settori = 1 sul DB di prova perché nessun profilo ha unità: **sul server
      del cliente ne sono assegnati 27**

## 3. Modalità esclusiva

| Modalità | Interventi | Ore |
|---|---|---|
| In sede | 45.073 | 253.900,0 |
| Da remoto | 15.896 | 41.858,0 |
| Presso cliente | 5.107 | 35.915,0 |
| Smart working | 1.497 | 6.382,0 |
| In reperibilità | 150 | 348,5 |

- [x] I flag della sorgente **non sono mutuamente esclusivi**: serve un ordine di
      precedenza, dal più specifico al più generico
- [x] Colonne booleane separate avrebbero reso le quote superiori al 100%

## 4. I chilometri — entrambe le strade

- [x] **Strada 1**: `trip_hours` su 5.550 allocazioni, **10.968,5 ore** — dato
      reale, disponibile subito
- [x] **Strada 2**: `cm_it_distances` con vincolo unico su (sede, cliente);
      `lat`, `lng`, `geocoded_at` aggiunte a `clients`
- [x] `v_cm_it_distanze_mancanti` ordinata per numero di interventi: si parte da
      ciò che pesa
- [x] **km NULL e non zero**: con zero, `AVG()` includerebbe le trasferte non
      misurate come distanza nulla
- [x] Copertura esposta come indicatore: **0 su 104** trasferte nel mese di prova
- [x] `source` distingue manuale / geocodifica / stimata

## 5. QA — filtri combinabili

| Filtro | Interventi | Ore |
|---|---|---|
| base 2026 | 16.855 | 71.835,0 |
| remoto **+** smart working | 4.831 | 11.497,5 |
| 3 linee insieme | 3.937 | 13.635,5 |
| 3 linee **AND** da remoto | 613 | 726,0 |

- [x] Semantica: OR dentro la dimensione, AND fra dimensioni
- [x] Coerenza verificata: ogni sottoinsieme contenuto nell'insieme
- [x] Raggruppamento a 3 dimensioni: 10 righe
- [x] `gb` validato contro elenco chiuso: finisce in un `GROUP BY`

## 6. QA — giornate-uomo

| Misura | Luglio 2026 |
|---|---|
| Interventi | 1.756 |
| **Giornate-uomo** | **932** |
| Ore | 6.761,5 |
| **Ore medie per giornata** | **7,25** |

- [x] `COUNT(DISTINCT incaricato|giorno)`, non `COUNT(DISTINCT giorno)`
- [x] Il valore 7,25 è plausibile e conferma il calcolo: con una misura sbagliata
      sarebbe stato assurdo

## 7. Difetti intercettati in collaudo

- [x] `l.name` inesistente: la colonna è **`location_name`** — due occorrenze,
      SELECT e GROUP BY
- [x] `p.customer_name` inesistente: le commesse hanno `client_id` e `client_raw`
- [x] Un `;` in un commento SQL
- [x] Tutti corretti; entrambi gli splitter a **err=0**

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` (dati reali) | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c89` fresco | 595 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c89` | 595 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c89` | 595 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **588 → 595**

## 9. Che cosa NON contiene questa release

Dichiarato apertamente:

- **La pagina** `it_service.php` con filtri, grafici, export XLSX e pivot, PDF
- **La stampa a colori con i grafici** del Service Desk
- **Il servizio di geocodifica** che popola `cm_it_distances`

Il modello espone già tutto il necessario: `totali()`, `aggrega()`,
`perDimensione()`, `andamento()`, `valori()`, `statoKm()`, `distanzeMancanti()`.

## 10. Aperto

- **Gli indirizzi sono scarsi**: 10 sedi su 169, e i clienti li avranno solo dopo
  la sincronizzazione della v1.8.74. È il prerequisito della geocodifica.
- **Il servizio di mappe va scelto**: la chiave API e il fornitore sono una
  decisione che non potevo prendere.
- Le assegnazioni alle unità organizzative sono 27 su 104 profili: più ne vengono
  fatte, più la ripartizione per settore diventa significativa.
