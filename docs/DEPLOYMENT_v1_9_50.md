# Deployment — PortalManager v1.9.50

1. SQL: eseguire `sql/migration_v1_9_50.sql` (idempotente: seed permessi + bump versione).
2. File: copiare `access_control.php`, `r.php`, `rbac_debug.php` in root; `app/Session.php` in `app/`.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_50.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` + SQL Runner. Stop+Start Apache, Ctrl+F5.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Login con profilo Direttore IT / Coord. Tecnico | il menu mostra e apre Relazione di Servizio IT, Service Desk, Report direzionale, Ordinativi Pratix |
| Recruiter / Team Leader | accedono a Posizioni/Candidature (manage_job_positions, manage_applications) |
| HR | accede a Organigramma |
| Dipendente (role 6) su it_service | resta negato (non previsto dal profilo) |
| `rbac_debug.php?page=it_service.php` con l'utente bloccato | "Causa probabile" = OK/consentito |
| `schema_version` | 1.9.50 |

Nota: se un profilo specifico deve vedere/non vedere una pagina diversa da questo baseline,
regolare in Gestione permessi (le righe sono ora presenti e modificabili).
