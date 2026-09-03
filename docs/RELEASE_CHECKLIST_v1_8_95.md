# Release Checklist — PortalManager v1.8.95

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.94.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.95` |
| `cron_alerts.php` | ROOT, **NUOVO** | OK |
| `dir_report.php` | ROOT, modificato — pannello | OK |
| `app/AlertEngine.php` | **NUOVO** | OK |
| `app/SmtpMailer.php` | invariato, incluso | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 8 in `app/` | invariati da v1.8.94 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.95**
- [x] `cron_alerts.php` **non** in `Router::PAGES`: è CLI, protetto da
      `PHP_SAPI !== 'cli'` → 403

## 2. Riuso del canale esistente

- [x] `SmtpMailer` già configurato e verificato (Aruba, 465, SSL)
- [x] Tutti e otto i metodi invocati **verificati contro il file reale**:
      `configure`, `from`, `to`, `cc`, `subject`, `bodyHtml`, `bodyText`, `send`
- [x] Un secondo canale avrebbe significato due configurazioni: al primo cambio
      di password una resterebbe indietro
- [x] **Alias** distinto da `mail_from`, con ripiego su di esso se vuoto

## 3. Soglie concordate, in tabella

| Regola | Attenzione | Allarme |
|---|---|---|
| Consumo budget | 75% | 90% |
| Sforato | — | >100% |
| Margine | — | <20% |
| Divergenza | 20 pt | 35 pt |
| Scadenza | 30 gg | 7 gg |
| Fermo | 90 gg | 180 gg |

- [x] In `cm_alert_rules`, **non nel codice**: valori aziendali
- [x] `INSERT IGNORE`: un aggiornamento non riporta le soglie ai valori di fabbrica
- [x] **434 condizioni rilevabili** sui dati reali

## 4. Destinatari

- [x] Agente → solo le sue; direttore → tutte; **copia per singolo agente**
- [x] La copia in `cm_alert_recipients` e non nelle regole: nelle regole
      servirebbero **270 righe** (45 agenti × 6 regole) per 45 preferenze
- [x] `agent_name` testuale: `commercial_ref` è un nome, non esiste un'anagrafica

## 5. Tre tabelle — verificato il caso che conta

| Verifica | Esito |
|---|---|
| SMTP irraggiungibile: errori tracciati | **4** |
| SMTP irraggiungibile: eventi marcati inviati | **0** |
| Reinvio possibile senza ririlevare | **sì** |

- [x] Con una tabella sola l'errore avrebbe perso anche la rilevazione, e la
      condizione sarebbe stata segnalata solo al prossimo cambio di fascia

## 6. La firma impedisce la ripetizione

| Verifica | Esito |
|---|---|
| Prima rilevazione | 434 nuovi |
| **Seconda rilevazione, stessi dati** | **0 nuovi, 434 già noti** |
| Eventi in tabella | 434 — nessun duplicato |
| 79,8% → fascia 70; 80,1% → fascia 80 | corretto |

- [x] **Fascia e non valore esatto**: con il valore, da 85,0 a 85,1 genererebbe
      una email
- [x] Granularità per metrica: decine per il consumo, cinquine per il margine,
      90 giorni per il fermo, due soli valori per la scadenza
- [x] Condizioni rientrate **chiuse, non cancellate**: verificato su un caso
      simulato
- [x] Il vincolo è **dichiarato nel messaggio**: chi non interviene subito non
      aspetta un promemoria che non arriva

## 7. Una email per destinatario

| Verifica | Esito |
|---|---|
| Messaggi prodotti | **4** |
| Righe complessive | **652** |
| Direttore | 434 (tutte) |
| Agenti | 105, 76, 37 (solo le loro) |
| Copia per agente | funzionante |

- [x] Una email per evento sarebbe posta indesiderata anche con messaggi
      legittimi
- [x] Ordinamento per gravità: le righe critiche in cima

## 8. Protezioni

- [x] **Spento di fabbrica**: `alert_enabled = 0`, `alert_dry_run = 1`
- [x] La simulazione **non consuma gli eventi**: verificato, 0 marcati
- [x] **Tetto 50 messaggi** per esecuzione: interrompe l'invio, non la
      rilevazione — gli eventi restano in coda

## 9. QA

| Verifica | Esito |
|---|---|
| Chiamate a metodi inesistenti | **0** |
| Metodi `SmtpMailer` verificati | **8 su 8** |
| Protezione CLI e opzioni documentate | **OK** |
| Avvisi o errori PHP | **0** |

## 10. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` (dati reali) | 12 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 12 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 12 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c95` fresco | 622 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c95` | 622 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c95` | 622 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **612 → 622**

## 11. Aperto

- **I riepiloghi cadenzati non sono implementati**: le regole `riepilogo_sett` e
  `riepilogo_mens` esistono ma sono disattivate. Richiedono una logica a
  calendario diversa da quella a evento, perché un riepilogo va inviato **anche
  se vuoto** — «nessuna criticità» è un'informazione, un alert che non arriva è
  ambiguo.
- **L'alias deve essere autorizzato dal server SMTP**: con Aruba, in genere deve
  appartenere al dominio dell'utenza. Non è verificabile dal portale.
- **Un evento chiuso che si ripresenta** non genera una riga nuova: il vincolo di
  unicità sulla firma lo impedisce. Se servisse tracciare i cicli, la firma
  andrebbe estesa con la data di riapertura.
- **Modificando le soglie**, le condizioni già segnalate mantengono la firma
  vecchia: per rigenerarle serve chiudere gli eventi aperti a mano (documentato).
