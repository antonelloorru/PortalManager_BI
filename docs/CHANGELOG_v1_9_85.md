# CHANGELOG — v1.9.85

## Fix — Import candidati LinkedIn: «ID offerta di lavoro» alterato (E9)

**Sintomo**: i candidati del file LinkedIn (es. `Job_SistemistaAncona.xlsx`) non vengono associati alle posizioni:
l'ID offerta risulta `4.412730757E9` / `4412730757E9` invece di `4412730757`.

**Causa**
- L'export LinkedIn salva gli ID come celle numeriche in notazione scientifica (`<v>4.412730757E9</v>`).
  `XlsxReader` restituiva il valore grezzo, l'importer lo usava come stringa: nessuna corrispondenza con
  `job_positions.linkedin_code`. Stessa alterazione su «ID progetto di assunzione» e «ID contratto».
- Sulle posizioni il codice era salvato senza normalizzazione: nel DB sono presenti codici con suffisso `E9`
  (`4427193793E9`, punto decimale perso), che non corrispondevano più nemmeno ai candidati già importati.

**Correzione**
- `app/XlsxReader.php` — `plainNumber()`: celle numeriche in notazione scientifica con valore intero → cifre
  esatte (conversione su stringa, nessuna perdita di precisione). Vale per tutti gli import XLSX.
- `app/LinkedInApplicantImporter.php` — `normalizeJobCode()` (pubblico): forma canonica solo cifre da
  notazione scientifica, suffisso `E9` senza punto, `.0` finale, URL `…/jobs/view/<id>`, spazi.
  Il match con le posizioni confronta la forma canonica di entrambi i lati; a parità di codice prevale la
  posizione **aperta**, poi la più recente.
- `recruiting_posizioni.php` — il «Codice Posizione LinkedIn» viene salvato in forma canonica (accetta anche l'URL dell'annuncio).
- `sql/migration_v1_9_85.sql` — bonifica dei codici esistenti (`job_positions.linkedin_code`, `candidates.li_job_id`,
  `hiring_project_id`, `li_contract_id`), indici, ricollegamento dei candidati LinkedIn rimasti senza candidatura
  per il mancato match (stage `cv_received`, stato `in_pipeline` solo per quelli ricollegati ora).

**Verifica sul file allegato**: 102 righe, ID 4412730757 (46), 4401079935 (41), 4472701774 (15) → 102 associati
con posizioni di test che hanno il codice in formati sporchi (`E9`, URL, `.0`).

## Nota di sequenza
Basata su `main` (v1.9.81). Merge consigliato: 1.9.82 → 1.9.83 → 1.9.84 → 1.9.85 (conflitto solo su `VERSION`: tenere il valore più alto).
