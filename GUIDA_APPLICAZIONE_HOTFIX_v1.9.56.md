# Guida Operativa Applicazione Hotfix — PortalManager v1.9.56

## 1. Riepilogo Hotfix v1.9.56 (Audit RBAC, Dizionario Permissions & Allineamento "Personalizza Menu")

La release **v1.9.56** implementa un audit completo e il refactoring del motore RBAC (Role-Based Access Control) in relazione al modulo **Personalizza Menu** (`menu_customizer.php`), risolvendo l'anomalia di mancata registrazione a dizionario e la rottura di propagazione delle configurazioni sui diversi ruoli di sistema.

| Componente / File | Problema Risolto | Soluzione Applicata |
|---|---|---|
| `migration_menu_permissions.sql` | Mancanza a database della tabella `menu_preferences` e delle voci nel catalogo `permissions` e nella matrice `role_permissions`. | Creato script SQL idempotente con DDL tabelle `menu_preferences` e `permissions`, registrazione di `menu_customizer.php`, alias `menu.customize`, capability `can_customize_menu` e seed ruoli. |
| `cert_management.sql` & `sql/cert_management.sql` | Baseline database disallineata: nuove installazioni non avevano le tabelle `menu_preferences` e `permissions` censite. | Integrate le definizioni DDL di `menu_preferences`, `permissions` e i relativi record di default in `role_permissions` (Ruoli 1 Super Admin e 2 HR Director). |
| `db_upgrade.php` & `UpdateControl/db_upgrade.php` | Assenza della release 1.9.56 nel motore di migrazione guidata dello schema database. | Registrata versione `1.9.56` con DDL idempotente di creazione tabelle, inserimento permessi e bump versioni `app_version`, `schema_version`, `release_label`. |
| `access_control.php` | 1. Mancanza di auto-guarigione schema se le tabelle `menu_preferences` e `permissions` sono assenti.<br>2. Assenza di un metodo policy standard ad alto livello `hasPermissionTo()`. | 1. Auto-migrazione idempotente all'avvio del portale (esegue `migration_menu_permissions.sql` se le tabelle mancano).<br>2. Implementata la funzione `hasPermissionTo(string $permission, string $action = 'view'): bool` con supporto mapping alias (`menu.customize`, `can_customize_menu`) verso `can($action, $page)`. |
| `app/MenuManager.php` | 1. **Bypass RBAC**: la voce `menu_customizer` era forzata con `'always_visible' => true` nel defaultMenu.<br>2. Il sanitizer sovrascriveva etichette e icone personalizzate di sezioni e voci.<br>3. `userCanSee()` non gestiva in modo trasparente nomi pagina con o senza estensione `.php`. | 1. Rimosso `'always_visible' => true`, rendendo la voce rigorosamente soggetta al controllo `can('view', 'menu_customizer.php')`.<br>2. Aggiornato `sanitizeConfig()` per preservare etichette e icone custom.<br>3. Normalizzati i controlli RBAC con e senza suffisso `.php`. |
| `menu_customizer.php` | 1. Assenza di guard di accesso per ruoli non-admin che possiedono il permesso esplicito.<br>2. I ruoli personalizzabili erano cablati ad un array statico di 6 elementi (`range(1, 6)`), escludendo i ruoli dinamici con ID > 6.<br>3. Durante la configurazione di un ruolo target, l'editor mostrava tutte le pagine perché `user_role_can_see()` testava il Super Admin in sessione anziché il ruolo target.<br>4. Mancavano verifiche autorizzative sul salvataggio POST. | 1. Inserito guard: `if (!$is_admin && !can('view', 'menu_customizer.php'))` redirect a `unauthorized`.<br>2. Dropdown ruoli popolato dinamicamente con query `SELECT id, name FROM roles ORDER BY id`.<br>3. Funzione `user_role_can_see()` corretta per verificare i permessi effettivi del ruolo target.<br>4. Aggiunto guard autorizzativo stringente sulle azioni POST di salvataggio/ripristino per utenti e ruoli. |
| `header.php` | Il pulsante di personalizzazione rapida (icona bacchetta magica) nella barra superiore era renderizzato per qualsiasi utente autenticato senza verificare i permessi RBAC. | Condizionato il rendering del pulsante a: `if ($is_admin || can('view', 'menu_customizer.php'))`. |
| `manage_permissions.php` | L'etichetta nel catalogo permessi era generica o non descrittiva delle funzionalità reali. | Aggiornata con label `Personalizza menu` e descrizione esplicativa: `Personalizzazione ordine, visibilità e sezioni della navigation bar`. |
| `manage_roles.php` | Mancanza di scorciatoia per la personalizzazione del menu dalla gestione ruoli e rischio record orfani. | 1. Aggiunto pulsante rapido per accedere direttamente alla personalizzazione del menu del ruolo target.<br>2. Inserita pulizia delle preferenze su cancellazione ruolo (`DELETE FROM menu_preferences WHERE scope_type='role' AND scope_id=?`). |
| `VERSION` | Versione codebase non aggiornata. | Aggiornato a `1.9.56`. |

