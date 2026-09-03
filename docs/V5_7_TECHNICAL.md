# certV 5.7.0 — Documentazione tecnica

## Sintesi modifiche

| Area | Cosa |
|---|---|
| Modello dati | Tecnologia promossa a entità trasversale cross-brand |
| Pivot N:M | tech_brands, tech_certifications, tech_user_certifications, tech_employee_skills |
| Skill | employee_skills (skill matrix) + pivot tech_employee_skills |
| Audit | entity_change_log cross-tabella per import + UI + API |
| Import | upsertWithHistory in 8 handler, 3 nuovi tipi (tech_brand_links, tech_cert_links, employee_skills) |
| UI | manage_technologies, tech_skill_matrix, entity_change_log |

---

## 1. Modello dati

### Tabelle nuove

```
tech_categories          ← macro-categorie (Infrastructure, Security, ...)
  └ id, name, parent_id (self-ref), display_order, icon, color, is_active

technologies (esteso)    ← entità ASTRATTA cross-brand
  └ id, name, description, category_id ⇒ tech_categories
        slug, icon, color, is_active, created_at, updated_at

tech_brands              ← N:M technology ↔ brand
  └ technology_id, brand_id, is_primary, notes, created_by

tech_certifications      ← N:M technology ↔ certifications (catalogo)
  └ technology_id, certification_id,
        relevance ENUM('primary','secondary','related'), created_by

tech_user_certifications ← N:M technology ↔ user_certifications (titoli posseduti)
  └ technology_id, user_certification_id,
        auto_inferred (1=ereditato da tech_certifications, 0=manuale)

employee_skills          ← skill matrix dei dipendenti
  └ employee_id, skill_name, level ENUM(beginner..expert),
        years, last_used, self_assessed, validated_by, validated_at

tech_employee_skills     ← N:M technology ↔ skill
  └ technology_id, skill_id

entity_change_log        ← audit trail cross-tabella
  └ entity_table, entity_id, field_name, old_value, new_value,
        change_action ENUM(insert,update,approve,reject),
        change_source ENUM(import,ui,api,migration,system),
        source_ref_id, changed_by, changed_at
```

### Diagramma ER

```
                           ┌─────────────────┐
                           │ tech_categories │
                           │  (gerarchia)    │
                           └────────┬────────┘
                                    │ parent
                                    │
                                    ↓
                           ┌────────────────┐
                           │  technologies  │←─────────────────┐
                           │ (cross-brand)  │                  │
                           └────┬───────────┘                  │
                       ┌────────┼───────────────┐              │
                       │        │               │              │
                       ↓        ↓               ↓              │ source_ref_id
               ┌──────────┐ ┌────────────┐ ┌─────────────────┐ │ (job_id se import)
               │tech_     │ │tech_       │ │tech_user_       │ │
               │ brands   │ │certif.     │ │certifications   │ │
               └────┬─────┘ └──────┬─────┘ └──────┬──────────┘ │
                    │              │              │            │
                    ↓              ↓              ↓            │
               ┌─────────┐  ┌──────────────┐ ┌──────────────┐  │
               │ brands  │  │certifications│ │user_certifi- │  │
               └─────────┘  └──────────────┘ │ cations      │  │
                                              └──────┬───────┘  │
                                                     │          │
                                                     ↓          │
                                               ┌───────────┐    │
                                               │ employees │    │
                                               └─────┬─────┘    │
                                                     │ has-many │
                                                     ↓          │
                                            ┌─────────────────┐ │
                                            │ employee_skills │ │
                                            └────────┬────────┘ │
                                                     │ N:M      │
                                                     ↓          │
                                          ┌─────────────────────┘
                                          │
                              ┌──────────────────────┐
                              │ tech_employee_skills │
                              └──────────────────────┘

   ┌────────────────────────────────────────────────────────────┐
   │  entity_change_log  (logga modifiche su QUALUNQUE tabella) │
   │  source_ref_id punta a import_jobs.id se source='import'   │
   └────────────────────────────────────────────────────────────┘
```

---

## 2. Storicizzazione import: upsert con audit per-campo

### `ImportProcessor::upsertWithHistory()`

Helper unico chiamato dagli 8 handler principali (employees, brands, technologies, certifications, locations, agencies, candidates, clients). Pattern:

```
1. SELECT esistente con matchKeys (es. fiscal_code o employee_code)
2. Se esiste:
     - Calcola diff campo per campo (coalesceCompare loose-equal)
     - Se diff vuoto → return action='skip'
     - Altrimenti UPDATE solo i campi cambiati
     - EntityChangeLog::diffAndLog → 1 riga di log per ogni campo mutato
3. Se non esiste:
     - INSERT con campi merged (payload + insertExtra come created_by)
     - EntityChangeLog::logField → 1 riga "__create__" per snapshot
4. return ['action' => 'insert'|'update'|'skip', 'id' => N, 'fields_changed' => N]
```

### Vantaggi

