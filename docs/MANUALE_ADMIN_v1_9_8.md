# Manuale Amministratore — v1.9.8

## I filtri sono uniformi

**Service Desk**, **Relazione di Servizio IT** e **Report direzionale** hanno ora
lo stesso pannello di **Commesse / Progetti**: a scomparsa, con gruppi
intestati e contatore dei filtri attivi.

Gli stili erano **dentro `manage_projects.php`**: li ho estratti in un foglio
condiviso, perché copiarli in quattro viste avrebbe creato quattro copie
divergenti — e la prima modifica le avrebbe allineate solo in parte.

## I campi che mancavano

| Vista | Aggiunti |
|---|---|
| **Report direzionale** | Cerca ovunque, Cliente, Azienda esecutrice |
| **Relazione IT** | Cerca ovunque, Cliente |
| **Service Desk** | **Classe di gestione** |

La classe di gestione merita una nota: **esisteva nel modello dalla v1.8.84** ma
non c'era nel pannello. Si poteva usare solo modificando l'URL a mano.

È il secondo caso in poche release — dopo il filtro per componente della v1.9.6 —
di una funzione presente e irraggiungibile. Ho aggiunto un controllo per
intercettarli.

## Il pannello si apre da solo

Se un filtro è attivo, il pannello è aperto e il contatore lo dice.

Serve soprattutto quando aprite un collegamento con filtri nell'URL — da un
segnalibro o da una email — e non avete impostato nulla di persona: un pannello
chiuso vi farebbe credere di guardare tutti i dati.

## Il controllo che ho aggiunto

Ogni campo del pannello viene confrontato con quelli che il modello riconosce.

**Un campo che il modello ignora produce un filtro che si compila, si invia,
ricarica la pagina e non cambia nulla**: nessun errore, solo un utente convinto di
aver ristretto i dati.

Esito su questa release: **19 campi su tre viste, nessuno non riconosciuto**.

## La ricerca libera

Cerca su **codice, denominazione e cliente**: i tre modi in cui una commessa viene
nominata a voce.

Non cerca su tutti i campi: avrebbe restituito corrispondenze su note interne e
identificativi tecnici che nessuno cerca.

## I menu a selezione multipla

Hanno ora un'altezza sufficiente a mostrare più righe, e l'etichetta `(multipla)`.

Un menu multiplo alto una riga sembra un menu normale: chi fa clic su una seconda
voce perde la prima senza capire perché.

## Da fare in installazione

**Copiare `assets/pm-filters.css`** nella cartella `assets\`, creata con la
v1.9.3. Senza, i pannelli funzionano ma senza stile.
