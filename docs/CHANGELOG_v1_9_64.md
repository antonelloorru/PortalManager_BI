# PortalManager v1.9.64 — Report Certificazioni: link Credly e LinkedIn nell'export

Aggiunte due colonne al Report Certificazioni (`report_certificazioni.php`) e all'export
(CSV/XLSX/PDF/DOCX via ListFilter): **Credly** e **LinkedIn**.

- Credly: badge `https://www.credly.com/badges/<certificate_code>` se il codice è un badge
  UUID (import Credly); altrimenti URL badge a catalogo (`certifications.credly_url`) o
  profilo Credly dip. (`employees.credly_url`).
- LinkedIn: deep link "Aggiungi al profilo" della certificazione
  (`profile/add?startTask=CERTIFICATION_NAME&name=...&issueYear/Month=...&certUrl=...&certId=...`);
  fallback profilo LinkedIn dip. (`employees.linkedin_url`).

A schermo icona cliccabile; nell'export l'URL completo è incluso via `<span>` nascosto
(ListFilter rimuove le icone e legge il testo).

Nessun delta schema (campi esistenti). Query estesa con cert.credly_url, e.credly_url,
e.linkedin_url. QA: php -l OK; link verificati; migration RUN1/RUN2 err=0; schema_version → 1.9.64.

## Hotfix
Rimosso `cert.credly_url` dalla SELECT: la colonna non esiste su `certifications`
(credly_url è su `employees`). Causava "Unknown column 'cert.credly_url'". Il link Credly
usa ora: badge UUID da `certificate_code` -> profilo Credly del dipendente (`employees.credly_url`).
