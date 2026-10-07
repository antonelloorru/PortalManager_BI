# CHANGELOG — v1.10.20 (2026-10-07)

Software 1.10.20 · Schema 1.10.20 · Upgrade `sql/migration_v1_10_20.sql` (cumulativo da 1.10.06) · Plugin pm-ats **1.3.1**

## Pagina «Lavora con noi» come nell'esempio
- **Colonna sinistra**: «Unisciti a WeTech's!», introduzione, «Posizioni Aperte».
  - Elenco delle posizioni: ogni voce ha il **titolo cliccabile** che apre la scheda della posizione (tutta la casella è cliccabile, freccia ›).
  - Sotto il titolo: sede · modalità · contratto.
- **Colonna destra**: «Compila il form», con il modulo di candidatura e la scelta della posizione (o candidatura spontanea).
- Le due colonne si affiancano quando il contenitore è largo almeno 760 px (riga a tutta larghezza Divi o pagina). In una colonna stretta (es. ½ Divi) o su tablet/telefono il modulo va sotto l'elenco.

## Perché prima non era uguale
- Dopo l'aggiornamento il layout restava quello configurato (griglia di schede), perché la 1.3.0 richiedeva di sceglierlo a mano. Ora «Lavora con noi» è il **predefinito**; all'aggiornamento griglia o lista vengono sostituite una sola volta (registro del plugin), e si possono ripristinare in Impostazioni › Aspetto.
- La 1.3.0 forzava il blocco a tutta larghezza dello schermo (100vw): dentro le righe/colonne del tema poteva uscire dai margini. Ora usa la larghezza del contenitore in cui è inserito.
- Le posizioni erano a fisarmonica: ora per default sono un **elenco di link** alla scheda. La fisarmonica resta disponibile: Impostazioni › Aspetto › Elenco posizioni.

## Shortcode
`[pm_ats_lavora_con_noi]` mostra sempre questa pagina, qualunque sia il layout impostato. Attributi: `hero="0|1"` (testata), `elenco="link|accordion"`.
