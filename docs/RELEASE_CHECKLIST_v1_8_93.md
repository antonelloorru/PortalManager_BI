# Release Checklist — PortalManager v1.8.93

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.92.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.93` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `it_service.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 1 metodo** | OK |
| `app/ItServiceModel.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.92 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.93**

## 2. Il dato esisteva già

- [x] `exec_company_id` popolato su **1.062 commesse su 1.062**
- [x] Derivato da `DatasetSync` tramite `company_from => codice_commessa`
- [x] Mancava solo l'esposizione: **un `LEFT JOIN` e una colonna**
- [x] Caso opposto ai chilometri (v1.8.89): lì la struttura c'è e il dato no

## 3. Nome contro prefisso

- [x] `SUBSTRING_INDEX(project_code, '_', 1)` avrebbe dato lo stesso
      raggruppamento senza join
- [x] Scartato: il prefisso è una **convenzione di codifica**, il nome è la
      **società** — e in un report «NIS» richiede una legenda
- [x] Scartato anche per **duplicazione**: la risoluzione esiste in
      `PrefixResolver`, e due implementazioni divergono al primo cambio di
      convenzione
- [x] `COALESCE(name, '(non attribuita)')`: un NULL in un raggruppamento si legge
      come difetto di visualizzazione, un'etichetta esplicita è informazione

## 4. Relazione di Servizio IT

| Azienda | Interventi | Ore |
|---|---|---|
| WETECH'S SPA SB | 13.673 | 62.540,0 |
| Nis Group srl | 2.171 | 5.444,5 |
| Antea srl | 932 | 2.845,5 |
| Wenest SRL | 143 | 1.057,5 |
| Weenergy | 18 | 146,5 |

- [x] Filtro multiplo, raggruppamento, grafico, foglio nell'export

## 5. Service Desk — riquadro condizionato

- [x] Compare **solo se `count($aziende) > 1`**
- [x] Sui dati attuali: **una sola azienda** (WETECH'S, 4.067 moduli, 11.908,5 h,
      149 commesse, 14 linee) → riquadro nascosto
- [x] Una tabella con una riga ripeterebbe il totale già mostrato sopra
- [x] **L'export include sempre il foglio**: in un file di dati la riga singola è
      un fatto, a video sarebbe rumore — i due contesti hanno costi di spazio
      diversi
- [x] Palette `$COLAZ` separata da `$colClasse`: dimensioni diverse, colori
      condivisi suggerirebbero un legame inesistente
- [x] Verificato l'ordine definizione/uso: definita a 825, usata a 55.572

## 6. QA — quadrature

| Verifica | Esito |
|---|---|
| Relazione IT, somma per azienda | **72.034,0 = 72.034,0** |
| Azienda × codice linea (43 righe) | **72.034,0 = 72.034,0** |
| Service Desk, totale per azienda | **11.908,5** = v1.8.87 |
| Filtro su una azienda | 932 ≤ 16.937 |
| Filtro tecnico sul riquadro | 6.565,5 h < 11.908,5 |
| Chiamate a metodi inesistenti | **0** |
| Avvisi o errori PHP | **0** |

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` (dati reali) | 5 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 5 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 5 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c93` fresco | 606 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c93` | 606 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c93` | 606 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Conteggio statement consolidato: **603 → 606**

## 8. Nota di metodo

Prima di costruire una derivazione, vale la pena verificare se qualcuno l'abbia
già costruita. Qui la logica prefisso → azienda esisteva da tempo in
`DatasetSync`, il risultato era in tabella su tutte le righe, e nessuna vista lo
leggeva.

## 9. Aperto

- Restano gli aperti precedenti: `project_type` vuoto, chilometri in attesa degli
  indirizzi, settore tecnologico dipendente dalle assegnazioni, colori in stampa
  soggetti alla preferenza del browser.
