# PortalManager v1.9.65 — Anagrafica: email aziendale non più autocompilata dal browser

## Bug
Nel form dipendente l'Email aziendale mostrava automaticamente l'email dell'utente
loggato (es. antonello.orru@wetechs.it) per tutti i dipendenti, invece della loro.

## Causa
I campi `type="email"` (Email aziendale/personale) venivano **autocompilati dal browser**
con l'email dell'account loggato. Non è un bug di dati: `business_email` è gestita
correttamente lato server e via JS in modifica.

## Fix (front-end)
- `manage_employees.php`: `<form id="empForm" autocomplete="off">`; sui due input email
  `autocomplete="off"` + `readonly` rimosso al focus (blocca l'autofill al render senza
  impedire il popolamento via JS in modifica né la digitazione manuale).
- `employee_profile.php`: `autocomplete="off"` sui campi email.

## QA
- `php -l` OK; nessun delta schema; migration RUN1/RUN2 err=0; schema_version → 1.9.65.
