# Manuale Amministratore — v1.9.4

## Il difetto corretto

Solo la sincronizzazione automatica aggiornava lo stato. Lanciandola a mano dalla
pagina, i dati si aggiornavano ma **lo stato restava fermo**: il riquadro in home
continuava a segnalare **«IN RITARDO» su dati appena aggiornati**.

Ora ogni sincronizzazione manuale registra la propria esecuzione.

## Tre stati, e uno serve a proteggervi

| Azione | Stato |
|---|---|
| **Sincronizza tutto** | `ok` / `parziale` / `errore` |
| Sincronizza un dataset | **`dataset`** |
| Import da CSV | **`dataset`** |

Lo stato `dataset` è deliberatamente diverso, e la ragione è questa: **il cron
salta la giornata quando trova `ok` o `parziale`.**

Se avessi marcato così anche l'aggiornamento di una singola tabella, un import di
prova avrebbe **sospeso in silenzio la sincronizzazione automatica di quel
giorno** — e nessuno se ne sarebbe accorto fino al giorno dopo.

| Stato | Il cron |
|---|---|
| `ok` | salta |
| `parziale` | salta |
| **`dataset`** | **esegue comunque** |
| `errore` | esegue |

Per la stessa ragione `dataset` non fa tornare verde il riquadro in home: aver
aggiornato una tabella non è aver sincronizzato.

## Il lock resta al cron

Il blocco che impedisce due esecuzioni automatiche sovrapposte **non viene preso**
dalla sincronizzazione manuale.

Prenderlo sarebbe stato coerente e sbagliato: il lock scade dopo tre ore, e una
pagina chiusa a metà lascerebbe il cron bloccato fino alla scadenza per
un'esecuzione che nessuno sta più seguendo.

Il rischio accettato è che manuale e automatica si sovrappongano se lanciate
insieme. È raro, e il danno sarebbe una doppia lettura — non una corruzione,
perché la scrittura usa `INSERT ... ON DUPLICATE KEY`.

## Il registro distingue l'origine

```sql
SELECT started_at, trigger_type, status, rows_read, seconds, note
  FROM cm_sync_schedule_log ORDER BY id DESC LIMIT 20;
```

`trigger_type` vale `manuale` o quello del cron.

Serve a una domanda che altrimenti non potreste porvi: **la pianificazione
funziona, o qualcuno la sta supplendo a mano ogni giorno?** Un registro pieno di
esecuzioni riuscite sembrerebbe la prova che tutto va bene, mentre potrebbe essere
la prova che qualcuno lavora al posto del sistema.

## Un difetto trovato in collaudo

L'istante di inizio, necessario per calcolare la durata, era definito solo nel
blocco della sincronizzazione completa.

`php -l` non lo vede — una variabile non definita è sintatticamente valida — e
avrebbe prodotto una durata negativa di quarantasei anni. Il controllo che l'ha
trovato verifica che ogni blocco che registra un'esecuzione definisca il proprio
istante di inizio.
