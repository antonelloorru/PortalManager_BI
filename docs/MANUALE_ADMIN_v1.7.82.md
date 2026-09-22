# Manuale Amministratore — v1.7.82 (Gestione Commesse)

## 1. Prerequisiti permessi
Sezione menu **Gestione Commesse** (visibile a Super Admin di default). Per gli altri ruoli
assegnare in *Amministrazione → Permessi ruoli* i permessi su:
`manage_projects.php`, `project_dashboard.php`, `manage_rate_bands.php`,
`import_commesse.php`, `import_intervention_reports.php`.

Nella pagina *Permessi ruoli* le voci si trovano nel gruppo **Gestione Commesse**, sezione
indipendente al pari di Brand & Partnership o Competenze & Formazione.

**Sezioni comprimibili**: cliccare l'intestazione di un gruppo per comprimerlo/espanderlo; in alto a
destra sono disponibili *Espandi tutte* / *Comprimi tutte*. Ogni intestazione mostra un contatore
(permessi attivi per ruolo, override per utente). Lo stato resta memorizzato nel browser; comprimere
una sezione **non** altera i permessi salvati.

## 2. Configurazione iniziale
1. **Fasce costo orario** (*Gestione Commesse → Fasce costo orario*): compilare la matrice
   tariffe per ogni fascia (A–F preseminate; aggiungibili) su Aziendale/Cliente/Commerciale ×
   Ordinario/Reperibilità. Ogni salvataggio è storicizzato.
2. **Parametri costo HR** (opzionali) in `app_settings`:
   - `proj_oneri_mult` (default 1.42) — moltiplicatore oneri su RAL
   - `proj_annual_hours` (default 1720) — ore/uomo annue
3. Le aziende **Wenest SRL** (sede Marcon) e **Weenergy** (sede Montevarchi) e la mappa
   prefissi (WTS/NIS/ANT/MIPS/WEN/WEE) sono create dalla migration.

## 3. Import commesse (XLSX)
*Gestione Commesse → Import commesse XLSX* → caricare l'export. UPSERT su `codice_commessa`:
- clienti creati/aggiornati nel **registro clienti condiviso** (`clients`, lo stesso usato dagli altri moduli);
- azienda esecutrice derivata dal prefisso del codice;
- ri-esecuzione sicura (nessun duplicato). I valori economici del file sono la fonte di verità.

## 3-bis. Limiti di caricamento
I form di import mostrano il limite corrente. Se un file lo supera, il portale indica la
dimensione inviata e il limite attivo: aumentare `upload_max_filesize`/`post_max_size` in
`php.ini` e riavviare Apache, oppure suddividere l'export (l'import è UPSERT: i file parziali
si sommano senza duplicati). La lettura è in streaming: file molto grandi non saturano la memoria.

## 4. Import rapporti di intervento (XLSX)
*Gestione Commesse → Import rapporti intervento* → caricare la lista rapporti. UPSERT su `N.`:
- risoluzione automatica commessa (per codice), tecnico ("Cognome Nome"), cliente/sede, fascia;
- `approvato` aggiornato ad ogni reimport;
- costo/ricavo salvati sia da file (`*_import`) sia calcolati da fascia (`*_calc`);
- righe senza match commessa/tecnico vengono comunque importate e conteggiate come "non risolte".

## 4-bis. Controllo & Riconciliazione (dopo ogni import)
*Gestione Commesse → Controllo & Riconciliazione*. L'esito dell'import riporta il numero di righe
non risolte con il collegamento diretto a questa pagina.

- **Riepilogo**: quante righe sono senza commessa, senza tecnico, senza fascia.
- **Anomalie raggruppate per valore**: si lavora sui valori distinti (poche decine), non sulle righe.
  Ogni riga mostra occorrenze, periodo e un **suggerimento automatico**, già preselezionato.
- **Mappa a**: scegliere il record corretto, oppure *Ignora questo valore* per escluderlo
  definitivamente dalla lista. Il salvataggio riapplica subito la risoluzione a tutte le righe.
- **Esporta anomalie (XLSX)**: file con tre fogli di anomalie + tre fogli di riferimento con gli ID.
  Si compila `mappa_a_id` (o `ignora` = 1) e si ricarica con *Importa mappature e riapplica*.
- **Riapplica risoluzione**: rilancia la risoluzione su tutte le righe non risolte — utile dopo aver
  importato le commesse mancanti o creato nuovi dipendenti, **senza reimportare** il file dei rapporti.

