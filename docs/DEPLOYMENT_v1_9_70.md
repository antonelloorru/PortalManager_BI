# Deployment — v1.9.70

1. Copiare in root: `cronless_worker.php`, `cron_sync.php`, `sync_commesse.php`;
   in `app/`: `SyncRunner.php`, `CronlessScheduler.php`, `bootstrap.php`.
2. Eseguire `sql/migration_v1_9_70.sql` (idempotente).
3. Commesse → Sincronizzazione gestionale: "Pianificazione attiva", orario, giorni,
   Modalità = "Automatica dal portale (senza cron)", "Recupera in giornata" → Salva.
4. Verifica: "Esegui ora in background" → dopo qualche minuto l'esito compare tra le ultime
   esecuzioni con trigger `manuale`.
5. Se in precedenza era stata creata l'attività nell'Utilità di pianificazione di Windows,
   può essere rimossa (se resta attiva non crea doppioni: stesso lock).

## Requisiti
- Apache in ascolto anche su 127.0.0.1 alla porta del portale (default XAMPP: sì).
- Almeno un accesso al portale dopo l'orario previsto: senza traffico non c'è innesco.
  Con "Recupera in giornata" basta il primo accesso della giornata.
