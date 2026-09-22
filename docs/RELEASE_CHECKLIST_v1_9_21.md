# Release Checklist — PortalManager v1.9.21

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.20.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.21` |
| `system_errors.php` | ROOT, **NUOVA** | OK |
| `app/Router.php` | **modificato** | OK |
| `app/MenuManager.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 28 file restanti | invariati da v1.9.20 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.21**
- [x] Pagina registrata in **Router** e **MenuManager**

## 2. Riservata al super admin

- [x] `role_id !== 1` → `unauthorized.php`, stesso presidio della console
- [x] Il registro contiene percorsi, frammenti di query e valori dei parametri:
      **aiuta un attaccante tanto quanto chi ripara**

## 3. Solo in lettura

- [x] `display_errors` e `log_errors` **in molte installazioni non sono
      modificabili a runtime**
- [x] Un interruttore che a volte non funziona è peggio di nessun interruttore
- [x] Mostra le righe di `php.ini` con il percorso già inserito

## 4. Quattro avvertenze automatiche

- [x] Errori né mostrati né registrati → **rossa**
- [x] `log_errors` disattivato → gialla
- [x] `display_errors` attivo → gialla, con la ragione
- [x] `E_WARNING` fuori dal livello → **«Undefined variable» non viene segnalato
      né a schermo né nel log**

## 5. Lettura dalla coda

- [x] Ultimi **512 KB**, non l'intero file
- [x] **Prima riga scartata** quando il taglio la rende incompleta
- [x] Verificato: con taglio a 200 byte, 6 righe tutte complete

## 6. QA — 18 casi

| Gruppo | Esito |
|---|---|
| Classificazione | **6 su 6** |
| Coda: prima riga = ultima del file | OK |
| Taglio a metà riga | scartata |
| File inesistente / vuoto | array vuoto |
| Conversione flag | **8 su 8** |

- [x] **`(bool)'Off'` sarebbe `true`**: alcune installazioni scrivono `Off` nel
      `php.ini`, ed è il motivo della conversione a mano invece del cast

## 7. Altri controlli

| Verifica | Esito |
|---|---|
| `if`/`endif` | **12 = 12** |
| `foreach`/`endforeach` | **3 = 3** |
| `<div>` | **25 = 25** |
| Variabili solo nel catch, 3 pagine | **0** |
| `php -l` su tutti i file | OK |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| **Consolidato completo** | **757 stmt, err=0** |
| `;` nei commenti SQL | **0** |

## 8. Aperto

- **La pagina non crea la cartella dei log**: se impostate `error_log` su un
  percorso inesistente, PHP scrive nel log del server web senza segnalarlo. La
  pagina lo mostra come «file individuato: nessuno».
- **Verifica a schermo non eseguita**: le funzioni sono collaudate sui casi
  limite, la resa no.
- Restano gli aperti precedenti: `fascia_letta_pct` da verificare sui dati reali,
  risincronizzazione dopo la v1.9.12, valorizzazione a costo (`CEH`).
