# Release Checklist — PortalManager v1.8.76

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.75.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.76` |
| `index.php` | ROOT, **NUOVO nel pacchetto** | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 5 in `app/` | invariati da v1.8.75 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.76**
- [x] Release solo applicativa: usa le tabelle della v1.8.75

## 2. Due elementi, due logiche

| Elemento | Presenza | Domanda |
|---|---|---|
| scheda KPI «Ultima sincronia» | **sempre** | a quando risale l'aggiornamento |
| banner in testa | **solo se problema** | c'è qualcosa da fare |

- [x] Scartato il riquadro unico sempre visibile: verde il 95% dei giorni,
      smette di essere letto proprio prima di diventare rosso
- [x] La scheda KPI resta perché sta in una griglia che si consulta
      deliberatamente; il banner occupa la testa della pagina e deve guadagnarsela

## 3. L'ultima riuscita, non l'ultima tentata

- [x] Query filtrata su `status IN ('ok','parziale')`
- [x] Senza il filtro, un tentativo fallito due ore fa diventerebbe «ultima
      sincronia: 2 ore fa» — l'indicatore direbbe che i dati sono freschi
      proprio quando non lo sono
- [x] `parziale` incluso: alcuni dataset sono passati, qualcosa si è aggiornato
- [x] Le due informazioni convivono: «l'ultima è fallita» nel titolo, «ultima
      completa: 30 ore fa» nel dettaglio

## 4. QA — cinque scenari

| Scenario | Esito |
|---|---|
| Ruolo dipendente (9) | **non visibile** |
| Ultima riuscita 5 ore fa | nessun banner, KPI `5h fa` |
| Ultima riuscita 50 ore fa | **banner rosso** |
| Riuscita 30 h fa + **fallita 2 h fa** | riporta **30 ore fa**, banner presente |
| Mai eseguita | banner, «nessuna sincronizzazione completa registrata» |

- [x] Il quarto è il caso che conta: l'indicatore non si lascia ingannare
      dall'ultima riga del log

## 5. Soglie

| Soglia | Valore | Motivo |
|---|---|---|
| giallo | **26 ore** | una notturna che slitti di un'ora non è un problema |
| rosso e banner | **36 ore** | oltre un giorno e mezzo un'esecuzione è saltata |

- [x] 24 ore esatte avrebbero prodotto un giallo quasi ogni giorno: fra due
      notturne passano 24 ore **più** il tempo di esecuzione
- [x] Una soglia che scatta nel funzionamento normale è rumore

## 6. Scelte di progetto

- [x] Filtro sul ruolo (≤ 5): non è sicurezza ma **attenzione** — un avviso su cui
      non si può agire si impara a ignorare, insieme agli altri
- [x] `try/catch` con `$syncAvviso = null`: se la v1.8.75 non è applicata
      l'avviso non compare invece di rompere la home, che è la prima pagina che
      tutti aprono
- [x] Formato **relativo** (`5h fa`) nella scheda, **assoluto** nel suggerimento:
      il relativo si interpreta senza calcoli, l'assoluto è a un passaggio di mouse
- [x] Dimensione del carattere ridotta oltre i 5 caratteri, perché `mai` e
      `12g fa` non hanno lo stesso ingombro e la griglia deve restare allineata

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c76` fresco | 521 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c76` | 521 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c76` | 521 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **520 → 521**

## 8. Aperto

- L'avviso è **passivo**: va visto aprendo la home. Una notifica attiva — email
  al fallimento — resta il passo successivo, e richiede una configurazione SMTP
  che il portale non ha ancora.
- La soglia di 36 ore è cablata. Se la pianificazione fosse impostata su giorni
  alterni, produrrebbe un falso allarme: andrebbe derivata da `days_mask`.
