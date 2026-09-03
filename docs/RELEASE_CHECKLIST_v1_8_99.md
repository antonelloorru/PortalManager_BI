# Release Checklist — PortalManager v1.8.99

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.98.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.99` |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.8.98 | OK |
| `sql/` × 2, `docs/` × 5 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.99**

## 2. Fedeltà al documento aziendale

| Verifica | Esito |
|---|---|
| Descrizioni trovate nella migration | **20 su 20** |
| Ripartizione formule vs file | **1/6/12/1 — identica** |
| Basi di costo vs file | **2/8/10 — identica** |

- [x] Confronto eseguito **contro il file XLSX originale**, non contro la mia
      trascrizione

## 3. Quattro formule

- [x] **A** valore − consuntivato (1) · **B** valore − costi (6) ·
      **C** valore + consuntivato − costi (12) · **D** consuntivato − costi (1)
- [x] La **C** riguarda 12 linee su 20: il consuntivo **si somma**
- [x] Applicare B dove serve C produce un margine **plausibile e sbagliato**, che
      nessuna quadratura interna può rilevare — il portale non ha un secondo modo
      di calcolare la stessa cosa
- [x] `formula` è un **codice**, `formula_desc` è documentazione: un refuso nel
      testo non cambia un calcolo

## 4. Casi particolari gestiti

- [x] **«Time material» vs WTS-CSS**: registrati entrambi in `code_doc` e
      `service_line`. Confonderli avrebbe lasciato la linea senza regola
- [x] **Sei codici «Dinamico»**: `is_dynamic = 1`, le linee `NV_*` senza riga
      propria ereditano il trattamento del gruppo
- [x] `LIKE 'NV\_%'` con `_` protetto: senza barra rovesciata è un jolly e `NVX`
      verrebbe incluso

## 5. La risoluzione dichiara la propria origine

| Commessa | Linea | Formula | Origine |
|---|---|---|---|
| WTS_5 | WTS-PRES | C | linea |
| WTS_8 | NV_DT | C | **gruppo dinamico** |
| WTS_10 | WTS-XYZ | B | **predefinita** |

- [x] Tre livelli: linea, codice documento, gruppo dinamico
- [x] La ricaduta sulla predefinita è **visibile**: restituire NULL avrebbe rotto
      i calcoli, applicarla in silenzio avrebbe nascosto la lacuna
- [x] `v_cm_calc_copertura` rende la lacuna **misurabile** invece che scopribile
      per caso

## 6. Allineamento di `cm_contract_models`

- [x] `'direzionale'` su WTS-GES e WTS-SOC → **`'costi_a_zero'`**: il documento
      dice «Costi a zero», che è cosa diversa
- [x] L'`UPDATE` sovrascrive deliberatamente: i valori precedenti erano una
      classificazione provvisoria fatta senza il documento
- [x] Il join sulla linea limita l'aggiornamento alle linee presenti

## 7. Quality Assurance SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_t99` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_t99` | 9 stmt, **err=0** |
| Migration RUN3 | `pm_t99` | 9 stmt, **err=0** |
| Coda v1.8.99 del consolidato, RUN1 | `pm_coda` | 7 stmt, **err=0** |
| Coda v1.8.99, RUN2 (idempotenza) | `pm_coda` | 7 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Risoluzione delle regole verificata su **10 commesse** di prova
- [x] `php -l` su tutti i file: OK

## 8. Limite dichiarato

**Il consolidato completo non è stato rieseguito su database reale**: il dump del
portale non era più disponibile in ambiente di prova, e ho ricostruito uno schema
minimo per collaudare la parte nuova.

La **coda v1.8.99 è stata collaudata in isolamento** e la migration singola per
intero, tre volte. Resta non verificata l'esecuzione delle 641 istruzioni
precedenti, già collaudate nelle rispettive release.

## 9. Aperto — il passo successivo

**Le viste economiche non applicano ancora queste formule.** Questa release
stabilisce la tabella di riferimento e la risoluzione; riscrivere
`v_cm_redditivita_commessa` e le altre è il passo successivo.

L'ho tenuto separato deliberatamente: cambiare le formule di calcolo su tutto il
portale in una sola release, **senza poter confrontare i risultati prima e dopo**,
sarebbe stato imprudente.

Con il dump del database si può mostrare **quanto cambiano i margini** applicando
le formule corrette, linea per linea, prima di renderle operative.
