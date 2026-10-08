# CHANGELOG — v1.10.26 (2026-10-08)

Software 1.10.26 · Schema 1.10.26 · Upgrade `sql/migration_v1_10_26.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## 1. Bugfix UI — tendina di ricerca (pm-multiselect)
**Sintomo.** Nella tendina con ricerca dei filtri (es. Incaricato, Codice Contratto) le opzioni risultavano tagliate e, scorrendo, venivano disegnate solo in parte.

**Causa.** Il pannello delle opzioni era `position:absolute` dentro il pannello «Filtri» (`.pm-panel`, `overflow:hidden`) e dentro contenitori scorrevoli. Il pannello lo ritagliava, lo scorrimento della lista trascinava la pagina e le intestazioni sticky lo coprivano.

**Correzione** in `assets/js/pm-multiselect.js` e `assets/css/pm-multiselect.css`:
- **Tendina flottante**: all'apertura il pannello delle opzioni passa in `<body>` con `position:fixed`, sulle coordinate del campo. Si apre sotto o, se lo spazio non basta, sopra il campo.
- **Altezza della lista** calcolata sullo spazio disponibile, tra 96 e 300 px.
- **Riposizionamento** a ogni scorrimento della pagina o ridimensionamento della finestra; la tendina si chiude se il campo esce dallo schermo.
- **Scorrimento della lista confinato** (`overscroll-behavior: contain`): arrivati in fondo, la pagina non scorre. `z-index` 10050, sopra le intestazioni fisse e le tabelle sticky.
- **Tastiera invariata**: frecce, Invio, Esc, Tab. Il clic su un'opzione non chiude la tendina multipla, il clic esterno la chiude.
- Vale per tutte le select del portale migliorate dal componente.

## 2. Pulizia dei filtri — un solo componente filtro per pagina
Pagine con il pattern del filtro globale: Relazione di Servizio IT, Relazione Tecnici, Service Desk, Service SOC, Report direzionale, Attività & Rendicontazione DGB.

- **Rimosso il riquadro «Filtro contratto attivo»** sopra il pannello, che duplicava il campo Codice Contratto. Il filtro resta nel pannello «Filtri»: con il filtro attivo il pannello si apre da solo, mostra il contatore e il contratto si rimuove dal suo campo.
- **Attività DGB**: tolto il selettore del mese a sé stante nel grafico giornaliero, cioè il secondo form di filtro. Restano i comandi di navigazione Mesi/Giorni e mese precedente/successivo.
- **Relazione Tecnici**:
  - l'opzione «dettaglio dei moduli» passa nel pannello, in «Dettagli da includere»;
  - le tipologie della tabella non sono più link-filtro: la tipologia si sceglie dal pannello.
