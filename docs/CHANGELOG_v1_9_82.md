# PortalManager v1.9.82 — Audit RBAC del menu (Responsabile Commerciale)

## Diagnosi (dump v1.9.80, utente alessandro.macinai@wetechs.it, ruolo 9)
Il filtro del menu (`MenuManager::filterByPermissions`) legge correttamente `role_permissions`: ogni voce
mostrata aveva un permesso a DB. Il difetto era a monte e ai lati:

| # | Causa | Effetto sul ruolo 9 |
|---|---|---|
| 1 | **Permessi invisibili**: `manage_permissions.php` elenca solo le pagine di `$page_map`; 10 voci di menu (Ordinativi Pratix, Service Desk, Relazione IT, Report direzionale, Organigramma, Anagrafica tecnici, Unità tecniche, Sincronizzazione, Errori di sistema…) non c'erano | 5 voci visibili nel menu ma «non assegnate» in Permessi e **non revocabili**; 4 permessi nascosti su pagine non di menu |
| 2 | **Gate codificati**: pagine con `if ($u_role > N) → unauthorized` mostrate nel menu in base al solo permesso | Tecnologie, Skill matrix, Import progetti visibili ma inaccessibili |
| 3 | Salvataggio della matrice = `DELETE` + reinserimento delle sole righe mostrate | salvare un ruolo cancellava in silenzio i permessi non elencati |
| 4 | `userCanSee()` con `LIMIT 1` senza ordinamento su righe `pagina` / `pagina.php` | esito arbitrario con righe discordanti |
| 5 | `role_permissions` con `DEFAULT 1` su view/create/edit/export | un INSERT che omette i flag concede l'accesso (es. seed `menu_customizer.php`) |
| 6 | Personalizza menu: ignorava override utente e gate; per il menu di un altro utente filtrava col ruolo dell'amministratore | elenco con voci non accessibili all'utente configurato |
| 7 | Etichetta ruolo nel topbar fissa per i ruoli 1–6 | il ruolo 9 compariva come «Utente» |

## Correzioni
- `app/MenuManager.php`: `HARD_GATES` + `hardGated()`; `canSee()` regola unica (gate → override utente → ruolo);
  divieto prevalente su righe duplicate (`MIN`); voci di configurazioni salvate ammesse solo se presenti nel
  catalogo, etichetta/icona dal catalogo.
- `manage_permissions.php`: 10 pagine aggiunte alla matrice nelle rispettive sezioni; sezione automatica
  **«Altre pagine (non classificate)»** con ogni pagina che ha un permesso a DB, ogni voce di menu e ogni pagina del
  router non classificate → nessun permesso invisibile, nessuna cancellazione silenziosa al salvataggio;
  avviso «⚠ limitata dal codice ai ruoli ≤ N» sulle pagine con gate.
- `menu_customizer.php`: stessa regola del menu (`MenuManager::canSee`), ruolo dell'utente configurato.
- `header.php`: nome del ruolo dalla tabella `roles`.
- Migration: ruolo **Responsabile Commerciale** — azzerati i permessi non assegnabili (pratix_orders, service_desk,
  it_service, dir_report, organigramma, relazione_servizio_it, report_servizi_it, manage_applications,
  manage_job_positions) con copia in `role_permissions_backup`; default dei flag a **0** (nega per impostazione predefinita).

## Menu del ruolo 9
- Prima: 30 voci. Dopo: 22.
- Rimosse: Ordinativi Pratix, Service Desk, Relazione IT, Report direzionale, Organigramma (permessi non assegnati),
  Tecnologie, Skill matrix, Import progetti (gate codificati).
- Le voci rimosse per la causa 1 sono ora nella matrice e si possono riassegnare consapevolmente
  (es. Report direzionale → scheda commerciale per agente).

## Da rivedere (altri ruoli, non modificati)
Permessi su pagine prima assenti dalla matrice: ora visibili in Permessi, da confermare o revocare —
ruolo 8 Direttore IT e 10 Coordinatore Tecnico (Service Desk, Relazione IT, Report direzionale, Ordinativi Pratix,
Organigramma), 2/3/5/7/11 (Organigramma), 6 Dipendente (2fa_settings, sempre consentita).
Query di controllo in TECHNICAL_DESIGN.

## QA (dump v1.9.80)
- Menu ruolo 9 simulato con `MenuManager` reale: ogni voce superata da `can()` (salvo Dashboard/Profilo, sempre visibili).
- Matrice ruolo 9: 121 righe, 25 spuntate = 25 permessi `can_view` a DB; 14 avvisi di gate; sezione «Altre pagine» con 3 voci.
- `canSee`: override utente di divieto rispettato; gate su manage_technologies/project_import/manage_permissions.
- Migration RUN1/RUN2 err=0; riassegnazione dopo la migration non annullata da una riesecuzione; `php -l` ok.
