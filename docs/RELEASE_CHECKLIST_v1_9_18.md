# Release Checklist — PortalManager v1.9.18

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.17.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.18` |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.17 | OK |

- [x] **Nessun file applicativo modificato**: la release aggiunge viste
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.18**

## 2. Il perimetro

- [x] 16 linee escluse; restano **WTS-ACM, WTS-CSS, WTS-CC, WTS-MEG** — 369
      commesse attive
- [x] **Per esclusione e non per inclusione**: una linea nuova entra
      automaticamente. Con l'inclusione ogni linea creata dopo sarebbe rimasta
      fuori in silenzio

## 3. Giorni distinti

- [x] Verificato: **4 interventi in 3 giorni → 3 giorni lavorati**
- [x] `giornate_equiv` accanto: due ore al giorno per venti giorni danno 20 e 5
- [x] Stesso principio delle tre misure di «addetti» (v1.9.10): quando una parola
      ammette letture diverse, esporle tutte rende la scelta esplicita

## 4. Le fasce in giorni

- [x] `COUNT(DISTINCT CASE WHEN fascia = 'C' THEN giorno END)`
- [x] **Un giorno misto conta in entrambe le fasce**: la somma può superare i
      giorni totali, ed è corretto
- [x] L'alternativa avrebbe richiesto una regola arbitraria per i giorni misti,
      nascondendo che sono misti
- [x] `ore_C` e `ore_D` danno la ripartizione esclusiva

## 5. Il filtro sulle attive

- [x] Guarda lo stato **oggi**, non alla data del modulo
- [x] **Conseguenza dichiarata**: un report ristampato mesi dopo può dare numeri
      diversi
- [x] `v_cm_it_giorni_tutte` rende la differenza misurabile: verificato 4 giorni
      totali → 3 su attive, 1 su chiusa

## 6. Difetto intercettato

- [x] `v_cm_it_giorni_area` usava una **sottoquery correlata alla vista in corso
      di definizione**: MariaDB la rifiuta
- [x] Sostituita con `JOIN` su sottoquery aggregata — anche la forma che
      l'ottimizzatore gestisce bene sulle viste annidate (v1.8.88)

## 7. QA

| Verifica | Esito |
|---|---|
| Giorni distinti | **3 su 4 interventi** |
| Sabato → fascia D | sì |
| Filtro attive | 4 totali → **3 su attive** |
| Quote per area | **100,0%** |
| Righe senza tariffa segnalate | sì |
| `(non indicata)` per settore mancante | sì |
| Migration RUN1/RUN2/RUN3 | 10 stmt, **err=0** |
| Coda consolidato RUN1/RUN2 | 8 stmt, **err=0** |
| **Consolidato completo** | **754 stmt, err=0** |
| `;` nei commenti SQL | **0** |

## 8. Aperto

- **`fascia_letta_pct` da verificare sui dati reali**: se bassa, le fasce sono in
  gran parte dedotte dall'orario e il conteggio per fascia eredita l'incertezza.
- **La pagina non è inclusa**: le viste sono verificate e interrogabili da SQL. Il
  riquadro nella Relazione IT e l'export si costruiscono su queste.
- **I moduli sono vuoti nel dump**: il collaudo usa 6 moduli costruiti che coprono
  i casi limite — due interventi nello stesso giorno, sabato, commessa chiusa,
  settore mancante — poi rimossi.
- Restano gli aperti precedenti: risincronizzazione dopo la v1.9.12, valorizzazione
  a costo (`CEH`), `workload_overview` e `dgb_activities` non uniformati.
