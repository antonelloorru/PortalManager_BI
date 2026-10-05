# TECHNICAL DESIGN — v1.9.95
- Dati: `attenzione()` → `pf.id AS project_id, pf.external_link` (pf = cm_projects su project_code); `competenza()` → `pf.external_link`,
  `project_id` = `commessa_id`; `commesse()` → `pf.external_link`.
- Rendering: helper `$linkSp($row)` (link esterno, nuova scheda, rel=noopener) e `$schedaPrj($row)` (`url_safe('project_dashboard', ['id'=>…])`).
- Nessuna modifica a filtri, calcoli, schema.
