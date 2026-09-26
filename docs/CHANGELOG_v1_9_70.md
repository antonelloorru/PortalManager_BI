# PortalManager v1.9.70 — Sincronizzazione gestionale giornaliera senza cron

## Cosa cambia
La sincronizzazione pianificata (Commesse → Sincronizzazione gestionale) viene avviata
**dal portale stesso**, senza cron né Utilità di pianificazione di Windows.

## Difetti preesistenti corretti
- `cron_sync.php` includeva `app/Db.php`, file inesistente: la sincronizzazione pianificata
  terminava con errore fatale a ogni esecuzione. Ora usa la connessione di `Config.php`.
- La vista `v_cm_sync_schedule_stato` non era creata da alcuna migration: la query della
  pagina falliva e la card della pianificazione non veniva mai mostrata. Ora è definita.

## Funzionamento
1. A fine di ogni richiesta web (pagina già inviata) `CronlessScheduler::tick()` controlla la
   pianificazione, al massimo una volta al minuto per istanza.
2. Se la sincronizzazione è dovuta, avvia `cronless_worker.php` con una richiesta interna
   (127.0.0.1) **non bloccante** e firmata HMAC: il worker risponde subito `202` e prosegue
   in background. L'utente non attende (misurato: 7 ms).
3. Se il loopback non è raggiungibile, fallback nel processo corrente (segnalato in pagina).

## Regole di pianificazione (`SyncRunner::isDue`)
- giorni e orario configurati; con **"Recupera in giornata"** (default) la sincronizzazione
  parte al primo accesso dopo l'orario previsto, altrimenti solo entro la finestra di recupero;
- una sola esecuzione riuscita al giorno;
- dopo un errore nuovo tentativo non prima di 30 minuti (niente tentativi a raffica);
- lock in DB con scadenza di 3 ore: mai due esecuzioni contemporanee;
- un'unica sorgente temporale (PHP) per lock e date: nessun disallineamento di fuso PHP/MySQL.

## Pagina Sincronizzazione gestionale
- Modalità di esecuzione: **Automatica dal portale (senza cron)** oppure Attività esterna.
- Opzione "Recupera in giornata".
- Stato: modalità, prossima esecuzione, ultimo controllo automatico, ultimo avvio del worker,
  motivo per cui non è ancora partita.
- Pulsante **Esegui ora in background**.

## Sicurezza del worker
Solo POST firmati (HMAC-SHA256, segreto in `.env.php`, validità 5 minuti); GET → 405,
firma assente/errata/scaduta → 403. Un eventuale replay è innocuo: il worker esegue solo se
la sincronizzazione è dovuta e sotto lock. Il worker non apre sessioni.

## QA
- 17 casi di `isDue`/`nextRunAt` (orario, giorni, finestra, recupero, già eseguita, errore
  con intervallo, lock attivo/scaduto, disattivata): tutti OK.
- End-to-end con server web multi-processo e MariaDB: pagina 200 in 7 ms, sincronizzazione
  completata in background (trigger `cronless`), lock rilasciato, diagnosi "regolare";
  throttling, idempotenza, due avvii simultanei → una sola esecuzione, firme non valide → 403,
  fallback in-process con loopback irraggiungibile, `cron_sync.php` da CLI.
- `php -l` OK su tutti i file; migration RUN1/RUN2 err=0 su DB esistente e su DB vuoto.
