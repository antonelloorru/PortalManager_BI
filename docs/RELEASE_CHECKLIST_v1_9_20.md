# Release Checklist — PortalManager v1.9.20

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.19.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.20` |
| `it_service.php` | ROOT, **corretto** | OK |
| `app/Version.php` | modificato | OK |
| 30 file restanti | invariati da v1.9.19 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.20**

## 2. Il difetto

- [x] Assegnazioni nel ramo **`catch`** invece che nel **`try`**
- [x] Percorso normale: variabili **mai assegnate**, avviso a ogni riferimento
- [x] Percorso di errore: **rifaceva le query appena fallite**
- [x] Il catch ora **azzera**: se il caricamento non è riuscito, rifare le stesse
      interrogazioni non può riuscire

## 3. Perché il collaudo non l'aveva visto

- [x] `php -l` non lo vede: l'assegnazione nel catch è sintatticamente valida
- [x] **Il collaudo interrogava i metodi del modello, non il blocco di
      caricamento della pagina**
- [x] I metodi funzionavano: è per questo che tutte le quadrature tornavano
- [x] **Secondo difetto in due release nello stesso punto** — dopo `$mo` invece
      di `$it` nella v1.9.17

## 4. I due controlli aggiunti

**Statico** — ogni variabile del template definita in entrambi i rami:

| Pagina | Solo nel try | Solo nel catch |
|---|---|---|
| `it_service.php` | — | — |
| `service_desk.php` | — | — |
| `dir_report.php` | — | — |

**Dinamico** — il blocco `try/catch` estratto dal sorgente ed eseguito con gli
avvisi trasformati in eccezioni.

- [x] Un `$gOp` non definito diventa un **errore visibile** invece di una riga
      nel log

## 5. QA

| Verifica | Esito |
|---|---|
| Avvisi durante il caricamento | **0** |
| Variabili del template definite | **22 su 22** |
| Il catch non ricalcola | OK |
| Variabili solo nel catch, 3 pagine | **0** |
| `php -l` su tutti i file | OK |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| **Consolidato completo** | **756 stmt, err=0** |
| `;` nei commenti SQL | **0** (due intercettati) |

## 6. Aperto

- **Gli avvisi PHP in produzione**: se sono disattivati, un difetto come questo si
  manifesta come un riquadro che non compare, senza traccia nei log. Vale la pena
  verificare la configurazione di `display_errors` e `log_errors`.
- Restano gli aperti precedenti: `fascia_letta_pct` da verificare sui dati reali,
  risincronizzazione dopo la v1.9.12, valorizzazione a costo (`CEH`),
  `workload_overview` e `dgb_activities` non uniformati.
