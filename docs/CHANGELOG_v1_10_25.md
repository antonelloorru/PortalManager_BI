# CHANGELOG — v1.10.25 (2026-10-08)

Software 1.10.25 · Schema 1.10.25 · Upgrade `sql/migration_v1_10_25.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Gestione Commesse › Relazione Tecnici (nuova voce di menu)
Pagina `tech_report.php`, dopo «Relazione di Servizio IT».

**Filtri.** Usa il pannello unificato della Relazione di Servizio IT:
- contratto/PM Project, stato commessa;
- periodo, ricerca, cliente;
- linea, codice linea, settore, azienda, natura;
- tecnico, sede, modalità, fascia oraria, durata.

Si aggiungono due filtri nuovi:
- **Tipologia contratto**, cioè il modello della linea: presidio, a scalare, chiavi in mano, su chiamata, a canone, assistenza, interno, da classificare;
- **Provenienza modulo**: ticket (codice), riferimento libero, da commessa.

**Stampa ed export** in CSV, XLSX, DOCX e PDF per ciascuna scheda.

### Scheda «Tecnici»
- **Riepilogo per tecnico e codice linea**: una riga per tecnico × codice linea, più una riga «Totale» del tecnico quando lavora su più linee. Colonne: Tecnico, Codice linea, N. attività, N. ticket, GG lavorabili, GG uomo lavorati, N. ore lavorate, Fascia di costo.
- **Metriche di dettaglio**, sulle stesse righe: Giornate-uomo, Ore cons., Ordinarie, Fuori orario, Reperib., Extra dich., Presso cl., Remoto, Smart.
- **Moduli di intervento**, suddivisi in Valorizzati e Non valorizzati con il totale (moduli, %, ore, giornate-uomo, tecnici, commesse). Segue il dettaglio per tecnico con la quota di ore non valorizzate. Produzione teorica e valore addebitato compaiono solo con il permesso sui valori.

### Scheda «Rapporti di intervento»
- KPI della provenienza: moduli totali, da ticket (codice), riferimento libero, da commessa, ore.
- **Per tipologia di contratto**: commesse, moduli, provenienza (conteggi e barra), ticket distinti, tecnici, ore. Un clic sulla tipologia la imposta come filtro.
- **Per commessa**, con drill-down:
  - la riga si apre e mostra i moduli della commessa, con data, tecnico, codice linea, modalità, ore, provenienza evidenziata, ticket e attività DGB;
  - il codice porta alla scheda commessa;
  - ogni commessa si esporta da sola in XLSX, CSV o PDF.
- L'opzione «includi il dettaglio dei moduli» aggiunge l'elenco dei moduli a stampa ed export: fino a 50.000 righe in XLSX e CSV, 1.500 in DOCX, PDF e stampa.

## Relazione di Servizio IT
`ItServiceModel` accetta i nuovi filtri `tipologie` e `prov`, finora usati dalla sola Relazione Tecnici. Il comportamento della pagina esistente non cambia.
