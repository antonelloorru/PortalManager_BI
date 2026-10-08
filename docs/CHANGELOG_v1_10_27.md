# CHANGELOG — v1.10.27 (2026-10-08)

Software 1.10.27 · Schema 1.10.27 · Upgrade `sql/migration_v1_10_27.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Sistema › File Manager — correzioni
**Causa principale.** Tutti i link della pagina erano relativi (`?p=…`, `?op=download…`, `?op=view…`, `?op=edit…`, breadcrumb, «Chiudi senza salvare»). `header.php` imposta `<base href>` sulla radice del portale, quindi quei link si risolvevano su `/?…`, cioè sulla home. Il risultato:
- aprire una cartella, risalire dal breadcrumb, scaricare, visualizzare e modificare portavano alla home;
- le operazioni (caricamento, nuova cartella, rinomina, elimina) tornavano sempre alla radice.

**Correzioni:**
- Tutti gli URL sono ora `file_manager.php?…` (`fm_url()`).
- **CSRF** verificato su ogni operazione: prima i form inviavano il token ma la pagina non lo controllava.
- **PRG**: dopo ogni operazione si torna alla cartella in cui si lavorava, con l'esito, e ricaricare la pagina non ripete l'operazione. Dopo il salvataggio di un file si torna alla sua cartella.
- **Download, ZIP e Visualizza** inviano il file a blocchi dopo aver svuotato i buffer di output. Prima il file passava interamente in memoria: i file grandi esaurivano la memoria e i binari rischiavano di arrivare corrotti. Il nome del file è conforme a RFC 5987, quindi gli accenti sono conservati.
- **Visualizza**:
  - HTML, SVG, XML, PHP e gli altri file di testo sono mostrati come testo, con `Content-Security-Policy: sandbox`, quindi nessuno script del file viene eseguito nel portale;
  - immagini e PDF sono mostrati nel loro formato;
  - gli altri tipi vengono scaricati.
- **Rinomina ed Elimina** funzionano anche con nomi che contengono apici o caratteri speciali.
- **Elimina** segnala gli elementi non eliminati per permessi o file in uso. Le cartelle di sistema del portale (`app`, `assets`, `sql`, `uploads`, `vendor`) non si possono eliminare né rinominare da qui.
- **ZIP**: archivio creato anche quando il file temporaneo esiste già, percorsi interni corretti, file temporaneo rimosso.
