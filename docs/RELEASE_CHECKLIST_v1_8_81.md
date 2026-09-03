# Release Checklist — PortalManager v1.8.81

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.80.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.81` |
| `workload_overview.php` | ROOT, **NUOVO nel pacchetto** | OK |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/DgbModel.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 4 in `app/` | invariati da v1.8.80 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.81**

## 2. Punto 1 — serie temporali con toggle

- [x] Quattro serie: Ferie, Permessi, Recupero ore, **Visite**
- [x] **Palette separata** da quella delle risorse: colori vicini
      suggerirebbero un confronto privo di significato
- [x] Toggle su gruppo SVG: nessun ridisegno, nessuna richiesta al server
- [x] Totale ore per serie nel pulsante
- [x] **`$yAt` ridefinita dopo** l'aggiornamento di `$yMax`: le closure PHP
      catturano il valore, non un riferimento — senza, le assenze uscirebbero
      dal grafico

## 3. Le "visite" non esistono come tipo

| Tipo | Impegni | Ore |
|---|---|---|
| LEAVE | 95 | 304,5 |
| RECOVERY | 47 | 141,5 |
| REMINDER | 19 | 37,0 |
| HOLIDAY | 11 | 89,0 |
| MEETING | 1 | 1,0 |
| **totale** | **173** | **573,0** |

- [x] Serie costruita sulla **descrizione**, non su un tipo
- [x] **Trasversale**: le ore sono già contate nelle altre serie
- [x] Resa **tratteggiata** e dichiarata in legenda e documentazione
- [x] Scartata la riclassificazione dei 173 impegni: tornerebbero come sono alla
      prima sincronizzazione

## 4. Punto 2 — assenze per tecnico

| | Luglio 2026 |
|---|---|
| Senza filtro | **1.660,0 h** |
| Operatore 2429 | **130,5 h** su 19 giorni |

- [x] Filtro attivo e verificato
- [x] Container allineato agli altri grafici: `width:100%` con
      `table-layout:fixed`, così un mese di 28 giorni occupa la stessa larghezza
      di uno di 31

## 5. Difetto intercettato in collaudo

- [x] Primo tentativo su `operator_name LIKE`: **non funzionava**
- [x] `normFilters()` normalizza `operator` a **intero** (id, non nome)
- [x] Il `catch` del blocco assenze **inghiottiva** l'errore: zero assenze in
      ogni caso, senza messaggio
- [x] Corretto con `operator_id`, che è anche il legame giusto: i nomi sono
      esposti alle differenze di forma fra gestionale e anagrafica (v1.8.77)
- [x] Annotato: un `catch` largo trasforma un errore in un dato mancante, e un
      dato mancante somiglia a un dato assente

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c81` fresco | 559 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c81` | 559 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c81` | 559 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **553 → 559**

## 7. Aperto

- **Le visite restano una categoria per convenzione di scrittura.** Il
  riconoscimento è su `description REGEXP '[Vv]isit'`: se qualcuno scrivesse
  «controllo medico» non verrebbe riconosciuto. Una categoria vera richiede un
  tipo nel gestionale.
- Il toggle non **persiste** fra un caricamento e l'altro: chi nasconde le ferie
  le ritrova al ricaricamento. Salvare lo stato richiederebbe un parametro
  nell'URL o una preferenza utente.
