# Release Checklist — PortalManager v1.8.48

Policy **zero-omission**: ogni voce verificata con evidenza.
Base di partenza: **v1.8.47**.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.48` |
| `tech_registry.php` | ROOT, **nuovo** | OK |
| `tech_units.php` | ROOT, **nuovo** | OK |
| `project_dashboard.php` | ROOT, modificato | OK |
| `dgb_activities.php` | ROOT, modificato | OK |
| `app/DgbModel.php` | modificato | OK |
| `app/MenuManager.php` | modificato | OK |
| `app/Router.php` | modificato | OK |
| `app/Version.php` | modificato | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] In `app/` solo i file effettivamente modificati
- [x] ZIP forward-slash; ZIP precedente rimosso dagli output
- [x] File completi e già patchati, nessuno snippet o diff

## 2. Versionamento coeso

- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.48**, verificato su `pm_c48`

## 3. Routing e menu

- [x] `tech_registry` e `tech_units` aggiunte a `Router::PAGES`
- [x] Ciclo `pagina → slug → pagina` verificato: **2 / 2 OK**
- [x] Voci di menu inserite in Gestione Commesse dopo Anagrafica Professionisti
- [x] Form GET con `route_slug_field()` come primo elemento (regola v1.8.42)

## 4. QA — tassonomia

| Verifica | Esito |
|---|---|
| Unità installate | **9** (le nove richieste) |
| Sotto-unità installate | **24** |
| Sotto-unità duplicate nella stessa unità | **0** |
| Unità marcate come reperibilità | 2 (Reperibilità, Reperibile H24) |

## 5. QA — vincoli del profilo

Validazione applicativa provata su sei combinazioni:

| Caso | Esito |
|---|---|
| interno + unità + sotto-unità coerente | accettato |
| esterno + unità | accettato |
| interno ed esterno insieme | **respinto** |
| nessuna persona selezionata | **respinto** |
| sotto-unità appartenente a un'altra unità | **respinto** |
| sotto-unità senza unità | **respinto** |

- [x] Secondo profilo per la stessa persona: **respinto** dal vincolo di unicità

## 6. QA — storicizzazione

Tre variazioni registrate in sequenza su un profilo:

| Dal | Al | Unità | Sotto-unità |
|---|---|---|---|
| 2024-01-01 | 2025-05-31 | Sistemista Infrastruttura | Virtualizzazione |
| 2025-06-01 | 2026-02-28 | SOC | Monitoraggio |
| 2026-03-01 | in corso | SOC | — |

| Verifica | Esito |
|---|---|
| Assegnazioni aperte contemporaneamente | **1** (atteso 1) |
| Periodi sovrapposti (auto-join sulle intersezioni) | **0** |
| Dopo rinomina dell'unità, lo storico riporta il nome di allora | **OK** |

## 7. QA — elenco unificato

| Verifica | Esito |
|---|---|
| Totale persone | 63 (58 interni + 5 esterni) |
| Persone duplicate in elenco | **0** |
| Esterni già associati a un dipendente, esclusi | 43 |
| Filtro per unità / solo esterni / reperibili / da classificare | tutti coerenti |

## 8. QA — legame Consuntivo ↔ DGB

| Verifica | Esito |
|---|---|
| Rapporti legati sul campione | **433 / 433** |
| Codici incoerenti fra rapporto e attività | **0** |
| Legami che risalgono al codice corretto | **433 / 433** |
| Filtro per codice in `DgbModel` | trova l'attività cercata |

- [x] Non è stata riusata `dgb_source_id`: ha vincolo UNIQUE ed è riservata alla
      sincronizzazione DGB. Un intervento multi-tecnico genera più rapporti sulla
      stessa attività e il vincolo li rifiuterebbe. Usate colonne dedicate.
- [x] Il popolamento usa `SUBSTRING_INDEX(report_code,'/',1)`, compatibile con il
      suffisso per tecnico introdotto in v1.8.46

## 9. Difetto intercettato e corretto

- [x] **Lock wait timeout** sulla UPDATE di riconciliazione:
      `dgb_forms_activity.code` era privo di indice e con 80.000 attività ogni
      rapporto causava una scansione completa. Aggiunto `idx_dfa_code` **prima**
      della UPDATE nella sequenza della migration.

## 10. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_lite` | 17 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_lite` | 17 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_lite` | 17 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c48` fresco | 342 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c48` | 342 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c48` | 342 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN/INDEX IF NOT EXISTS`,
      `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`
- [x] Permessi propagati da `professionals.php`, verificati: 11 righe
- [x] Conteggio statement consolidato: **327 → 342**

## 11. Sicurezza

- [x] Tutte le scritture da prepared statement con placeholder
- [x] Permessi verificati separatamente per vista, modifica ed eliminazione
- [x] Permessi derivati dall'Anagrafica Professionisti: nessun accesso allargato
- [x] Coerenza unità/sotto-unità verificata **lato server**, non solo in pagina
- [x] CSRF su tutti i form; ogni operazione nell'event log
- [x] Output HTML sempre via `h()`

## 12. Documentazione

- [x] `CHANGELOG.md` — verifiche preliminari, scelte, difetto intercettato
- [x] `TECHNICAL_DESIGN_v1_8_48.md` — modello, separazione tassonomia,
      disattivazione contro eliminazione, storicizzazione volontaria, etichette
      congelate, legame DGB e indice mancante
- [x] `DEPLOYMENT_v1_8_48.md` — tempi della migration, ordine indice/UPDATE, verifica
- [x] `MANUALE_ADMIN_v1_8_48.md`, `MANUALE_UTENTE_v1_8_48.md`
- [x] `RELEASE_CHECKLIST_v1_8_48.md` — questo documento

## 13. Nota sul collaudo

I test funzionali sono stati eseguiti su un database ridotto (`pm_lite`, 3.000
rapporti e 3.000 attività) perché il dump completo — 67.000 rapporti e 80.000
attività — eccede i tempi della sandbox. La struttura è quella reale, clonata
dalle tabelle di produzione, e la validità degli statement è stata verificata
anche sul consolidato applicato a un database con tutte le 132 tabelle.

Il comportamento su volumi pieni resta da confermare in produzione: il punto da
osservare è la durata della migration, per la quale la guida di deployment
riporta l'avvertenza sui tempi.
