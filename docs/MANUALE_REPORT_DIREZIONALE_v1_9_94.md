# Manuale — Report direzionale (v1.9.94)

## Utente
- **Periodo**: Filtri → Data Inizio / Data Fine. Restano le commesse attive nel periodo; l'andamento mostra i mesi del periodo;
  la competenza è calcolata sul periodo. Senza date: portafoglio corrente, ultimi 12 mesi, intera durata.
- **Unità**: importi in €, ore in h, giorni in gg.
- **FIDO**: indica una commessa con fido (sforamento consentito) su valore o su costi; passa il mouse per gli importi.
- **Valore ordini per competenza**: il valore di ogni ordine cliente è distribuito in quote mensili uguali sulla durata della commessa
  (dalla data dell'ordine, se successiva all'inizio). I riquadri mostrano il totale per anno; la tabella il valore e i mesi per anno di
  ciascuna commessa. Clic sul codice commessa per vedere il calcolo di ogni ordine (importo / mesi = quota mensile × mesi dell'anno).
  Esempio: 100.000 € su 28 mesi (06/2024–09/2026) → 2024: 25.000,00 € · 2025: 42.857,14 € · 2026: 32.142,86 €.
- **Export XLSX**: fogli «Competenza per anno», «Competenza per commessa», «Competenza per ordine».

## Amministratore
- La competenza usa le operazioni di commessa importate (Ordine cliente, Riporto); le commesse senza ordini usano il valore contrattuale.
- Date di inizio/fine commessa errate falsano la ripartizione: correggerle in Commesse / Progetti.
