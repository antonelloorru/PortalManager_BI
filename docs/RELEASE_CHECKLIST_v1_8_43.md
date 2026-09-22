# Release Checklist — PortalManager v1.8.43

Policy **zero-omission**: ogni voce verificata con evidenza.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — contiene `1.8.43` |
| `import_employees_xlsx.php` | ROOT, modificato | OK |
| `app/EmployeeImportSchema.php` | **nuovo** | OK |
| `app/ListFilter.php` | modificato | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_43.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_43.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] In `app/` solo i file modificati più il nuovo schema
- [x] `XlsxWriter.php`, copiato in sandbox per generare i template di prova, **non**
      incluso nel pacchetto: non è stato modificato
- [x] Dipendenza dichiarata: `import_employees_xlsx.php` richiede
      `app/EmployeeImportSchema.php`, evidenziata nella guida di deployment
- [x] ZIP con separatore forward-slash
- [x] ZIP della versione precedente rimosso da `/mnt/user-data/outputs/`
- [x] File completi e già patchati, nessuno snippet o diff

## 2. Versionamento coeso

- [x] `VERSION` = `1.8.43`
- [x] `app/Version.php` → `PM_VERSION` = `1.8.43`
- [x] `app_settings` = `1.8.43`, verificato a valle dell'esecuzione su `pm_q2`
- [x] Nessuna riscrittura di versioni già rilasciate

## 3. QA funzionale — export di tutti i record

Verifica eseguita sulla funzione JavaScript **estratta dal file di release**, non
su una riscrittura, applicata a un DOM simulato con 6 righe di cui 3 nascoste dai
filtri e 1 di servizio.

| Verifica | Esito |
|---|---|
| Export in ambito "filtrate" | 2 righe (atteso 2) **OK** |
| Export in ambito "tutti" | 5 righe (atteso 5) **OK** |
| Intestazioni identiche nei due ambiti | **SI** |
| Riga di servizio (`lf-noskip`) esclusa in entrambi | **SI** |

- [x] Il predicato su `lf-noskip` resta incondizionato: le righe di servizio non
      sono dati e non vanno esportate in alcun ambito
- [x] Nessuna chiamata al server aggiuntiva: il filtro è client-side e le righe
      sono già nel DOM

## 4. QA funzionale — allineamento template / import

Prove eseguite con il **parser reale** estratto dal file di release.

| Verifica | Esito |
|---|---|
| Colonne del tracciato | 32 con Compensation, 30 senza |
| Template XLSX generato | 5.046 byte, firma `PK`, 2 fogli |
| Intestazioni rilette = generate | **SI** |
| Intestazioni del template non riconosciute dal parser | **0** |
| Campi del tracciato non coperti dal template | **0** |
| Collisioni di normalizzazione | **0** |
| Intestazioni dei tracciati precedenti accettate | **10 / 10** |
| Template CSV | BOM UTF-8, separatore `;`, 32/32 riconosciute |
| Colonne retributive escluse senza permesso | **SI** (RAL e premio assenti) |
| Ordine identico sul prefisso comune fra le due varianti | **SI** |

## 5. QA funzionale — import end-to-end

Template compilato con tre casi e importato su DB reale (dump `demo`, 286
dipendenti), replicando la logica dell'importer:

| Verifica | Esito |
|---|---|
| Colonne riconosciute in lettura | **32 / 32** |
| Righe interpretate | 3 / 3 |
| Esito import | 2 nuovi, 1 aggiornato |
| Campi scalari verificati sul record nuovo | 23, **discordanti 0** |
| Lookup risolti | `company_id=1`, `location_id=2`, `work_mode_id=3`, `department_id=3` |
| Aggiornamento per codice fiscale | mansione e livello aggiornati |
| **Celle vuote non sovrascrivono** | RAL preesistente **conservata** |
| Riga con soli Cognome/Nome | importata |
| Campi scritti inesistenti in `employees` | **0** (33 campi) |

## 6. Difetti individuati e corretti in collaudo

- [x] **Collisione `% Part-time` / `Part-time`**: entrambe normalizzavano a
      `part_time`, la percentuale sarebbe finita nel campo booleano. Corretto
      traducendo `%` in `perc`; aggiunta la guardia `collisions()`.
- [x] **Normalizzazione divergente**: `normalize_header()` applicava una regola
      diversa da quella della mappa. Ora delega allo schema.
- [x] **Sinonimo `%PT` perduto**: aggiunto, legacy da 9/10 a **10/10**.
- [x] Valori di esempio del template allineati ad anagrafiche realmente esistenti
      (`Sede Milano`, `Acquisti`, `Ibrido`), così la riga dimostrativa supera i
      lookup invece di risultare non risolta.

## 7. Quality Assurance SQL

DB di test creati da zero dal dump reale `portalmanager_db.sql`.

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_q1` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_q1` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_q1` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_q2` fresco | 315 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_q2` | 315 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_q2` | 315 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file SQL
- [x] Idempotenza via `ON DUPLICATE KEY UPDATE`
- [x] Conteggio statement consolidato: **314 → 315**

## 8. Sicurezza

- [x] Colonne retributive filtrate dal permesso `manage_employees_compensation.php`,
      lo stesso che governa la colonna RAL nell'elenco: il template non espone RAL
      a chi non può vederla in anagrafica
- [x] Endpoint del template soggetto al controllo di accesso della pagina
- [x] Buffer svuotati e `zlib.output_compression` disattivato prima del binario
      (regola consolidata dalla v1.8.39)
- [x] Import: prepared statement con whitelist di campi, nessun nome di colonna
      derivato da input utente
- [x] Aggiornamento non distruttivo: le celle vuote non azzerano dati esistenti
- [x] Download del template registrato nell'event log
- [x] Nessuna modifica a schema, dati o logiche di autorizzazione

## 9. Documentazione

- [x] `docs/CHANGELOG.md` — richiesta, soluzione, difetti trovati in collaudo
- [x] `docs/TECHNICAL_DESIGN_v1_8_43.md` — ambito export, schema unico,
      normalizzazione, tipi, lookup, aggiornamento non distruttivo
- [x] `docs/DEPLOYMENT_v1_8_43.md` — percorsi, dipendenza sul file nuovo, verifica
- [x] `docs/MANUALE_ADMIN_v1_8_43.md`
- [x] `docs/MANUALE_UTENTE_v1_8_43.md`
- [x] `docs/RELEASE_CHECKLIST_v1_8_43.md` — questo documento

## 10. Nota di metodo

I test usano il codice reale della release, non riscritture: la funzione
`collectVisibleData` è estratta dal PHP e valutata in JavaScript, il parser XLSX è
estratto ed eseguito con `eval`. Una prima versione della prova, con parser
proprio, produceva **falsi positivi**: non gestendo l'attributo `r` delle celle
XLSX, e poiché le celle vuote sono omesse dall'XML, le colonne slittavano e
l'allineamento appariva rotto quando non lo era.
