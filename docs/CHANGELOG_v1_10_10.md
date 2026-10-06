# CHANGELOG — v1.10.10 (2026-10-06)

Software 1.10.10 · Schema 1.10.10 · Upgrade `sql/migration_v1_10_10.sql` (pacchetto cumulativo da 1.10.06)

## 1. Data mapping — categoria del ticket
- Query predefinita sul DB SOC: `tt_article.id_tt_ticket → tt_ticket.id`, `tt_ticket.id_tt_category → tt_category.id` → colonna `categoria`.
- Schema verificato sulla sorgente a ogni lettura: colonna descrittiva di `tt_category` fra name, description, title, label, descrizione, nome, code; colonna padre (id_parent, parent_id, …) → «Padre › Figlia». Tabelle o colonne assenti → query base, nessun errore, nota nel registro.
- Rilettura completa una tantum (`soc.full_resync`, impostata dalla migrazione): la categoria arriva anche sugli eventi già importati fuori dalla finestra incrementale.
- Registro della pipeline e anteprima: origine della categoria («categoria da tt_ticket.id_tt_category → tt_category.name»).

## 2. UI
- Filtro **Categoria** a scelta multipla nel blocco filtri principale, con «(non indicato)» per i ticket senza categoria.
- Cruscotto: «Ticket per categoria» (ticket, chiusi, ancora aperti) e «Ticket aperti per mese e categoria» (barre impilate).
- Tabella «Per categoria»: intestazioni corrette (Ticket, Chiusi, Risposta media, Ore moduli); clic → filtro Categoria.
- Team: matrice «Ticket per componente e categoria» con link all'elenco filtrato.
- Export XLSX: foglio «Componenti x categoria»; foglio Filtri con l'elenco delle categorie scelte.
- `PmCharts::groupedBars`: opzione `stacked`.

## 3. Filtri — pattern Relazione di Servizio IT
- Rimossa la seconda barra filtri client-side (ricerca, filtri per colonna, viste, export di `ListFilter::renderAuto`) agganciata dal footer alla tabella più lunga: `PM_NO_AUTOFILTER`.
- Tutti i widget (indicatori, grafici, tabelle, matrice, presidio, export) dipendono solo dal filtro principale `$f`.
