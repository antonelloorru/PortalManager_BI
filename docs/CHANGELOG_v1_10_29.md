# CHANGELOG — v1.10.29 (2026-10-09)

Software 1.10.29 · Schema 1.10.29 · Upgrade `sql/migration_v1_10_29.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

Tre ambiti distinti. **Descrizione tariffa** vuol dire la fascia oraria del modulo più l'unità di misura, per esempio «Fascia C (Ora)» o «Fascia D (Giornata)». La formula è la stessa del «Riepilogo costi» della Relazione di Servizio IT e del Service Desk (`v_cm_sd_costi_valorizzati.descrizione_tariffa`). Non va confusa con la **fascia di costo** del tecnico (Junior, Senior, Master…).

## 1. Relazione Tecnici
- **Tabella** «Riepilogo per tecnico e codice linea»: la colonna *Fascia di costo* è sostituita da **Descrizione tariffa**, che riporta le descrizioni distinte dei moduli del tecnico su quella linea. Vale per vista, stampa ed export.
- **Filtro**: nuovo campo a selezione multipla **Descrizione tariffa** nel pannello «Filtri», gruppo «Contratto e stato commessa». Si applica a KPI, tabelle, valorizzati, rapporti, stampa ed export; i valori scelti compaiono tra i filtri dei file.

## 2. Commesse / Progetti
- **Filtro «Tipo»** a selezione multipla con ricerca: è la colonna *tipo* dell'elenco, cioè la linea di servizio, ad esempio WTS-CSS, WTS-PRES, NV_AI. Sostituisce il vecchio menu «Linea di servizio» a scelta singola.
- Vale per elenco, conteggio ed export XLSX/CSV. Restano validi i link esistenti con `sl=<valore>`.

## 3. Scheda Progetto › Consuntivo
- Nella tabella dei rapporti la colonna *Fascia* è sostituita da **Descrizione tariffa**.
- La fascia di costo resta visibile nel dettaglio del rapporto, che si apre con la freccia.
