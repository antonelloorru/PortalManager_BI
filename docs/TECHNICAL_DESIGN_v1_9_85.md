# TECHNICAL DESIGN — v1.9.85

## Flusso import
```
XLSX ─ XlsxReader (plainNumber su celle t="n") ─ LinkedInApplicantImporter::parse
     ─ analyze: normalizeJobCode(li_job_id) ⇄ normalizeJobCode(job_positions.linkedin_code)
     ─ import: candidates (li_job_id canonico) + candidate_applications(candidate_id, position_id)
```

## Regole di normalizzazione (`normalizeJobCode`)
| Input | Output |
|---|---|
| `4.412730757E9` | `4412730757` |
| `4412730757E9` (mantissa senza punto, cifre = esponente+1, esp ≥ 6) | `4412730757` |
| `4412730757.0`, ` 4412730757 ` | `4412730757` |
| `https://www.linkedin.com/jobs/view/[slug-]4412730757/…` | `4412730757` |
| vuoto, `0`, `N/A` | NULL |
| altro testo | invariato (trim) |

`XlsxReader::plainNumber`: solo notazione scientifica con valore intero ed esponente ≤ 30, espansa su stringa.

## Relazioni (ER)
`job_positions.linkedin_code` (VARCHAR 100, indicizzato) 1—N `candidates.li_job_id` (VARCHAR 60, indicizzato) — legame logico;
legame fisico `candidate_applications (candidate_id → candidates, position_id → job_positions, UNIQUE(candidate_id, position_id))`.

## Scelta posizione con codice duplicato
Prima `status='open'`, poi `id` maggiore (stessa regola in PHP e nella migration).
