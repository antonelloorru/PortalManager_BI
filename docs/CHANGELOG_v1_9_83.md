# PortalManager v1.9.83 — Auto-sync RBAC (catalogo pagine, ruoli, utenti, permessi, menu)

## Problema
Ogni nuova pagina richiedeva patch manuali in quattro punti: voce in `MenuManager`, `Router::PAGES`, riga in
`$page_map` di `manage_permissions.php`, migration con `INSERT` su `role_permissions`. Dimenticarne uno ha prodotto
permessi invisibili e voci di menu non autorizzate (v1.9.82). Nuovi ruoli nascevano senza matrice; nomi pagina
incoerenti (`pagina` / `pagina.php`) davano esiti arbitrari.

## Soluzione
| Componente | Ruolo |
|---|---|
| `app/PermissionCatalog.php` (nuovo) | unico catalogo: etichette curate (ex `$page_map`) + scoperta automatica da menu, router (anche percorsi riservati) e permessi a DB |
| `app/RbacSync.php` (nuovo) | sincronizzazione idempotente (vedi sotto), simulazione, registro |
| `access_control.php` | `RbacSync::autoSync()`: se cambia la firma del codice (menu, router, catalogo, versione) sincronizza a fine richiesta; nel caso normale legge un file (~0,4 ms) |
| `rbac_sync.php` (nuovo, Sistema → Sincronizzazione permessi, solo Super Admin) | Simula / Sincronizza ora, sincronizzazione automatica on/off, **regole di seeding**, catalogo, registro |
| `manage_permissions.php` | matrice costruita dal catalogo (128 pagine, nessuna invisibile), badge **NUOVA** |
| `manage_roles.php` | «Permessi iniziali: copia da ruolo…» e sincronizzazione alla creazione/eliminazione |
| `app/Router.php` | + `import_candidates_linkedin` (voce di menu non anonimizzata, rilevata dalla sincronizzazione), `rbac_sync` riservata |

## Sincronizzazione (idempotente, nessuna concessione implicita)
1. **Catalogo** → tabella `permissions` (`is_page`, provenienza menu/router/curata, sezione, icona, limite codice,
   prima/ultima comparsa); pagine sparite disattivate, permessi conservati.
2. **Nomi** `pagina` → `pagina.php` in `role_permissions` e `user_permissions`; con due righe prevale il divieto.
3. **Orfani**: menu personalizzati di ruoli/utenti eliminati (i permessi di ruolo sono in cascata sulla FK).
4. **Matrice esplicita**: una riga «tutto negato» per ogni ruolo × pagina mancante.
5. **Regole di seeding** (`rbac_seed_rules`): per ruolo, su tutte le pagine nuove / una sezione / una pagina (anche
   futura), con vista-crea-modifica-elimina-esporta. Applicate **una sola volta**, alla comparsa della pagina; mai alla
   prima sincronizzazione; rifiutate se in conflitto con i blocchi del codice. Sostituiscono le migration con INSERT.
6. **Controlli**: voci di menu fuori dal router, permessi senza effetto per i blocchi codificati, utenti attivi con
   ruolo inesistente, override su pagine rimosse, pagine nuove ancora senza ruoli.
Ogni esecuzione in `rbac_sync_log` e nel Log eventi (Permissions).

## Ciclo di vita di una pagina nuova (da ora)
Aggiungerla al menu (`MenuManager::defaultMenu`) e/o al router → al primo accesso dopo l'aggiornamento la pagina entra
nel catalogo, riceve righe negate per ogni ruolo, le regole di seeding concedono quanto previsto, la matrice la mostra
come NUOVA e il report la segnala se nessun ruolo la vede. Descriverla in `PermissionCatalog::sections()` è facoltativo
(etichetta curata).

## QA (dump v1.9.80)
- Prima sincronizzazione: 128 pagine a catalogo, 527 righe esplicite create, tutte a 0 (permessi effettivi invariati:
  446 viste concesse), 11 avvisi reali (10 permessi senza effetto per blocchi codificati, 1 voce non anonimizzata).
- Seconda esecuzione: 0 modifiche.
- Pagina nuova + 3 regole: regole «tutte» e «pagina» applicate, «sezione» non pertinente ignorata; riesecuzione: 0.
- Normalizzazione: `brand` (negato) + `brand.php` (concesso) → `brand.php` negato; `Service_Desk` → `service_desk.php`.
- Ruolo modello: 128 righe copiate, 25 viste = ruolo di origine; eliminazione → righe rimosse.
- Auto-sync via web server: prima richiesta sincronizza (registro «automatica»), seconda 0,4 ms senza esecuzione.
- Matrice ruolo 9: 128 righe, 25 spuntate = DB. Pagina rbac_sync: KPI e catalogo (128 righe).
- Migration RUN1/RUN2 err=0; `php -l` ok.
