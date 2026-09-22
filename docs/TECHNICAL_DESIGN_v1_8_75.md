# Technical Design — PortalManager v1.8.75

## 1. Chi decide e chi avvia

La pianificazione è divisa in due responsabilità:

| Componente | Responsabilità |
|---|---|
| Utilità di pianificazione di Windows | **avviare** lo script, a intervalli regolari |
| `cron_sync.php` | **decidere** se è il momento di lavorare |

L'alternativa sarebbe stata configurare l'orario in Windows e far eseguire allo
script il lavoro senza domande. Più semplice, e con due difetti: l'orario si
cambierebbe solo con accesso al server, e un'esecuzione mancata — server spento —
non si recupererebbe.

Spostando la decisione nello script, l'attività di Windows diventa un battito
regolare e stupido, e tutta la logica sta dove la si può cambiare da interfaccia.

## 2. La finestra di recupero

```php
$previsto = strtotime(date('Y-m-d') . ' ' . $cfg['run_at']);
$fine     = $previsto + $cfg['window_minutes'] * 60;
if ($ora < $previsto || $ora > $fine) { /* niente */ }
```

Non un istante ma un intervallo. Un orario esatto richiederebbe che l'attività
parta in quel minuto: se il server è spento alle 02:00 e acceso alle 03:00, la
sincronizzazione salta il giorno.

Con la finestra, lo script trova l'intervallo ancora aperto al primo avvio utile.
Due ore coprono i riavvii ordinari senza rischiare di eseguire in orario di
lavoro.

## 3. Riprovare dopo un fallimento, non dopo un successo

```php
if (!empty($cfg['last_run_at'])
    && date('Y-m-d', strtotime($cfg['last_run_at'])) === date('Y-m-d')
    && in_array($cfg['last_status'], ['ok', 'parziale'], true)) { /* niente */ }
```

La condizione include lo **stato**, non solo la data. Un'esecuzione fallita alle
02:05 lascia la porta aperta: alle 03:00 lo script riprova, perché l'errore
poteva essere transitorio — il gestionale non raggiungibile, un blocco
temporaneo.

Se la condizione fosse stata sulla sola data, un fallimento avrebbe richiesto
un intervento manuale o l'attesa del giorno dopo.

`parziale` è trattato come successo: alcuni dataset sono passati, e ripetere
tutto per recuperarne uno costerebbe minuti per un guadagno incerto. Il riquadro
lo segnala e l'operatore decide.

## 4. Il lock e la sua scadenza

```sql
UPDATE cm_sync_schedule SET lock_owner = ?, lock_expires = ?
 WHERE id = 1 AND (lock_expires IS NULL OR lock_expires < NOW())
```

L'acquisizione è un `UPDATE` condizionato: `rowCount() === 0` significa che
qualcun altro lo tiene. È atomico senza bisogno di transazioni esplicite.

La **scadenza a 3 ore** è la parte importante. Un lock senza scadenza è corretto
finché il processo termina bene; se viene ucciso — riavvio del server, arresto
anomalo — resta acquisito per sempre e la pianificazione muore in silenzio.

`register_shutdown_function` rilascia il lock anche su errore fatale, ma non
copre un `kill -9`: la scadenza è la rete sotto la rete.

## 5. Perché non un trigger sul caricamento delle pagine

È il "poor man's cron": all'apertura di una pagina si controlla se c'è lavoro
pendente e lo si esegue.

Due difetti che lo rendono inadatto qui.

La sincronizzazione dura **minuti**: l'utente che apre per primo la pagina dopo
l'orario previsto la vedrebbe bloccata, senza capire perché. Si può mitigare con
una richiesta asincrona, ma su Apache in ambiente Windows significa introdurre
complessità per un problema che l'Utilità di pianificazione risolve già.

E in un giorno senza accessi non verrebbe eseguita — proprio il giorno in cui una
pianificazione automatica serve di più.

## 6. `unset($rows)` fra un dataset e l'altro

```php
$rows = $sync->readSource($source, $k, 0);
…
unset($rows);
```

`readSource()` accumula l'intero dataset in memoria: 36 MB per le sole
allocazioni, misurati nella v1.8.67. Senza il rilascio esplicito, il picco
sarebbe la somma dei dataset invece del massimo fra essi.

Lo script imposta `memory_limit` a 512M, ma un limite alto non sostituisce il
rilascio: rimanda solo il punto in cui il problema si presenta.

## 7. Codici di uscita

| Codice | Significato |
|---|---|
| 0 | eseguita, oppure non era il momento |
| 1 | eseguita con errori su almeno un dataset |
| 2 | errore di configurazione o connessione |

«Non era il momento» è **0** e non un codice dedicato: per l'Utilità di
pianificazione non è un fallimento, ed è la situazione normale in ventitré
esecuzioni su ventiquattro. Segnalarla come anomalia riempirebbe la cronologia di
falsi allarmi, e chi guarda smetterebbe di guardarla.

## 8. Solo da riga di comando

```php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(...); }
```

Il file sta nella ROOT ed è quindi raggiungibile via web. Senza questo controllo,
chiunque ne conoscesse l'URL potrebbe far partire una sincronizzazione — anche
ripetutamente — senza autenticazione.
