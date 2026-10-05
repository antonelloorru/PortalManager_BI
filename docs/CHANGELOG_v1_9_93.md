# CHANGELOG — v1.9.93

## Commesse / Progetti — rimosso il filtro aggiuntivo
- Sopra la tabella compariva una seconda barra («Cerca in tutta la tabella / Filtri / Viste / Esporta») aggiunta in automatico
  da `footer.php` (ListFilter::renderAuto) alla tabella con più righe. Filtrava solo le righe a video: non aggiornava il conteggio
  «N di M commesse», non era rispettata da Esporta XLSX/CSV e non era sincronizzata con il pannello «Filtri di ricerca».
- `manage_projects.php`: `$GLOBALS['PM_NO_AUTOFILTER'] = true` prima di `header.php`. Resta solo il pannello «Filtri di ricerca»
  (server-side, ogni colonna filtrabile, rispettato dagli export).
- Stesso intervento già applicato a Relazione IT (v1.9.90), Service Desk, Report direzionale, Attività & Rendicontazione DGB (v1.9.91).
