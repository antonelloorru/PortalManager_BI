# CHANGELOG — v1.10.36 (2026-10-09)

Software 1.10.36 · Schema 1.10.36 · Upgrade `sql/migration_v1_10_36.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Service Desk › nuova voce «Ticket e Attività dei clienti»

### 1. Collocazione nella vista
| Dove | Come |
|---|---|
| Pagina | quinta scheda accanto ai quattro indicatori del quadro, con bordo tratteggiato e dicitura «voce separata · esclusa dai totali dei moduli» |
| Stampa | blocco a sé sotto «Quadro del periodo» |
| Report PDF / DOCX / XLSX / CSV | sezione «Ticket e Attività dei clienti» con ticket, attività e ore |
| Dati XLSX | righe nel foglio «Filtri» |

### 2. Regola di filtro contrattuale
- **Attività** = moduli di intervento dell'UO Service Desk (componenti del team) sul contratto **WTS_3119** «Help Desk per clienti Wetech's», nel periodo.
- **Ticket** = codici ticket distinti di quei moduli.
- Si applicano i filtri di pagina (periodo, componente, Unità Organizzativa, contratto): con un filtro contratto che non comprende WTS_3119 la voce vale 0.

### 3. Vincolo di scorporo
I moduli WTS_3119 dell'UO Service Desk sono esclusi da tutti gli aggregati dei moduli della pagina:
- Analisi del Team (quadro, dettaglio, fasce, tipologie di contratto);
- operatività dei componenti;
- moduli per codice linea e per azienda esecutrice;
- scheda del componente;
- OBJ_2.1 / 2.2 / 2.3.

La voce non viene sommata a questi totali. Le note di «Analisi del Team» e di «Attività del Service Desk» indicano quanti moduli sono esclusi.

Non sono toccati:
- gli indicatori sui ticket (archivio ticket);
- il «Riepilogo costi», che ha un perimetro più ampio dell'UO Service Desk.

## Correzione
- `app/XlsxWriter.php`: i fogli vengono costruiti prima di `sharedStrings.xml`. I valori testuali numerici con zero iniziale producevano indici fuori tabella e il file XLSX risultava corrotto (export «Dati XLSX» del Service Desk).
