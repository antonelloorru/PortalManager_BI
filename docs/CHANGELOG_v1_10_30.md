# CHANGELOG — v1.10.30 (2026-10-09)

Software 1.10.30 · Schema 1.10.30 · Upgrade `sql/migration_v1_10_30.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Filtro multi-select «Unità Organizzativa»
Nuovo campo a selezione multipla con ricerca (`uo[]`, nei link `uo=1,4`) nel pannello filtri di 8 pagine. Le unità sono quelle attive in *Unità Organizzative Tecniche* (`cm_tech_units`); l'appartenenza è data dai profili attivi dell'Anagrafica tecnica (`cm_tech_profiles`, dipendenti e professionisti esterni).

| Pagina | Oggetto filtrato | Regola |
|---|---|---|
| Commesse / Progetti | commessa | almeno un modulo di intervento o un membro del team appartiene alle unità scelte |
| Report direzionale | commessa | come sopra (quadro, elenchi, attenzione, andamento, perimetro, export) |
| Service Desk | ticket / persona | presa in carico, tecnico o autore del messaggio appartiene alle unità (match per nome) |
| Service SOC | ticket | assegnatario o owner appartiene alle unità |
| Relazione di Servizio IT | modulo | incaricato delle unità |
| Attività & Rendicontazione DGB | allocazione | operatore DGB mappato a un dipendente delle unità (anche anomalie) |
| Carico & Sovrapposizioni | risorsa | dipendenti delle unità, in intersezione con la selezione puntuale delle risorse |
| Relazione Tecnici | modulo | tecnico delle unità |

- Il badge del pannello conta il filtro; il valore è propagato a link, paginazione ed export (XLSX/CSV/DOCX/PDF) e dichiarato nel foglio «Filtri» dove presente.
- Nuova classe `app/PmUoFilter.php`.
