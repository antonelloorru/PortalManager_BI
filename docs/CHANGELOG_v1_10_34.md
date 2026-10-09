# CHANGELOG — v1.10.34 (2026-10-09)

Software 1.10.34 · Schema 1.10.34 · Upgrade `sql/migration_v1_10_34.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Relazione Tecnici › Controllo Reperibilità — correzione di fondo
### 1. Regola di correlazione
| Intervento | Regola v1.10.34 |
|---|---|
| Reperibilità | modulo in **modalità Reperibilità**, la stessa del filtro Modalità della pagina, con inizio 18:01–08:59 |
| Giorno successivo | primo modulo **non in reperibilità** dello stesso tecnico, con inizio 09:00–18:00 nel primo giorno lavorativo dopo il turno e non prima della fine dell'intervento in reperibilità |

Viene sostituita la regola della v1.10.33, che considerava reperibilità anche i moduli notturni non segnati come tali.

### 2. Colonne nell'ordine richiesto
Tecnico / Incaricato | Data/Ora Reperibilità | Rif. Modulo Intervento (reperibilità) | Cliente | Codice Commessa | Tipo | Data/Ora Giorno Succ. | Rif. Modulo Intervento (giorno succ.) | Cliente | Codice Commessa | Tipo

Il Cliente e il Tipo del giorno successivo seguono le stesse regole della vista di servizio: cliente della commessa e linea di servizio.

### 3. Intestazioni colorate
| Gruppo | Colore |
|---|---|
| Tecnico / Incaricato | neutro `#475569` |
| Reperibilità | rosso mattone `#A0442C` |
| Giorno successivo | verde `#15803D` |

- I colori valgono nella vista, nella stampa e negli export XLSX, DOCX e PDF; il CSV non ha colori e usa intestazioni con suffisso «(reperibilità)» / «(giorno succ.)».
- `PmReport` ha la nuova opzione di tabella `hcolors` (colore di intestazione per colonna), supportata da `XlsxWriter`, `DocxWriter` e `PdfWriter`.
