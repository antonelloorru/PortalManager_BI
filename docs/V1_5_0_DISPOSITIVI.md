# PortalManager 1.5.0 — Tab Dispositivi

## Sintesi

Aggiunta tab "Dispositivi" alla scheda dipendente (`employee_profile.php`) per gestire tutti gli asset aziendali assegnati: telefono, SIM, notebook, veicolo, carta carburante, carta credito.

## Asset gestiti

| Asset | Campi specifici | Eventi correlati |
|---|---|---|
| Telefono aziendale | Marca, Modello, IMEI 1, IMEI 2, S/N | — |
| SIM aziendale | Tipo (voce/dati), Numero, Operatore, S/N, PIN, PUK | — |
| Notebook | Marca, Modello, S/N, SO, Caratteristiche | — |
| Veicolo | Marca, Modello, Targa, Alimentazione, Tipo acquisizione (noleggio/leasing/finanziamento/acquisto), Contratto, Durata, Costo rateo, Km iniziali/attuali, Condizioni | Tagliandi (data, km, costo, descrizione, allegato) |
| Carta carburante | Circuito, Numero, PIN, Veicolo associato | Rifornimenti (data, km, litri, importo, location, allegato) |
| Carta credito aziendale | Circuito, Banca, Ultime 4 cifre, PIN, Plafond | Estratti conto mensili (anno, mese, totale, PDF) |

Tutti gli asset hanno:
- `assigned_at` (data consegna)
- `returned_at` (data ritiro)
- `status` (assegnato/restituito/smarrito/rotto o varianti)
- `notes` (note libere)
- Audit `created_at` + `created_by`

## Tabelle SQL nuove

- `emp_devices_phone`
- `emp_devices_sim`
- `emp_devices_notebook`
- `emp_devices_vehicle`
- `emp_vehicle_service` (tagliandi)
- `emp_devices_fuel_card`
- `emp_fuel_log` (rifornimenti)
- `emp_devices_credit_card`
- `emp_credit_card_statement` (estratti conto)

Tutte con FK CASCADE su `employees`.

## UI

### Tab "Dispositivi"
6 sezioni accordioniche, una per categoria. Ogni sezione mostra:
- Lista degli asset assegnati (in uso in alto, restituiti sotto)
- Bottone "Nuovo" (modal con form dinamico)
- Bottoni Modifica/Elimina per riga
- Sub-eventi (tagliandi/rifornimenti/estratti) come `<details>` espandibili con form di aggiunta inline

### Toolbar superiore
- **Esporta Excel** → `device_export.php?employee_id=N`
- **Stampa scheda** → `device_print.php?employee_id=N`
- **Importa** → `device_import.php?employee_id=N` (solo Admin/HR)

(I tre endpoint saranno consegnati in v1.5.1)

### Modal universale
Un singolo modal JavaScript con form dinamico generato da configurazione `devForms[type]`. Riconosce 7 tipi (phone, sim, notebook, vehicle, fuel_card, credit_card) e popola i campi dal record selezionato per la modifica.

## File pacchetto

- `employee_profile.php` (esteso con handlers POST + query + UI tab)
- `sql/migration_v1_5_0.sql` (9 tabelle nuove)
- Cartella `uploads/devices/` (creata automaticamente al primo upload)

## Note prossimo step (v1.5.1)

- `device_export.php` → export Excel multi-sheet (uno per categoria)
- `device_print.php` → vista print-friendly stampabile
- `device_import.php` → import bulk da Excel
- `note_spese.php` → tab Nota spese replica modello Excel + esporta come da template
