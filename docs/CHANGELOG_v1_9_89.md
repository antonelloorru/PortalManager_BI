# CHANGELOG — v1.9.89

Relazione di Servizio IT — correzioni su segnalazione dopo la v1.9.88.

## 1. Reperibilità nel «Dettaglio» (es. per linea di servizio) inferiore al conteggiato
Causa: le colonne «Reper.» e «F.orario» del dettaglio mostravano il **numero di interventi**, non le ore
(settembre 2026: 14 interventi contro 58,5 h di reperibilità nei KPI).
- Il dettaglio mostra ora **Ore ordinarie, Ore fuori orario, Ore reperibilità** (stessa regola di KPI e andamento:
  ordinarie + fuori orario + reperibilità = ore totali) e, separato, «N. interv. reperib.».
- Le altre colonne di conteggio sono etichettate «N.» (presso cliente, remoto, smart).
- Riga **Totale** in fondo: coincide con i KPI (settembre: 7.224 + 609 + 58,5 = 7.891,5 h).
- Il rapportino della riga è agganciato per id (`report_id`) e non più per codice modulo: con più tecnici sullo
  stesso modulo si leggevano orari e reperibilità del primo tecnico.
- Stessa struttura in XLSX, Word e stampa.

## 2. Giorni lavorati per persona: colonne «C / D» ambigue
Le colonne erano giorni per **fascia tariffaria** (C = ordinaria, D = extra) ricavata dal tipo attività, una regola
diversa da quella delle ore in reperibilità. Sostituite da:
- **Giorni** (totale) e **di cui in reperibilità** (giorni con almeno un intervento in reperibilità);
- **Ore totali = Ordinarie + Fuori orario + Reperibilità** (stessa regola del resto della pagina);
- **Valorizzate / Non valorizzate**; produzione teorica.
- Riga **Totale**; KPI della sezione: «Ore fuori orario» e «Ore reperibilità» al posto di «Giorni in fascia C/D».
- Le fasce tariffarie restano nell'XLSX con etichetta esplicita «Giorni fascia tariffaria …».

## 3. Dettaglio delle ore non valorizzate
Nuovo riquadro «Dettaglio ore non valorizzate» (aperto, sotto le ripartizioni): codice linea, commessa, cliente,
persona, interventi, giorni, ore, **motivo** («Commessa senza listino» / «Tariffa mancante per fascia/unità» con le
combinazioni fascia · unità mancanti), riepilogo per motivo e per codice linea. Foglio XLSX «Ore non valorizzate»
completo; tabella in Word e stampa.

## Verifica (settembre 2026)
KPI = dettaglio = giorni per persona: 7.224,0 h ordinarie, 609,0 fuori orario, 58,5 reperibilità.
Non valorizzate 3.898,5 h = somma del dettaglio (3.875,0 commessa senza listino + 23,5 tariffa mancante).
