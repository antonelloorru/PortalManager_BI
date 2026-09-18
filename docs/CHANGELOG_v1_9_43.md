# Release Notes — PortalManager v1.9.43

Data: 2026-09-08
Allineamento versioni: Software 1.9.43 · Schema 1.9.43 · Upgrade 1.9.43
Sezione: Relazione di Servizio IT (`it_service.php`, `app/it_service_print.php`,
`app/ItServiceModel.php`) · nuovo `app/DocxWriter.php`

## Export Word (.docx)
Nuovo pulsante **Word** e handler `?export=docx`. Generatore `app/DocxWriter.php`
nativo OOXML (ZipArchive + XML, zero dipendenze, stessa filosofia di `XlsxWriter`):
titolo, heading, paragrafi e tabelle con intestazione. Il documento contiene le
sezioni selezionate (vedi sotto). Validato con reader OOXML (python-docx).

## Selezione dei dettagli per stampa/export
Nuovo gruppo di **checkbox** nel pannello Filtri — "Dettagli da includere
(stampa / export)": Quadro/KPI, Andamento mensile, Dettaglio interventi, Giorni per
operatore, Riepilogo costi, Riepilogo per contratto, Dettaglio per commessa.
La selezione viaggia nei link di **Stampa** ed **Export** (`inc[]`) e governa cosa
entra nel report di stampa e nel .docx. Default: tutto incluso.

## Grafico "Andamento mensile"
- **Target line "ore ordinarie lavorative"**: linea orizzontale tratteggiata (verde).
  Valore = media mensile delle ore ordinarie del periodo, oppure override manuale via
  `?target=<ore>`. Presente sia a video sia in stampa.
- **Ore di reperibilità** con **colore distinto** (viola `#7c3aed`): marcatore per mese,
  con legenda dedicata.
- `ItServiceModel::andamento()` ora espone anche `ore_ordinarie`
  (in orario, non reperibilità) e `ore_reperibilita` per mese.

## Contenuto pacchetto
```
VERSION                                 1.9.43
it_service.php               (ROOT)     toggle dettagli, export docx, grafico
app/ItServiceModel.php                  andamento(): +ore_ordinarie, +ore_reperibilita
app/it_service_print.php                grafico (target+reperibilità) + gate sezioni
app/DocxWriter.php                      writer .docx nativo (nuovo)
sql/migration_v1_9_43.sql               allineamento versione (nessun delta schema)
sql/upgrade_1_9_41_to_1_9_43.sql        consolidato ultime 2 versioni -> 1.9.43
docs/                                   changelog, manuali, deployment, technical design
```

## QA
- `php -l` OK su tutti i .php; `if/endif` del template di stampa bilanciati.
- `DocxWriter`: .docx aperto da python-docx (tabelle, heading, testo accentato/entità OK).
- `andamento()` con ore_ordinarie/ore_reperibilita verificato su stub.
- SQL migration/consolidato: RUN1/RUN2 err=0; `;` nei commenti = 0; versioni → 1.9.43.
