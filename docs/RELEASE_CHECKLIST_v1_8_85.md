# Release Checklist — PortalManager v1.8.85

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.84.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.85` |
| `service_desk.php` | ROOT, **corretto** | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 8 in `app/` | invariati da v1.8.84 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.85**

## 2. Il difetto

- [x] `Router::hiddenParams()` **non esiste**: Router espone `slug()`, `url()`,
      `resolve()`, `isRoutable()`, `isRestricted()`
- [x] Funzione corretta: **`route_slug_field()`** in `app/UrlHelper.php`, usata da
      tutte le altre pagine con form GET
- [x] Errore **fatale**: la pagina non si apriva affatto

## 3. Perché non era stato intercettato

- [x] `php -l` **non può rilevarlo**: una chiamata a metodo statico inesistente è
      sintatticamente valida, PHP verifica solo all'invocazione
- [x] Il collaudo della v1.8.84 verificava **il modello**, non il template: il
      form dei filtri sta nella parte HTML, che non veniva attraversata
- [x] Dava **zero errori** su una pagina che non si apriva: un controllo che copre
      il 90% e non il 10% con l'errore è peggio di nessun controllo

## 4. Il controllo aggiunto

- [x] Per ogni `Classe::metodo()` cerca la definizione in `app/Classe.php`
- [x] **Spoglio dei commenti** necessario: il commento che spiega la correzione
      cita il metodo inesistente, e senza rimuoverlo il controllo segnalerebbe un
      difetto già risolto — come accaduto con `$NAT` nella v1.8.72
- [x] Classi non nel pacchetto saltate: invariate sul server
- [x] Eseguito su **tutte** le pagine della release, non solo su quella corretta

| Verifica | Esito |
|---|---|
| Metodi statici inesistenti, tutte le pagine | **0** |
| Metodi statici invocati in `service_desk.php` | **nessuno** |
| `route_slug_field()` risolta | `app/UrlHelper.php` |

## 5. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c85` fresco | 575 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c85` | 575 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c85` | 575 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **574 → 575**

## 6. Nota di metodo

L'errore nasce dall'aver scritto una chiamata *plausibile* invece di verificare
l'interfaccia esistente. `Router` gestisce le rotte, quindi un metodo per i
parametri nascosti sembrava ragionevole.

Avevo aperto `workload_overview.php` per copiarne l'impalcatura, ma non per questo
dettaglio: bastava guardare due righe più in basso.

**Regola operativa**: quando serve un'utilità che le pagine esistenti già usano,
il primo posto da guardare è una pagina esistente — non il nome che sembra giusto.

## 7. Aperto

- Il collaudo del rendering dovrebbe attraversare anche la parte HTML delle
  pagine, non solo il modello. Richiede di simulare sessione e permessi, ed è il
  motivo per cui finora non lo faccio.
- Restano aperti dalla v1.8.84: SLA non definiti, `tt_ticket` e `tt_ticket_act`
  non esportate, durata comprensiva delle attese del cliente.
