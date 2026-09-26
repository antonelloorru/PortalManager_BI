# PortalManager v1.9.73 — Prestazioni Gestione Commesse e grafici giornalieri

## Diagnosi (dati di produzione del 18/09)

| Pagina | Causa | Misura prima | Dopo |
|---|---|---|---|
| Ordinativi Pratix | `cm_project_operations.order_code` senza indice; la vista espone `trim(order_code)`, che impedisce qualunque indice; filtri/ordinamenti con sottoquery correlate | filtro commerciale + ordina per cliente: **700 ms** | **18–19 ms** (risultato identico) |
| Relazione di Servizio IT | "Dettaglio per commessa" con tutte le righe nella pagina: 21.357 righe in 416 tabelle (8,5 mesi) | **5,3 MB** di HTML | **284 KB**; righe caricate all'apertura del contratto (~2 ms, ~5 KB) |
| Service Desk | ~36 calcoli su viste aggregate a ogni apertura | — (viste non presenti nel backup) | copie delle viste lente |
| Report direzionale | 9 query sulla stessa vista aggregata, ricalcolata ogni volta | — (viste non presenti nel backup) | copie delle viste lente (prova: 121 ms → 0,5 ms per query) |

Nessuna query dentro cicli (N+1); indici delle tabelle base già adeguati.

Difetto grave emerso: **il backup (`system_backup.php`) non salvava le viste**; un
ripristino lasciava il portale senza le viste di Gestione Commesse. Corretto.

## Correzioni

### Ordinativi Pratix (solo database)
Migration: indice `idx_projop_order`; `v_cm_pratix_righe` riscritta leggendo la sua
definizione dal database e sostituendo solo `trim(o.order_code)` (eventuali modifiche
locali restano). Sicuro: l'import normalizza già i codici (`DatasetSync::cast` applica
trim). Servono entrambe le modifiche: da sole 277 ms (indice) e 170 ms (vista).

### Relazione di Servizio IT
- A schermo: sintesi per contratto (righe, ore, totali) con i contratti chiusi; le righe
  arrivano dalla stessa pagina (`?ajax=dett_commessa&cid=…`, stessi filtri e permessi)
  quando si apre il contratto; errore gestito con nuovo tentativo.
- Stampa/PDF ed export Word: dettaglio completo, come prima.
- `ItServiceModel`: `dettaglioCommessa($f, $contractId)`, `dettaglioCommessaSintesi($f)`.
  Verificato: dettaglio completo identico al precedente (21.357 righe, stesso ordine),
  totali di sintesi coincidenti per tutti i 416 contratti.

### Copie delle viste lente (Service Desk, Relazione IT, Report direzionale)
`app/PmSnapshot.php`: tabella `snap_<vista>` con lo stesso contenuto della vista.
- Automatico e misurato: la copia si attiva se il calcolo della vista supera 50 ms e si
  disattiva sotto 20 ms (isteresi); per ogni vista auto / sempre / mai.
- Aggiornamento: dopo ogni sincronizzazione; oltre 60 minuti la pagina usa la copia e ne
  chiede l'aggiornamento in background (worker senza cron); oltre 26 ore torna alla vista.
- Sostituzione atomica, lock contro esecuzioni concorrenti, errori → vista originale.
- Indicatore in pagina: "dati aggiornati alle HH:MM".
- `?pm_nosnap=1` forza la lettura diretta delle viste (verifica).

### Grafici giornalieri
- Service Desk: il grafico giornaliero esisteva solo per periodi fino a 92 giorni; con il
  periodo predefinito era sempre mensile. Ora, quando il grafico adattivo è mensile,
  compare l'**andamento giornaliero dei ticket** per classe di gestione.
- Relazione di Servizio IT: nuovo **andamento giornaliero delle ore** (ordinarie, fuori
  orario, reperibilità), a schermo e in stampa.
- Ultimi 92 giorni del periodo (o l'intero periodo se più breve), weekend evidenziati,
  media dei giorni feriali, tooltip per giorno. Componente condiviso `app/PmCharts.php`.

### Strumenti
- **Sistema → Prestazioni** (`perf_center.php`, Super Admin): stato delle copie, modalità
  per vista, aggiornamento immediato, attivazione del profiler.
- **Profiler delle query** (per la propria sessione): in fondo a ogni pagina l'elenco
  delle query con i tempi misurati da MariaDB.

### Backup
`system_backup.php` esporta anche le viste, in ordine di dipendenza, senza DEFINER e senza
nome del database; le copie `snap_*` sono escluse (ricalcolabili).

## QA
Grafici 9/9 + controllo visivo; dettaglio IT 4/4 su dati reali + 5/5 nel browser;
copie 13/13 (inclusi isteresi, modalità, scadenza, errore, lock); worker: firma per
attività, compatibilità v1.9.70; backup: ripristino in un altro database con conteggi
identici; migration RUN1/RUN2 err=0 anche su database senza la vista Pratix; `php -l`
su tutti i file; schema_version → 1.9.73.
