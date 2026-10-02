# Manuale — Relazione di Servizio IT (v1.9.89)

## Utente
- **Dettaglio**: per ogni riga le ore sono divise in ordinarie, fuori orario e reperibilità (la somma è «Ore totali»).
  Le colonne che iniziano con «N.» sono numeri di interventi. La riga Totale coincide con gli indicatori in alto.
- **Giorni lavorati per persona**: Giorni = giorni distinti lavorati; «di cui in reperib.» = giorni con almeno un intervento
  in reperibilità. Le ore seguono la stessa divisione del dettaglio; Valorizzate / Non valorizzate indicano se esiste la tariffa.
- **Dettaglio ore non valorizzate**: elenco per linea, commessa e persona con il motivo:
  - *Commessa senza listino*: la commessa non ha tariffe (linee a canone, presidio, attività interne);
  - *Tariffa mancante per fascia/unità*: la commessa ha un listino ma non per la combinazione indicata (es. «Fascia D · ora»).
- Export XLSX: fogli «Dettaglio» (ore per classe), «Giorni per operatore», «Ore non valorizzate».

## Amministratore
- Per valorizzare le righe «Tariffa mancante» completare il listino della commessa (Commesse → Tariffe) con la combinazione indicata.
