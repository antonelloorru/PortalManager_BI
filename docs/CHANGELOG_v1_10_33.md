# CHANGELOG — v1.10.33 (2026-10-09)

Software 1.10.33 · Schema 1.10.33 · Upgrade `sql/migration_v1_10_33.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Fix — Relazione Tecnici › Controllo Reperibilità (v1.10.32)

### Difetti
- **Moduli diurni presi per reperibilità**: bastava l'inizio nella fascia 18:01–08:59, quindi rientravano i moduli diurni che iniziano prima delle 09:00. Nei dati sono la maggioranza: 5.032 moduli che iniziano fra le 08:00 e le 08:59, 4.038 dei quali con orario 08:00–17:00. L'«intervento in reperibilità» risultava così la giornata ordinaria. La «Data/Ora Giorno Succ.» cadeva nello stesso giorno e il cliente coincideva, perché era la prosecuzione dello stesso lavoro.
- **Un solo Cliente / Codice Commessa / Tipo**: erano quelli dell'intervento in reperibilità. Il cliente dell'intervento del giorno successivo non era visibile.

### Correzione
- È **intervento in reperibilità** il modulo con inizio 18:01–08:59 che:
  - termina entro le 09:00 del mattino successivo all'inizio del turno; oppure
  - è segnato in reperibilità (`on_call` o modalità Reperibilità, la stessa regola della colonna «Reperib.»).

  I moduli diurni che iniziano prima delle 09:00 sono esclusi.
- **Colonne** (11):
  - Tecnico / Incaricato;
  - per l'intervento in reperibilità: Data/Ora (inizio–fine), Rif. Modulo, Cliente, Codice Commessa, Tipo;
  - per il giorno successivo: Data/Ora (inizio–fine), Rif. Modulo, Cliente, Codice Commessa, Tipo.
- Intestazione a due gruppi colorati.
- Accanto alla data compare «notte del gg/mm» quando l'intervento inizia dopo la mezzanotte: il turno è quello della notte precedente e il giorno successivo può coincidere con la data dell'intervento.

Effetto su settembre 2026 (pmrepo):

| Misura | v1.10.32 | v1.10.33 |
|---|---|---|
| Interventi in reperibilità | 261 | 134 |
| Casi | 88 | 74 |
| Clienti diversi fra i due interventi | — | 42 casi su 74 |