- **Idempotenza forte**: re-importare lo stesso CSV non duplica e non modifica record già allineati (action='skip')
- **Audit granulare**: diff per campo con `old_value` e `new_value` letti dal DB pre-update
- **Tracciabilità completa**: ogni modifica ha source ('import') e source_ref_id (job ID), ricostruibile con un JOIN
- **Sicurezza**: tutti i campi scrivibili vengono filtrati attraverso whitelist `allowedFields`

### Esempio flusso

CSV con dipendente `RSSMRA85M01H501Z`. Prima import: INSERT + 16 righe di log "__create__". Seconda import con `phone` modificato: UPDATE solo `phone` + 1 riga di log con `old_value='+39 333 1111'`, `new_value='+39 333 2222'`.

### Coalesce compare (`coalesceCompare`)

Confronto loose per evitare false-positive di diff:
- `NULL ~ ''`
- `'5' ~ 5` (numerico)
- `'5.00' ~ '5'` (cast float)
- `'attivo' ≠ 'Attivo'` (case-sensitive)

---

## 3. Workflow tecnologie cross-brand

### Caso d'uso

"Networking" è un'**entità trasversale**. Non è di Cisco, non è di Juniper, è un concetto astratto che multipli vendor coprono. Un dipendente che ha sia CCNA Cisco che JNCIA Juniper è "competente in Networking" indipendentemente dal vendor.

### Mapping operativo

```
Tecnologia: Networking
   ├ tech_brands:
   │     • Cisco (primary)
   │     • Juniper
   │     • HPE Aruba
   ├ tech_certifications (catalogo):
   │     • CCNA-200-301      (relevance=primary)
   │     • CCNP-ENT          (relevance=primary)
   │     • JNCIA-Junos       (relevance=primary)
   │     • CompTIA-Network+  (relevance=secondary)
   │     • AZ-700            (relevance=related, è cloud-networking)
   └ tech_user_certifications:
         (auto-popolato quando dipendente acquisisce cert presente in
          tech_certifications, con auto_inferred=1)
```

### Propagazione automatica

Quando si crea un link in `tech_certifications`:

```sql
INSERT INTO tech_certifications (technology_id, certification_id, relevance)
VALUES (2, 35, 'primary');
```

`ImportProcessor::propagateTechToUserCerts()` viene chiamato automaticamente e fa:

```sql
INSERT IGNORE INTO tech_user_certifications (technology_id, user_certification_id, auto_inferred)
SELECT 2, uc.id, 1
  FROM user_certifications uc
 WHERE uc.certification_id = 35;
```

Tutti i dipendenti che POSSEDEVANO già `cert#35` ora risultano coperti su `tech#2`. Stessa logica via `TechnologyMapper::propagateToUserCertifications()` quando l'azione viene fatta da UI.

### Aggregazioni

`TechnologyMapper::getOverview()` restituisce per ogni tecnologia:
- `brand_count`: quanti brand la coprono
- `cert_count`: quante cert nel catalogo afferiscono a essa
- `held_count`: quanti titoli posseduti totali
- `skilled_employees`: quanti dipendenti distinti coprono la tech
- `skill_count`: quante skill linkate

`getCoverageMatrix($techId)` → lista dipendenti con cert+skill aggregati.

`getSkillGaps()` → tecnologie con cert in catalogo ma 0 dipendenti coperti.

---

## 4. Relazioni tra le viste

### Mappa di navigazione

```
Sidebar > Catalogo certificazioni
   ├─ Catalogo certificazioni (esistente, ora collegato a technologies trasversali)
   ├─ Tecnologie  ←──────────────  manage_technologies.php (NEW)
   │     │
   │     │ click "Modifica" → modale CRUD con sync N:M brand + cert
   │     ↓
   │     stesso DB su technologies, tech_brands, tech_certifications
   │
   └─ Skill matrix  ←──────────  tech_skill_matrix.php (NEW)
         │
         │ vista aggregata per tech: brand_count, cert_count, held, risorse
         ├─ click "drilldown" → dettaglio dipendenti che coprono la tech
         └─ box "skill gap" → tech con cert in catalogo ma 0 risorse

Sidebar > Importazione
   ├─ Import massivo (esistente, ora con 3 nuovi tipi: tech_brand_links,
   │                  tech_cert_links, employee_skills)
   ├─ Completamento LDB (v5.5)
   └─ Storico modifiche  ←──────  entity_change_log.php (NEW)
         │
         │ filtri: tabella, ID record, source, utente, data, campo, job_id
         ↓
         visualizza diff per campo: old_value → new_value
         se source='import' → link al mass_upload_review del job
```

### Flussi cross-page

