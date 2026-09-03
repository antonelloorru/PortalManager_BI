# Release Checklist — PortalManager v1.8.86

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.85.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.86` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 5 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 7 in `app/` | invariati da v1.8.85 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.86**
- [x] Nessuna risincronizzazione: le viste leggono i dati già presenti

## 2. La presa in carico come unità di misura

- [x] Contare i messaggi misura **quanto scrive**, non quanto risolve
- [x] La **prima risposta di supporto** identifica il responsabile del ticket
- [x] Due gruppi separati nella scheda: **esito** (attribuibile) e **attività**
      (non attribuibile), con etichetta esplicita

| Componente | Presi | Risolti | Scalati | Escalation | 1ª risposta |
|---|---|---|---|---|---|
| Enrico Mancini | 613 | 582 | 31 | 5,1% | 9,2 h |
| Sebastiano Chiarini | 520 | 488 | 32 | 6,2% | 4,9 h |
| Emanuele Bressi | 278 | 248 | 30 | **10,8%** | 6,1 h |
| Greta Ferrante | 49 | 47 | 2 | 4,1% | 4,1 h |

## 3. Univocità della presa in carico

- [x] `MIN(received_at)` da solo **non basta**: due risposte nello stesso istante
      darebbero due responsabili e la somma supererebbe i ticket
- [x] Secondo criterio su `source_id` per il pari merito
- [x] Verificato: **2.941 righe = 2.941 ticket**
- [x] Somma dei presi in carico per tecnico = **2.941**

## 4. Il tempo di prima risposta

- [x] Unico tempo misurabile **senza SLA**: non dipende dalle attese del cliente,
      che intervengono solo dopo la prima risposta
- [x] Non richiede di sottrarre gli intervalli in `WAITING_FOR_CUSTOMER_RESPONSE`
- [x] Dichiarato come tempo **osservato**, non rispetto di SLA

## 5. Il confronto con i pari

- [x] La media **esclude il tecnico stesso**: includerlo sposterebbe la media
      verso chi ha il volume più alto
- [x] Soglia di evidenza al **40% sopra la media**: alta abbastanza da non
      accendersi per fluttuazioni, bassa abbastanza da cogliere il 10,8% di Bressi
- [x] Dichiarato che **il tempo va letto insieme al volume**: Mancini a 9,2 h
      prende in carico il doppio dei ticket di Bressi

## 6. Profili diversi, non rendimenti

| Componente | Rapporto risposte/note | Code |
|---|---|---|
| Chiarini | 3,9 | 11 |
| Bressi | 2,0 | 11 |
| Mancini | 1,7 | 11 |
| **Ferrante** | **0,6** | **5** |

- [x] Misure **affiancate**, mai sommate in un punteggio: un indice unico
      confronterebbe persone che fanno lavori diversi
- [x] Dichiarato in pagina e in manuale

## 7. QA — collaudo esteso al template

Lezione della v1.8.85: un collaudo che si ferma al modello dà zero errori su una
pagina che non si apre.

| Verifica | Esito |
|---|---|
| Metodi statici inesistenti, tutte le pagine | **0** |
| Metodi `SdModel` invocati e inesistenti | **0** |
| Avvisi o errori sulle 4 schede | **0** |
| Espressioni del template eseguite | medie, percentuali, altezze barre |
| Barre oltre l'altezza del grafico | **0** |
| `risolti + scalati ≤ presi in carico` | rispettata su tutti |
| Quadratura presi in carico | **2.941 = 2.941** |
| Scheda di tecnico inesistente | **vuota, nessun errore** |

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` (dati reali) | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c86` fresco | 581 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c86` | 581 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c86` | 581 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **575 → 581**

## 9. Nota di metodo

Misurare le persone richiede di scegliere che cosa sia attribuibile a ciascuna.
Il dato più facile da contare — i messaggi — è anche quello che risponde alla
domanda sbagliata.

Separare esito e attività, e dichiarare quale dei due è attribuibile, costa due
gruppi invece di uno e evita che un numero venga usato per una conclusione che non
sostiene.

## 10. Aperto

- **Il collaudo non è un rendering completo**: esegue le espressioni del template
  ma non la pagina, perché servirebbe simulare sessione e permessi. Copre la parte
  dove gli errori si sono manifestati, non tutta la pagina.
- **Nessun SLA**: il tempo di prima risposta è osservato, non confrontato con una
  soglia. Vale quanto detto nella v1.8.83.
- I dati della tabella di confronto sono sull'intero archivio e non seguono il
  filtro di periodo: dichiarato in pagina, ma è un'asimmetria rispetto alla
  scheda, che mostra entrambi.
