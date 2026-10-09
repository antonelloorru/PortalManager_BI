# CHANGELOG — v1.10.31 (2026-10-09)

Software 1.10.31 · Schema 1.10.31 · Upgrade `sql/migration_v1_10_31.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Link SP
Il **Link SP** è il collegamento della commessa al gestionale (`cm_projects.external_link`), lo stesso di Commesse / Progetti e del Report direzionale.

- **Ordinativi Pratix**: nella tabella delle commesse collegate a ogni ordinativo, «SP» a fianco del link Commessa. Se manca, compare in grigio. L'export XLSX (foglio «Commesse collegate») ha la colonna **Link SP** dopo «Commessa».
- **Scheda Commessa**: pulsante **Link SP** a fianco del Codice Commessa nell'intestazione. Se manca, compare «Link SP —».
- Sicurezza: si rendono cliccabili solo gli URL `http(s)`, con apertura `target="_blank"` e `rel="noopener noreferrer"`.