| Da | Cosa | A |
|---|---|---|
| `mass_upload.php` | Upload CSV `tech_cert_links` → commit | `entity_change_log.php` con filtro source=import, table=tech_certifications |
| `mass_upload_review.php` | Approva riga import dipendenti | `entity_change_log.php?f_jobid=N` mostra diff per campo |
| `manage_technologies.php` | Edit tecnologia con sync brand | `entity_change_log.php?f_table=technologies&f_entity=ID` mostra modifiche UI |
| `tech_skill_matrix.php` | Drilldown su tech | mostra dipendenti coperti via `getCoverageMatrix()` (JOIN su user_certifications + employee_skills) |
| `tech_skill_matrix.php` | Skill gap section | tech con cert in catalogo ma 0 dipendenti → suggerimento per recruiting/formazione |
| `catalogo_certificazioni.php` | Form cert con tech_id | popola `certifications.technology_id` (primary) + `tech_certifications` pivot con `relevance='primary'` |

---

## 5. API metodi pubblici

### `TechnologyMapper`

```php
findOrCreate($name, $extra = []): array
    // Cerca per name; se non esiste crea con default. Restituisce ['id'=>N, 'created'=>bool]

attachBrand($techId, $brandId, $isPrimary=false, $userId=null): bool
detachBrand($techId, $brandId): bool
getBrands($techId): array               // brand collegati a una tech
getTechByBrand($brandId): array         // tech coperte da un brand
syncBrands($techId, $brandIds, $userId=null): array  // imposta esattamente questi brand

attachCertification($techId, $certId, $relevance='primary', $userId=null): bool
detachCertification($techId, $certId): bool
getCertifications($techId, $relevance=null): array
syncCertifications($techId, $assoc, $userId=null): array  // $assoc = [certId => 'primary'|'secondary'|'related']

propagateToUserCertifications($techId, $certId): int
    // Quando si aggiunge cert#X a tech#Y, popola tech_user_certifications
    // con auto_inferred=1 per ogni user_certifications.certification_id=X

tagUserCertification($techId, $userCertId, $userId=null): bool   // tag manuale
untagUserCertification($techId, $userCertId): bool

tagSkill($techId, $skillId, $userId=null): bool
untagSkill($techId, $skillId): bool

getOverview($categoryId=null, $onlyActive=true): array
    // Lista tech con counter aggregati (brand_count, cert_count, held_count, skilled_employees, skill_count)

getCoverageMatrix($techId): array
    // [{employee_id, employee_name, job_title, cert_count, skill_count}, ...]

getSkillGaps(): array
    // Tech con cert in catalogo ma 0 dipendenti coperti
```

### `EntityChangeLog`

```php
diffAndLog($table, $entityId, $oldRow, $newRow, $action, $source, $sourceRefId, $userId, $skipFields=[])
    // Diff campo per campo. Logga 1 riga per ogni campo cambiato.
    // Default skip: updated_at, created_at, updated_by

logField($table, $entityId, $field, $oldValue, $newValue, $action, $source, $sourceRefId, $userId)
    // Log singolo evento atomico

getHistory($table, $entityId, $limit=200): array
    // Storia completa di un singolo record con user_name JOIN

getByImportJob($jobId): array
    // Tutti i log generati da un singolo job di import (audit completo del flusso)
```

---

## 6. Sicurezza

- **Whitelist campi scrivibili** in `upsertWithHistory()`: solo i campi nell'array `$allowedFields` finiscono nel SQL
- **Whitelist tabelle FK** in `ImportValidator::resolveFk()`: anti-injection
- **Backtick escape** sui nomi di tabella/colonna in upsert (sono comunque validati prima)
- **CSRF** su tutti i form delle 3 nuove UI
- **Permessi**:
  - `manage_technologies.php`: ruolo 1 (CRUD) e ruolo 2 (read+update)
  - `tech_skill_matrix.php`: ruolo 1-2
  - `entity_change_log.php`: SOLO ruolo 1 (audit log riservato)
- **Skip su sensibili**: `EntityChangeLog::diffAndLog()` esclude di default `password_hash`, `created_at`, `updated_at` (no esposizione hash)

---

## 7. File del pacchetto

| File | Tipo | Ruolo |
|---|---|---|
| `sql/migration_v5_7.sql` | SQL | Migrazione idempotente (7 tabelle nuove + alter) |
| `app/TechnologyMapper.php` | PHP class | API gestione N:M cross-brand |
| `app/EntityChangeLog.php` | PHP class | Audit cross-tabella |
| `app/ImportValidator.php` | PHP class | + 3 schemi (tech_brand_links, tech_cert_links, employee_skills) + tecnologie cross-brand |
| `app/ImportProcessor.php` | PHP class | upsertWithHistory + 3 nuovi handler N:M, refactor 8 handler esistenti |
| `app/Router.php` | PHP class | + 3 slug |
| `header.php` | PHP/HTML | + 3 voci menu |
| `mass_upload.php` | PHP/HTML | + 3 tipi nei TYPES |
| `manage_technologies.php` | PHP/HTML | UI CRUD tecnologie + sync N:M |
| `tech_skill_matrix.php` | PHP/HTML | UI matrice copertura + gap analysis |
| `entity_change_log.php` | PHP/HTML | UI viewer audit |
| `docs/V5_7_TECHNICAL.md` | MD | Questa documentazione |
