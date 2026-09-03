# Technical Design — PortalManager v1.9.12

## 1. Perché il confronto falliva

MariaDB confronta le stringhe secondo la collation della colonna. Con una
collation `_ci` il confronto sarebbe stato indifferente alla grafia, e il difetto
non si sarebbe presentato.

`forms_activity.status` è `varchar(40)` senza collation esplicita, quindi eredita
quella della tabella — che nel gestionale è evidentemente sensibile alla grafia,
o il confronto avviene in un contesto che lo rende tale.

`UPPER(TRIM(...))` rimuove la dipendenza dalla collation: il confronto è
esplicitamente insensibile, e resta tale se il gestionale cambia impostazione.

Il costo è che l'indice su `status` non viene usato. Su una query di
sincronizzazione che gira una volta al giorno è irrilevante.

## 2. `TRIM` oltre a `UPPER`

`'  OPEN  '` è stato incluso nel collaudo perché un valore con spazi è
indistinguibile a occhio da uno senza, e un export che passa per un foglio di
calcolo li aggiunge con facilità.

## 3. Lo stato in tabella: il difetto strutturale

Il filtro sbagliato è un difetto ordinario. Che sia rimasto invisibile per mesi è
il difetto vero.

Il portale scartava righe senza registrare né quante né perché. L'unico modo di
accorgersene era confrontare un export del gestionale con il portale, riga per
riga, per un tecnico specifico — che è esattamente quello che è successo.

`source_status` costa una colonna e rende il fenomeno misurabile. `v_cm_ir_stati`
lo aggrega, e la colonna `ammesso` segnala gli stati presenti in tabella ma non
nell'elenco: se ne comparissero, vorrebbe dire che il parametro e la query
divergono.

## 4. Il parametro non governa la query

`sync_stati_moduli` documenta l'elenco; il filtro operativo è nella query SQL di
`SyncDatasets`.

Sarebbe stato possibile leggere il parametro e costruire la query dinamicamente.
Non l'ho fatto perché quella query gira sul database del GESTIONALE, dove
`app_settings` non esiste: leggere il parametro richiederebbe una connessione al
portale prima di interrogare il gestionale, per un valore che cambia una volta
l'anno.

La divergenza possibile è dichiarata nel deployment, e `v_cm_ir_stati.ammesso` la
rende visibile invece di lasciarla latente.

## 5. `APPROVED` non esisteva

Zero occorrenze su tutto il dump del gestionale.

Uno dei tre stati del filtro originale era un valore inventato. Non ha causato il
difetto — un valore che non esiste non scarta nulla che gli altri due avrebbero
accettato — ma dice come l'elenco era stato costruito: per plausibilità, non per
osservazione.

È lo stesso errore della formula C nella v1.9.1 e della ricostruzione di
`margin_total` nella v1.9.2. Tre volte lo stesso schema: dedurre invece di
guardare.

## 6. Il rollback non cancella i moduli

`DROP COLUMN source_status` toglie la colonna, non le righe entrate con la nuova
regola.

Cancellarle richiederebbe di distinguerle da quelle preesistenti, e la distinzione
sta proprio nella colonna che si sta rimuovendo. Una migration di rollback che
cancella dati di produzione sulla base di un criterio che sta cancellando è un
rischio maggiore del difetto che dovrebbe annullare.
