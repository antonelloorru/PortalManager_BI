# Technical Design — v1.9.82 — Visibilità voci di menu

## Regola unica (`MenuManager::canSee(page, role, user)`)
1. Super Admin → visibile.
2. `HARD_GATES[page]` e ruolo > N → nascosta (la pagina lo rifiuterebbe comunque).
3. `user_permissions` (utente): `MIN(can_view)` sulle righe non NULL → prevale.
4. `role_permissions` (ruolo): `MIN(can_view)`; nessuna riga → nascosta.
Voci `always_visible` (Dashboard, Profilo) sempre mostrate. Usata da header (menu) e Personalizza menu.

## HARD_GATES
| Pagina | Ruoli ammessi dal codice |
|---|---|
| manage_roles, manage_permissions, entity_change_log, view_logs, system_console, system_errors | 1 |
| manage_technologies, tech_skill_matrix, manage_enum_proposals, mass_upload* | ≤ 2 |
| project_import | ≤ 3 |
Da aggiornare se cambia il controllo nella pagina.

## Matrice permessi completa
`$page_map` + sezione «Altre pagine (non classificate)» = permessi a DB del ruolo/utente ∪ voci di menu ∪
`Router::PAGES` non classificate (escluse pagine pubbliche e sempre consentite).

## Audit — permessi su pagine non presenti nella matrice pre-1.9.82
```sql
SELECT rp.role_id, r.name, rp.page_name
  FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
 WHERE rp.can_view = 1 AND rp.role_id <> 1
   AND rp.page_name IN ('pratix_orders.php','service_desk.php','it_service.php','dir_report.php','organigramma.php',
                        'tech_registry.php','tech_units.php','sync_commesse.php','system_errors.php',
                        'relazione_servizio_it.php','report_servizi_it.php','manage_applications.php','manage_job_positions.php')
 ORDER BY rp.role_id, rp.page_name;
```
## ER
`roles 1—N role_permissions(role_id, page_name, can_*)`, `users 1—N user_permissions(user_id, page_name, can_* NULL = eredita)`,
`role_permissions_backup(version, role_id, page_name, can_*, reason)`.
