# Release Checklist — PortalManager v1.8.69

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.68.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.69` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/DgbModel.php` | **modificato** | OK |
| `app/SyncDatasets.php` | **modificato** (12° dataset) | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 4 in `app/` | invariati da v1.8.68 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.69**

## 2. Correzione di una mia dichiarazione errata

- [x] Nella v1.8.68 avevo dichiarato ferie e permessi **assenti dai dati**
- [x] Erano in `forms_commitment`, come **valori** del campo `type`
- [x] Stesso errore di metodo della v1.8.60 (costo direzionale, valore di
      `forms_contract_operation_type`)
- [x] Regola derivata: le tabelle di classificazione vanno esplorate leggendone i
      **contenuti**, non cercando fra i nomi di struttura

## 3. QA — dataset assenze

| Verifica | Esito |
|---|---|
| Colonne / mappatura | 10, **allineata** |
| Righe prodotte | **13.086** |
| Dataset totali | **12** su **18 tabelle** |

| Tipo | Righe | Ore | Operatori |
|---|---|---|---|
| HOLIDAY | 3.298 | 26.186,0 | 94 |
| REMINDER | 1.366 | 7.881,0 | 52 |
| LEAVE | 833 | 3.442,5 | 79 |
| RECOVERY | 658 | 2.884,0 | 38 |
| SICK_LEAVE | 361 | 2.851,5 | 45 |

- [x] `quantity` è **durata in ore**, non giorni: 7,94 medio per le ferie
- [x] `is_absence` separa le assenze dagli impegni di agenda: senza,
      l'assenteismo includerebbe **7.988 ore** di promemoria e riunioni

## 4. Difetto intercettato in collaudo

- [x] Primo tentativo: **interno = 0%**
- [x] Causa: join su `a.code`, che è il codice dell'**attività**
      (`MEFA_23_000003`), non del contratto
- [x] Corretto: `cm_projects.dgb_contract_id = a.id_contract` — aggancia
      **70.235 attività su 70.238**
- [x] Il difetto non produceva errori, solo un grafico con interno a zero:
      individuato perché implausibile su 76 commesse interne
- [x] Annotato che `COALESCE(has_revenue, 1)` maschera i join falliti

## 5. QA — quattro nature su dati reali (marzo 2026)

| Natura | Ore | Quota |
|---|---|---|
| cliente ordinario | 7.058,0 | 62,6% |
| cliente reperibilità | 1.260,7 | 11,2% |
| **interno ordinario** | **2.510,3** | **22,3%** |
| **interno reperibilità** | 448,4 | 4,0% |
| Totale lavorato | 11.277,3 | 100% |
| Assenze (piano separato) | 835,5 | — |

- [x] Il regime orario resta in PHP e non in SQL: deve applicare la regola del
      fine settimana, e replicarla nella query l'avrebbe duplicata in un terzo
      punto dopo la vista e `FRAC_ORD`
- [x] Cella colorata per **natura prevalente**, ripartizione completa nel
      suggerimento: a 15 px per cella, spicchi o tinte mediate sarebbero
      illeggibili

## 6. QA — assenze e export

| Verifica | Esito |
|---|---|
| Assenze marzo 2026 | 835,5 h su 22 giorni |
| Ferie / malattia / recuperi / permessi | 328,0 / 227,0 / 156,5 / 124,0 |
| XLSX | 17.480 byte, firma `PK` |
| Fogli | celle **744** righe, profilo 21, assenze 63 |
| **Quadratura celle esportate** | 11.277,33 = 11.277,33 **OK** |

- [x] Assenze in banda separata: distribuirle sulle 24 ore darebbe l'impressione
      che si lavori durante le ferie
- [x] Il totale assenze **non si somma** al lavorato, ed è dichiarato
- [x] Export a **tre fogli**: un foglio unico costringerebbe a separarli a mano

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c69` **collation mista** | 489 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c69` | 489 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c69` | 489 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **482 → 489**

## 8. Punti della richiesta

| Richiesta | Stato |
|---|---|
| Metrica 24 ore in vista giorni/mese | fatto (v1.8.67) |
| Colore diverso per attività interne | **fatto** — verde e rosso |
| Tonalità distinta per reperibilità interna/esterna | **fatto** — quattro nature |
| Ferie, permessi, recupero ore | **fatto** — più malattia |
| Filtrabile | i filtri esistenti agiscono su tutto |
| Export XLSX | **fatto** — tre fogli |
| Legenda aggiornata | **fatto** — nature e assenze |
| Export **immagine** | **non fatto** — vedi punto 9 |
| Blocchi orario per modulo | opzione B scelta: matrice arricchita |

## 9. Aperto

- **Export immagine non realizzato.** Il grafico a colonne è già SVG e sarebbe
  immediato; la matrice è HTML e richiederebbe un secondo generatore SVG da
  mantenere allineato al primo. Va deciso se il costo si giustifica, oppure se
  l'export XLSX dei dati sia sufficiente.
- I filtri esistenti (periodo, tecnico, commessa) agiscono già sulla matrice. Un
  filtro **per natura** — mostrare solo l'interno, o solo la reperibilità — non è
  stato aggiunto: con quattro nature già distinte per colore, nasconderne alcune
  toglie il confronto che rende la matrice utile. Se serve, si aggiunge.
- Restano i punti della v1.8.63: collegamento fra divisione e tecnico, ed esame
  delle 137 commesse segnalate solo dal gestionale.
