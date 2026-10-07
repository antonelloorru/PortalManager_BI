# CHANGELOG — v1.10.18 (2026-10-07)

Software 1.10.18 · Schema 1.10.18 · Upgrade `sql/migration_v1_10_18.sql` (cumulativo da 1.10.06) · Plugin pm-ats **1.2.0**

## 1. Controllo di pubblicazione puntuale
Stato **per posizione**, indipendente dallo stato della posizione in PortalManager: `job_positions.web_status`.

| Stato | Sul sito |
|---|---|
| **Pubblicata** (`publish`, predefinito) | visibile nell'elenco e con la propria pagina |
| **Bozza sul sito** (`draft`) | inviata ma non visibile né elencata; l'URL dà 404 (non «posizione chiusa»); consultabile in anteprima |
| **Non pubblicare** (`off`) | ritirata o mai inviata |

- «Applica» salva lo stato e **invia subito la sola posizione** (`/sync/jobs` in modalità delta), senza toccare le altre.
- Gli invii completi (manuali, pianificati, a ogni modifica) rispettano lo stato di ogni posizione.
- Restano inviabili solo le posizioni *aperte*, già avviate e non scadute.
- Il registro delle pubblicazioni distingue pubblicata, bozza e ritirata.
- Dove si imposta:
  - *Sito web (WordPress)* › tabella **Pubblicazione per posizione** (bozze, aperte e in pausa);
  - *Pubblica su portali* › riquadro **Sito web aziendale (WordPress)** della singola posizione.

## 2. Anteprima della scheda annuncio
Pulsante **Anteprima** (tabella e scheda posizione), disponibile prima e dopo la pubblicazione, anche per posizioni in bozza in PortalManager.
- **Plugin ≥ 1.2.0**: PortalManager invia i dati attuali con `POST /sync/preview` (firmata) e apre l'URL temporaneo del sito.
  - Resa identica alla pagina pubblicata: template, tema e colori.
  - Barra «ANTEPRIMA» con lo stato sul sito, modulo di candidatura disattivato.
  - `noindex`, validità 30 minuti; non crea né modifica contenuti.
- **Sito non raggiungibile o plugin precedente**: anteprima locale con il template e il foglio di stile del plugin (senza il tema), con il motivo indicato.

## Plugin 1.2.0
`web_status` per item in `/sync/jobs`, meta `_pm_web_status` (publish | draft | withdrawn), rotta `/sync/preview`, pagina `?pm_ats_preview=<token>`. API `pm-ats/v1` invariata e compatibile.
