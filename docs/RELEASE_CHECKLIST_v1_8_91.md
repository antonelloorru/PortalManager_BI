# Release Checklist — PortalManager v1.8.91

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.90.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.91` |
| `app/SdModel.php` | **modificato** | OK |
| `app/ItServiceModel.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 8 ROOT + 6 in `app/` | invariati da v1.8.90 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.91**

## 2. Il problema

| Fonte | Esempio | Forma |
|---|---|---|
| `cm_intervention_reports.technician_raw` | `Marziali Matteo` | Cognome Nome |
| `cm_sd_messages.author_name` | `Enrico Mancini` | Nome Cognome |

- [x] `ORDER BY` sulla colonna ordinava **alcune persone per cognome e altre per
      nome**: non un elenco alfabetico ma un rimescolamento
- [x] Fonte di verità: `cm_professionals`, con nome e cognome separati,
      **completa su 247 righe su 247**

## 3. Cognomi composti

| Forma mostrata | Chiave |
|---|---|
| Valentina De Caprio | de caprio valentina |
| Massimiliano De Battista | de battista massimiliano |
| Matteo Di Fabio | di fabio matteo |

- [x] L'euristica «ultima parola = cognome» avrebbe prodotto
      `Caprio Valentina De`
- [x] Su una fonte con i campi già distinti, indovinare dalla stringa sarebbe
      ingiustificabile

## 4. Difetto trovato in collaudo: le maiuscole

- [x] `ZIN DANIELE` scritto tutto maiuscolo: nel confronto fra stringhe le
      maiuscole precedono le minuscole, quindi finiva **dopo `Zhu Kevin`**
- [x] Trovato scorrendo l'elenco fino in fondo: **una rottura su quaranta**,
      invisibile guardando le prime righe
- [x] Corretto con `LOWER()` sulla chiave; la forma mostrata resta invariata
- [x] Riverificato: **40 operatori, 0 rotture**

```
Xavien Tremayne  →  tremayne xavien
Mirko Vadi       →  vadi mirko
Kevin Zhu        →  zhu kevin
DANIELE ZIN      →  zin daniele
```

## 5. Dove si applica

| Schermata | Ordinata |
|---|---|
| Service Desk — Operatività per tecnico | sì |
| Service Desk — I componenti | sì |
| Relazione IT — raggruppata per incaricato | sì |
| Relazione IT — menu «Incaricato» | sì |
| Export XLSX e report di stampa | sì, stesse query |

- [x] **Il nome resta mostrato nella forma consueta**: cambia l'ordine, non
      l'etichetta

## 6. Eccezione deliberata

- [x] I raggruppamenti **non per persona** restano ordinati per **ore
      decrescenti**
- [x] Un elenco di modalità in ordine alfabetico nasconderebbe quale pesa di più;
      un elenco di persone per volume costringe a scorrere tutto per un nome
- [x] `MIN()` sulla chiave di ordinamento: dipende funzionalmente da
      `incaricato`, ma va dichiarata per `ONLY_FULL_GROUP_BY`

## 7. Fallback per chi non è in anagrafica

- [x] `COALESCE(n.ordina, LOWER(nome))`: conserva la propria stringa
- [x] Un `INNER JOIN` lo avrebbe fatto **sparire dall'elenco** — errore
      silenzioso e peggiore di un ordinamento imperfetto, perché nessuno nota
      l'assenza di una riga che non sa di dover cercare

## 8. QA

| Verifica | Esito |
|---|---|
| `v_cm_nomi` | **640 forme per 322 persone** |
| Operatori Service Desk ordinati | **40, zero rotture** |
| Cognomi composti | corretti |
| Nomi in maiuscolo | corretti dopo `LOWER()` |
| Chiamate a metodi inesistenti | **0** |

## 9. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_full` (dati reali) | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_full` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c91` fresco | 602 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c91` | 602 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c91` | 602 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **596 → 602**
- [x] Migration testata su database con **tutte** le viste presenti: i database
      parziali producevano falsi errori su viste assenti

## 10. Nota di metodo

L'ordinamento sembrava un dettaglio di presentazione e si è rivelato un problema
di dati: due fonti con convenzioni opposte nella stessa colonna.

Il difetto delle maiuscole, in particolare, non si vedeva guardando le prime righe
dell'elenco. Serviva un controllo che scorresse fino in fondo cercando la prima
posizione in cui la chiave decresce.

## 11. Aperto

- Restano gli aperti della v1.8.90: chilometri a zero in attesa degli indirizzi,
  settore tecnologico dipendente dalle assegnazioni, colori in stampa soggetti
  alla preferenza del browser.
