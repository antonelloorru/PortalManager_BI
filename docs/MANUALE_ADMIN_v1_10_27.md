# MANUALE AMMINISTRATORE — v1.10.27 · File Manager

*Sistema › File manager* (solo Super Admin).
- **Navigazione**: un clic sulla cartella la apre; il breadcrumb risale; la casetta torna alla radice del portale.
- **Pulsanti per file**: 👁 Visualizza (i testi sono mostrati come testo, anche HTML e SVG), ⬇ Scarica, ✎ Modifica (file di testo fino a 5 MB).
- **Operazioni**: Carica, Crea cartella, Rinomina, Elimina, ZIP ed Elimina selezionati. Dopo ogni operazione la pagina resta nella stessa cartella e mostra l'esito.
- **Cartelle protette**: `app`, `assets`, `sql`, `uploads`, `vendor` non si eliminano né si rinominano da qui. Per gli aggiornamenti usare la Console › Aggiornamento.
- Tutte le operazioni finiscono nel log applicativo, categoria `FileManager`.
