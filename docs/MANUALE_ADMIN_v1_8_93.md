# Manuale Amministratore — v1.8.93

## L'azienda esecutrice

Il portale sapeva già a quale società appartiene ogni commessa: `exec_company_id`
è popolato su **tutte e 1.062 le commesse**, derivato dal prefisso del codice
(`WTS_3670` → Wetechs) da una logica che esiste dalla sincronizzazione.

Mancava solo di mostrarlo. Nessun calcolo nuovo: un collegamento che prima non
veniva fatto.

## Nella Relazione di Servizio IT

Nuovo filtro **Azienda esecutrice**, nuova voce in *Raggruppa per*, grafico
dedicato e foglio nell'export:

| Azienda | Interventi | Ore |
|---|---|---|
| WETECH'S SPA SB | 13.673 | 62.540,0 |
| Nis Group srl | 2.171 | 5.444,5 |
| Antea srl | 932 | 2.845,5 |
| Wenest SRL | 143 | 1.057,5 |
| Weenergy | 18 | 146,5 |

Combinabile con le altre dimensioni: `Azienda × Codice linea` produce 43 righe, e
la somma resta 72.034,0 h.

## Nel Service Desk

Il riquadro per azienda **compare solo se le aziende sono più di una**.

Sui dati attuali il Service Desk lavora **esclusivamente su commesse WETECH'S** —
4.067 moduli, 11.908,5 ore, 149 commesse, 14 linee — quindi resta nascosto: una
tabella con una riga sola ripeterebbe il totale già mostrato sopra.

**Nell'export il foglio c'è comunque**: in un file di dati «il Service Desk opera
solo su Wetechs» è un fatto, mentre a video sarebbe spazio occupato per nulla.

Se un domani il Service Desk operasse anche su commesse di altre società, il
riquadro comparirebbe da solo.

## Perché il nome e non il prefisso

Avrei potuto raggruppare direttamente su `WTS`, `NIS`, `WEN`, estraendoli dal
codice. Non l'ho fatto per due ragioni.

Il prefisso è una **convenzione di codifica**, il nome è la **società**: in un
report destinato a chi non conosce i codici, «NIS» richiede una legenda.

E la risoluzione prefisso → azienda **esiste già** nel portale. Riscriverla in SQL
avrebbe creato due implementazioni della stessa regola, che divergono al primo
cambio di convenzione — e a quel punto nessuno saprebbe quale delle due è giusta.
