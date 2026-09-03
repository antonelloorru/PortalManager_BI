# PortalManager 1.4.1 — Allineamento scheda dipendente ↔ candidato

## Sintesi

`employee_profile.php` riscritto come **clone strutturale** di `candidato_profilo.php`. Stessa identica:
- Struttura HTML (classi `.card`, `.card-header`, `.card-title`, `.grid-2`, `.form-group`, `.span-2`)
- Stile header (avatar 56px gradient, contatti inline, badge status, pill)
- Layout tab nav (pill style con shadow su attivo)
- Layout 2 colonne nei form (`grid-template-columns:1fr 1fr;gap:20px`)
- Pattern soft skills toggle (chip clickabili con feedback visivo `.soft-tag`)
- Pattern certificazioni (badge inline + tabella `.data-table`)
- Pattern documenti (`person_documents` con icone tipo + dimensioni + azioni)

## Tab → Tab — confronto entità

| Candidato | Dipendente |
|---|---|
| Anagrafica (dati personali + istruzione + note HR) | Anagrafica (idem, stessa struttura, stessi campi) |
| Competenze (skill tech + soft + cert dichiarate) | Competenze (skill tech + soft + cert acquisite) |
| Documenti (`person_documents` + upload veloce) | Documenti (`person_documents` + upload veloce) |
| Candidature | Inquadramento HR (azienda/sede/contratto) |
| Scorecard colloquio | Storico modifiche (`entity_change_log`) |

## Campi anagrafica allineati

Stessa identica disposizione 2 colonne, ordine:
1. Nome / Cognome
2. Email aziendale / Telefono aziendale
3. Email personale / Telefono personale
4. LinkedIn (span-2)
5. Credly (span-2)
6. Matricola / Codice fiscale
7. Data di nascita
8. Bio (span-2)

Sezione Istruzione: titolo studio, indirizzo/facoltà, istituto, anno conseguimento (stessi label, stesse opzioni dropdown).

Sezione Note HR (full-width, span-2) — visibile solo a Admin/HR.

## Funzionalità

- **Sync auto Credly/LinkedIn**: salvataggio URL aggiorna `employee_credly_link` / `employee_linkedin_link` (per import futuri)
- **Cascade Azienda → Sede**: funziona via `data-cascade` (scope_filters.js + api_filters.php)
- **Upload documenti su `person_documents`**: stesso schema del candidato, supporta CV / lettera / contratto / certificato / documento identità / altro
- **Soft skills toggle**: stesso comportamento JS del candidato, persistenza in `employees.soft_skills` (CSV)
- **Self-edit dipendente**: utente non admin sul proprio profilo può modificare campi safe (contatti, social, bio, istruzione, competenze, CV) — campi HR (Inquadramento) restano read-only

## File pacchetto

- `employee_profile.php` (NUOVO, v1.4.1) — clone strutturale del candidato
- `manage_employees.php` — bottone matita → link a `employee_profile.php`
- `app/Router.php` — whitelist slug
- `sql/migration_v1_4_1.sql` — 4 colonne education + RBAC + bump versione

## Installazione

```powershell
cd P:\xampp\htdocs\portalmanager
Expand-Archive -Path "PortalManager-1.4.1-allineamento-candidato.zip" -DestinationPath . -Force
```

phpMyAdmin → Importa → `sql/migration_v1_4_1.sql`

## Verifica visiva

Apri:
- `candidato_profilo.php?id=<X>` (riferimento)
- `employee_profile.php?id=<Y>` (allineato)

I due dossier devono apparire **strutturalmente identici**:
- Stesso header (avatar, contatti inline, badge)
- Stesse tab pill di navigazione
- Stesso layout dei form (2 colonne, card con header/title icon)
- Stessa logica soft skills (12 chip toggle)
- Stessa visualizzazione documenti (tabella `person_documents`)
- Stessa palette di colori e iconografia FontAwesome
