# Manuale — Relazione di Servizio IT: giorni lavorati (v1.9.88)

## Utente
- **Giorni lavorati** conta tutto ciò che è stato eseguito nel periodo, in base alla data del modulo di intervento:
  tutte le linee, anche quelle a canone, presidio e attività interne, qualunque sia oggi lo stato della commessa.
  I numeri coincidono con gli indicatori in cima alla pagina e non cambiano se una commessa viene chiusa in seguito.
- **Ore valorizzate** = con tariffa di listino (concorrono alla produzione teorica). **Ore non valorizzate** = senza tariffa:
  contano nei giorni e nelle ore, non nella produzione.
- Ripartizioni (riquadri apribili sotto la tabella per persona): Codice linea, Area tecnologica, Linea di servizio,
  Cliente, Commessa, Mese, Fascia, Stato commessa. Tutte rispettano i filtri della pagina.
- Per limitare a commesse aperte o chiuse usare il filtro «Stato commessa».
- Export: XLSX con un foglio per ripartizione; Word e stampa con le ripartizioni per codice linea e area tecnologica.

## Amministratore
- Le impostazioni `it_giorni_solo_attive` e `it_giorni_linee_escluse` non sono più applicate (marcate «dismesse»).
- Una tariffa mancante su una linea a produzione fa comparire le relative ore tra le non valorizzate: completare il listino della commessa.
