# Manuale Amministratore v1.10.07 — Sincronizzazione gestionale › SOC

Percorso: **Gestione Commesse › Sincronizzazione gestionale › scheda SOC** (permesso di esecuzione di Sincronizzazione gestionale; connessione DB e cartella di arrivo: Super Admin).

1. **Import da file**: «Carica e sincronizza» con l'export XLSX/CSV. In alternativa copiare gli export in `uploads/soc_inbox` (o nella cartella impostata):
   saranno acquisiti alla prossima esecuzione e spostati in `archivio` (o `scartati` se illeggibili).
2. **DB SOC**: aprire *Connessione (Super Admin)*, compilare host, porta, database, utente di sola lettura, password, finestra e prefisso ticket;
   Test connessione → Anteprima → Salva.
3. **Pianificazione e regole**: ☑ Esecuzione automatica, intervallo (default 60 minuti), soglie di risposta e presidio, stati di chiusura,
   ☑ Assegna i tecnici all'unità SOC. La pipeline parte da sola con lo scheduler del portale; se lo scheduler è in modalità esterna usare il comando mostrato (`cron_soc_sync.php`).
4. **Unità Organizzativa SOC**: elenco dei tecnici del servizio con l'unità attuale. «Sposta su SOC» per chi appartiene a un'altra unità
   (es. Service Desk): lo spostamento cambia anche la composizione di quell'unità.
5. **Abbinamenti**: persone → dipendenti e clienti; le scelte manuali restano. Dopo un abbinamento nuovo la mappatura sull'unità è immediata.
6. **Registro della pipeline**: ogni esecuzione con innesco (manuale, pianificata, giornaliera, caricamento), esito e dettaglio per sorgente.
