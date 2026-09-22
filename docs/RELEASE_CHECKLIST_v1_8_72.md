# Release Checklist — PortalManager v1.8.72

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.71.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.72` |
| `dgb_activities.php` | ROOT, **corretto** | OK |
| `app/SyncDatasets.php` | **modificato** (divisione) | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 5 in `app/` | invariati da v1.8.71 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.72**

## 2. Correzione dell'avviso PHP

- [x] `$NAT` dichiarato **dopo** la legenda che lo usa: riga 878 uso, 910
      definizione
- [x] Non era un difetto sintattico — `php -l` passava — ma di **ordine di
      esecuzione**
- [x] Invisibile in collaudo con `display_errors = Off`: la legenda non compare e
      basta
- [x] **Stessa causa del secondo sintomo**: con `display_errors = On` l'avviso è
      stampato prima delle intestazioni HTTP e corrompe l'export
- [x] Definizioni (`$NAT`, `$oreOrd`, `$cella`) spostate in testa al riquadro
- [x] Rimosso codice residuo della v1.8.68 (`$totOrd`, `$totRep`, `$oreOrdFasce`)

## 3. QA — esecuzione con avvisi convertiti in eccezioni

Il blocco è stato **estratto dal file di release** ed eseguito con
`set_error_handler` che solleva su ogni avviso.

| Verifica | Esito |
|---|---|
| Avvisi o errori PHP | **0** |
| Voci di legenda prodotte | **4** |
| Cella di prova | colore `#f8eedf`, natura `cli_rep`, 0,85 h |
| Ordine definizione/uso in codice | **OK** per `$NAT`, `$cella`, `$oreOrd` |

- [x] Il controllo d'ordine distingue i riferimenti in **commento** da quelli in
      codice: i commenti che spiegano il difetto citano la variabile prima

## 4. Punto 2 — il legame divisione/tecnico non esiste

| Divisioni per operatore | Operatori |
|---|---|
| 2 | 69 |
| 4 | 136 |
| 6 | 16 |
| 8+ | 23 |

- [x] `dgb_operator_can_see_forms_division` è un **permesso di visibilità**:
      nessun operatore ha una sola divisione, minimo 2, media 4
- [x] Usarla come appartenenza avrebbe **moltiplicato ogni ora per quattro**
- [x] Il legame dichiarato è `forms_contract.id_division`, valorizzato su
      **tutte le 808** commesse

## 5. QA — divisione sulle commesse

| Divisione | Commesse (sorgente) |
|---|---|
| Sistemistica | 1.358 |
| NIS | 68 |
| ANT | 46 |
| Assistenza Tecnica | 14 |
| WeSecure | 8 |

- [x] Query del dataset `commesse`: **31 colonne, mappatura allineata**
- [x] `division_code` su `cm_projects` con indice

Vista `v_cm_divisione_analisi` sui dati reali:

| Divisione | Commesse | Ore | Costo | Margine | €/h |
|---|---|---|---|---|---|
| Sistemistica | 679 | 305.152 | 9.231.365 | 64,7% | 30,25 |
| (non assegnata) | 315 | 25.047 | 712.972 | 89,7% | 28,47 |
| ANT | 23 | 5.874 | 189.726 | 60,4% | 32,30 |
| NIS | 34 | 2.195 | 26.697 | 72,1% | 12,17 |
| WeSecure | 4 | 113 | 4.446 | 99,2% | 39,35 |
| Assistenza Tecnica | 7 | 24 | 825 | −83,3% | 34,38 |

- [x] Join con `<=>` null-safe: senza, le 315 commesse non assegnate
      sparirebbero dalla vista invece di comparire aggregate
- [x] Assistenza Tecnica in negativo su 24 ore e 450 € di valore: caso singolo,
      dichiarato come tale e non come problema strutturale

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 7 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 7 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 7 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c72` fresco | 502 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c72` | 502 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c72` | 502 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **497 → 502**

## 7. Nota di metodo

Due sintomi apparentemente scollegati — avvisi a video ed export non funzionante
— avevano **una sola causa**. Cercare la seconda causa separatamente avrebbe
fatto perdere tempo su un difetto che non esisteva.

L'avviso PHP non è cosmetico quando `display_errors` è attivo: qualunque output
prima delle intestazioni rompe ogni download della pagina.

## 8. Aperto

- **Tre divisioni senza commesse** — Laboratorio, WENEST, WeEnengys: da chiarire
  se dismesse, nuove, o operanti su commesse attribuite altrove. Non è un difetto
  dei dati ma una domanda per chi conosce l'organizzazione.
- **315 commesse senza divisione**: sono quelle non ancora riconciliate con il
  gestionale, dovrebbero ridursi dopo la sincronizzazione.
- **Export immagine** dei grafici: resta non realizzato. Il grafico a colonne è
  già SVG; la matrice è HTML e richiederebbe un secondo generatore da mantenere
  allineato.