Le mappature sono **persistenti**: gli import successivi le applicano automaticamente.

## 4-ter. Timesheet (Gestione Commesse → Timesheet)
Cartellino mensile per risorsa. Ogni cella è la somma delle ore del giorno: quelle **dai rapporti di
intervento** (automatiche, non modificabili) più eventuali **voci manuali**.
- Navigazione mese per mese; filtri per commessa e dipendente; spunta *mostra tutti i dipendenti* per
  includere anche chi non ha attività nel mese.
- La colonna **Sat.** è la saturazione rispetto alle ore attese (giorni feriali × ore giornaliere
  standard, impostabili col setting `ts_daily_hours`).
- Clic su una cella → dettaglio del giorno (rapporti e voci) con possibilità di eliminare le voci manuali.
- **Nuova voce manuale**: per ferie, permessi, formazione, trasferte, ecc., con attività, commessa
  facoltativa e note. **Esporta XLSX** produce il cartellino completo del mese.

## 4-quater. Gantt commesse (Gestione Commesse → Gantt commesse)
Diagramma di portfolio: per ogni commessa la barra chiara è il **pianificato** (date in Anagrafica) e
quella piena l'**effettivo** ricavato dai rapporti. Se l'ultimo rapporto supera la fine pianificata la
barra effettiva è rossa. Filtri per stato, azienda e testo; clic sul codice per aprire la scheda.

Nella **scheda commessa** il tab **Gantt** mostra pianificato vs effettivo, le **fasi** con avanzamento,
le barre per singola risorsa e il carico mensile. Le fasi si creano e modificano lì (nome, date, %).

## 4-quinquies. Carico & Sovrapposizioni (Gestione Commesse → Carico & Sovrapposizioni)
Vista d'insieme dell'impegno delle persone e delle sovrapposizioni, ricavata dai rapporti di intervento.
- **Heatmap risorsa × mese**: ogni cella è il monte ore del mese; il colore indica la saturazione
  rispetto alla capacità (giorni feriali × ore/giorno). Il simbolo ⚠ segnala i mesi con più commesse in
  parallelo. Clic su una cella per vedere la ripartizione per commessa.
- **Conflitti per risorsa**: elenco dei mesi in cui una persona è impegnata su più commesse insieme o
  oltre la propria capacità (righe rosse = sovraccarico), con le commesse coinvolte.
- **Sovrapposizioni tra commesse**: coppie di commesse che nello stesso periodo usano le stesse persone.
  Per ciascuna coppia sono indicati la **fascia temporale** della sovrapposizione (dal primo all'ultimo
  mese in comune), il numero di mesi, le risorse condivise e le **ore consuntivate su ciascuna commessa**
  con il totale in contesa. Rispetta i filtri di periodo, commessa e risorse: restringendo l'intervallo,
  fascia e ore si aggiornano di conseguenza.
- **Legenda dei colori**: la vista spiega ogni fascia di saturazione (sotto-utilizzo, ottimale, al
  limite, sovraccarico) e il significato del marcatore ⚠ (più commesse nello stesso mese).
- **Ordinamento**: le risorse possono essere ordinate per monte ore (decrescente/crescente) o per nome.
- **Filtro multi-risorsa**: seleziona una o più persone (Ctrl/Cmd per la selezione multipla) per
  concentrare heatmap, conflitti e grafico su un gruppo specifico; *Azzera selezione* per tornare a tutte.
- **Grafico del carico**: un diagramma a linee mostra l'andamento mensile delle ore per ciascuna risorsa
  selezionata, con la linea tratteggiata della capacità come riferimento. Con molte risorse conviene
  selezionare un gruppo dal filtro per una lettura chiara.
- **Filtro per linea di servizio**: limita heatmap, conflitti e sovrapposizioni alle commesse di una
  determinata linea di servizio (campo `service_line` della commessa).
- Filtri per intervallo di mesi, commessa, risorsa e *solo sovraccarichi*; **Esporta XLSX** salva
  heatmap, capacità e conflitti.

## 4-sexies. Cestino (Sistema → Cestino)
Quando un record del modulo Commesse viene cancellato (membro di un team, fase di commessa, voce di
timesheet…), non è perso: finisce nel **Cestino**, da cui può essere recuperato.
- La tabella elenca cosa è stato eliminato, quando, da chi e da quale pagina.
- **Ripristina** re-inserisce il record con i dati originali. Se nel frattempo è stato creato un altro
  record con la stessa chiave, il ripristino viene bloccato per non sovrascrivere nulla (il sistema lo
  segnala).
