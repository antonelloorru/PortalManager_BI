# CHANGELOG — v1.10.11 (2026-10-06)

Software 1.10.11 · Schema 1.10.11 · Upgrade `sql/migration_v1_10_11.sql` (pacchetto cumulativo da 1.10.06)

## Service SOC › Consuntivo attività SOC (nuova scheda)
- **Target**: componenti attivi dell'Unità Organizzativa `SOC` (cm_tech_profiles → cm_tech_units, codice `soc.uo_code`).
- **Data fusion**: componenti × moduli di intervento (`v_cm_it_servizio`, perimetro per `employee_id`), con il modello della Relazione di Servizio IT (`ItServiceModel`).
- **Breakdown**:
  - Consuntivo per **Tipologia di contratto** (codice linea, tipologia, modello);
  - Consuntivo per **operatore** (anche i componenti senza moduli nel periodo);
  - Matrice **operatore × tipologia** (ore, classi nel tooltip);
  - KPI: ore totali, ordinarie, fuori orario, reperibilità, non classificate, giornate-uomo, ore a ricavo;
  - Grafici a barre impilate: andamento mensile e operatori per classe di ore.
- Colonne e regole della Relazione IT: ordinarie + fuori orario + reperibilità (+ non classificate) = ore totali; giornate-uomo = operatore + giorno; reperibilità dal rapportino; quota ore, ore a ricavo, extra, viaggio.
- **Filtro principale** (unico): periodo e contratti; «Componente» → il dipendente abbinato; filtri sui ticket (categoria, cliente, commessa SOC, stato, esito, ricerca) → moduli che riportano i ticket filtrati.
- Collegamento «Apri nella Relazione di Servizio IT» con gli stessi operatori, periodo e contratti.
- Export XLSX: fogli «Consuntivo tipologia», «Consuntivo operatori», «Operatore x tipologia».

## Tecnico
- `ItServiceModel::where()`: perimetri opzionali `dipendenti` (id) e `tickets` (codici), non esposti nel pannello della Relazione IT; `incaricatiDipendenti()`.
- `SocModel::membriUo()`, `SocModel::consuntivoFiltri()`.

## Prova (01/12/2025 – 06/10/2026)
8.281,5 h = 5.807,0 ordinarie + 2.474,5 fuori orario; 1.125 giornate-uomo; 8 operatori con moduli + 1 componente senza; identico alla Relazione IT con gli stessi operatori.