---

## 2. Procedura di Backup Pre-Aggiornamento

Prima di procedere all'aggiornamento, eseguire il backup di sicurezza (DB + File):

```powershell
cd "G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI"
powershell -ExecutionPolicy Bypass -File tools\safe_backup.ps1
```

Oppure con PHP CLI:
```powershell
C:\xampp\php\php.exe tools\cli_backup.php
```

---

## 3. Modalità di Applicazione del Pacchetto `update_v1.9.56.zip`

### Opzione A: Installazione Automatica via Web Updater (Consigliata)
1. Accedi a PortalManager come **Super Admin** (`role_id = 1`).
2. Naviga in **Console di Sistema** -> Scheda **Aggiornamento** (`system_console.php?tab=update`).
3. Carica il pacchetto `update_v1.9.56.zip`.
4. Il sistema verificherà l'integrità del manifest ed estrarrà tutti i file aggiornati.

### Opzione B: Estrazione Manuale dell'Archivio
1. Estrai il contenuto di `update_v1.9.56.zip` direttamente nella cartella root del portale (es. `G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI\`), confermando la sovrascrittura dei file.
2. Le tabelle SQL e i permessi verranno creati/aggiornati automaticamente al primo accesso al portale (tramite l'auto-migrazione in `access_control.php`).
3. In alternativa, puoi eseguire la migrazione manualmente importando il file `migration_menu_permissions.sql` da phpMyAdmin o MySQL CLI, oppure aprendo `db_upgrade.php` ed eseguendo l'allineamento guidato alla versione `1.9.56`.

---

## 4. Matrice Ruoli e Permessi — Collaudo e Verifica Funzionale

1. **Pannello Gestione Permessi (`manage_permissions.php`)**:
   - Accedi come Super Admin e apri **Gestione Permessi**.
   - Individua la riga **Personalizza menu** (`menu_customizer.php`).
   - Verifica che sia possibile abilitare o revocare la spunta di visualizzazione/modifica per ciascun ruolo (es. Ruolo 2 HR Director abilitato, Ruoli operativi disabilitati).
   - Clicca **Salva Permessi** e verifica il corretto salvataggio a DB.

2. **Verifica Visibilità Topbar e Sidebar**:
   - Con un utente appartenente a un ruolo **con permesso** (o Super Admin):
     - L'icona della bacchetta magica è visibile nella topbar accanto al profilo.
     - La voce "Personalizza menu" compare nella sidebar di navigazione.
   - Con un utente appartenente a un ruolo **senza permesso**:
     - L'icona della bacchetta magica **NON** compare nella topbar.
     - La voce "Personalizza menu" **NON** è presente nella sidebar.
     - Tentando l'accesso diretto via URL (`menu_customizer.php`), l'utente viene bloccato e reindirizzato alla pagina di accesso negato (`unauthorized`).

3. **Verifica Propagazione Menu su Ruoli e Ruoli Dinamici (ID > 6)**:
   - Come Super Admin, apri `menu_customizer.php`.
   - Seleziona un ruolo dal selettore (inclusi eventuali ruoli personalizzati con ID superiore a 6).
   - Modifica l'ordine o la visibilità delle voci e clicca **Salva Configurazione Ruolo**.
   - Effettua il login con un utente associato a quel ruolo: il menu di navigazione renderizzerà esattamente la struttura personalizzata configurata per il ruolo.