- **Elimina definitivamente** rimuove la voce dal cestino (irreversibile).
- **Svuota vecchie voci** cancella in blocco gli elementi più vecchi di un numero di giorni a scelta.
- Filtri per tipo di record, stato (nel cestino / ripristinati) e testo della descrizione.
- Accesso riservato al Super Admin.

## 5. Scheda commessa (dashboard)
*Gestione Commesse → Commesse* → icona grafico. Tab disponibili:
- **Anagrafica**: dati commessa + workflow (stato commerciale, riallocazione perdita, costi materiali).
- **Effort Presales**: ore e tariffe per Ufficio Gare, Sicurezza, Ingegneria/Analisi, PM.
- **Team**: allocazione risorse; mostra costo pieno, Servizio/Non a Valore, Diretto/Indiretto.
- **Redditività**: ricavi, costi (materiali+HR+presales), margine e %, ripartizioni.
- **Consuntivo**: totali e dettaglio dei rapporti importati.
  - Filtri per testo (rapporto, ticket, tecnico, lavoro eseguito), *Approvato*, *Reperibilità*; 50 rapporti per pagina.
  - Freccia a inizio riga → **dettaglio completo** del rapporto (cliente, sede, ticket, settore, ore,
    importato vs calcolato, batch di import, richiesta e lavoro eseguito).
  - Icona matita → **modifica** del rapporto: dati, tecnico, fascia, cliente/sede, ore, importi, flag e testi.
    È possibile anche **riassegnare il rapporto a un'altra commessa**. I valori calcolati vengono
    rigenerati al salvataggio; quelli importati restano invariati.
- **Team**: oltre alle ore allocate mostra **ore e costo effettivi dai rapporti**.
  - **Popola team dai rapporti**: aggiunge automaticamente i tecnici che hanno lavorato sulla commessa,
    con le ore risultanti dai rapporti. Le righe inserite manualmente (ore e ruolo) **non vengono toccate**;
    l'operazione è ripetibile senza effetti collaterali.
  - Un avviso elenca i tecnici presenti nei rapporti ma non ancora in team.

## 6. Audit
Variazioni tariffe in `hourly_rate_band_history`; azioni tracciate via log applicazione
(*Amministrazione → Log applicazione*). Ogni import registra un batch in
`intervention_import_batches`.

## Import Commesse DB (Gestione Commesse → Import Commesse DB)
Importa l'export nativo del gestionale (tabella *contract*), un file CSV con separatore `|`. Carica il
file, eventualmente spunta *importa anche le commesse marcate come eliminate*, e avvia: le commesse sono
create o aggiornate su `cm_projects` in base al **codice** (nessun duplicato alle riesecuzioni). Il
cliente viene creato in anagrafica dal nome presente nel file e l'azienda esecutrice è dedotta dal
prefisso del codice. Il riepilogo mostra righe lette, nuove, aggiornate ed eliminate saltate. I dati
caricati non vengono conservati oltre l'elaborazione.

## Anagrafica Professionisti (Gestione Commesse → Anagrafica / Import Professionisti)
Gli operatori del gestionale che non sono dipendenti vengono gestiti in un'anagrafica separata.
- **Import Professionisti**: carica l'export operatori (CSV separatore `|`). Le credenziali (password,
  RFID) non vengono mai importate. Puoi collegare automaticamente ai dipendenti con email identica e
  scegliere se includere gli operatori eliminati. UPSERT su ID operatore: ri-eseguibile senza duplicati.
- **Anagrafica Professionisti**: elenco con ricerca, filtri per **tipo** (esterni / dipendenti),
  stato e azienda, contatori (tra cui **Esterni** e **Dipendenti**). Ogni riga mostra un badge
  **Dipendente** (viola) se l'operatore corrisponde a un dipendente esistente — per email o nome, anche
  se non ancora unito — oppure **Esterno** (azzurro). Il pulsante **Rileva dipendenti** riesamina tutte
  le corrispondenze con l'anagrafica dipendenti (utile dopo aver aggiornato i dipendenti). Per ogni
  professionista non ancora collegato la vista propone un possibile **dipendente corrispondente**
  (per email o nome): con *Unisci* lo colleghi (merge), impostando lo stato a *unito* e creando gli alias
  che agganciano i suoi rapporti al dipendente. Puoi anche marcare un professionista come *confermato*
  (resta autonomo) o *ignorato*, oppure rimuovere un collegamento.
