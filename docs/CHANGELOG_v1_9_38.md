# Release Notes — PortalManager v1.9.38

Data: 2026-09-07
Allineamento versioni: Software 1.9.38 · Schema 1.9.38 · Upgrade 1.9.38
Sezione: Ordinativi Pratix (`pratix_orders.php`)

## Filtri a tendina Commerciale e Cliente
Nel pannello Filtri due nuovi `<select>` statici (elenco completo, `stato`/`Mostra`
resta filtro parallelo indipendente):

- **Commerciale** — valori distinti da `v_cm_pratix_righe.commerciale`
  (ricavato da `cm_projects.commercial_ref`).
- **Cliente** — valori distinti da `v_cm_pratix_righe.cliente`. Sostituisce il
  precedente campo testo a ricerca parziale con selezione esatta.

Filtro applicato a livello di ordinativo via `EXISTS` sulla vista righe: un
ordinativo compare se contiene almeno una riga che soddisfa il commerciale e/o
il cliente selezionati. Le opzioni sono caricate in un blocco resiliente: se la
colonna `commerciale` non fosse ancora presente (migration non applicata) i
select restano vuoti ma la pagina continua a funzionare.

## Export server-side PDF / CSV / XLSX
Tre pulsanti nel pannello Filtri, tutti coerenti con i filtri attivi:

- **XLSX** — invariato nel formato a 3 fogli (Ordinativi, Commesse collegate,
  Anomalie); il foglio di dettaglio ora include la colonna **Commerciale**.
- **CSV** — nuovo. Elenco ordinativi (grid principale), delimitatore `;` e BOM
  UTF-8 per apertura diretta in Excel IT.
- **PDF** — nuovo. Vista di stampa `pratix_orders_print.php` (A4 orizzontale) con
  quadro, filtri attivi, tabella ordinativi e dettaglio commesse collegate; il
  PDF si ottiene dalla stampa del browser, coerente con gli altri `*_print.php`.

L'intercettazione avviene prima di `header.php`, con buffer svuotati, compressione
disattivata ed `exit` (nessun HTML di layout appeso al binario/CSV).

## Note tecniche
- `app/XlsxWriter.php` è riusato dall'installazione (non incluso nel pacchetto):
  l'export ne usa l'API pubblica `addSheet()` / `download()`, invariata.
- Schema: la migration ri-asserisce `v_cm_pratix_righe` (idempotente) per garantire
  la colonna `commerciale` anche dove la v1.9.37 non fosse stata applicata.

## Contenuto pacchetto
```
VERSION                              1.9.38
pratix_orders.php          (ROOT)    filtri Commerciale/Cliente + export xlsx/csv/pdf
pratix_orders_print.php    (ROOT)    vista di stampa per export PDF
sql/migration_v1_9_38.sql            vista + bump versioni + registrazione
sql/upgrade_1_9_36_to_1_9_38.sql     consolidato ultime 2 versioni -> 1.9.38
docs/                                changelog, manuali, deployment, technical design
```

## QA
- `php -l` OK su `pratix_orders.php` e `pratix_orders_print.php`.
- SQL migration/consolidato: RUN1/RUN2 err=0, idempotenti; `;` nei commenti = 0.
- Post-upgrade: `app_version/schema_version/release_label` = 1.9.38; `commerciale` risolta.
- Export XLSX: file zip valido, 3 fogli, colonna Commerciale presente (verifica su reader OOXML).
- Export CSV: header + righe corretti, BOM + `;`.
