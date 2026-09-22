# Release Checklist — PortalManager v1.9.13

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.12.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.13` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 2 metodi, `trend()` modificato** | OK |
| `app/Version.php` | modificato | OK |
| 30 file restanti | invariati da v1.9.12 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.13**

## 2. La regola

- [x] Fino a **92 giorni** → giornaliero; oltre → mensile
- [x] **Soglia in giorni e non in mesi**: «tre mesi» non è una durata —
      gennaio-marzo 90 giorni, maggio-luglio 92
- [x] **92 e non 90**: due periodi che l'utente chiama entrambi «tre mesi» si
      sarebbero comportati in modo diverso, con la differenza invisibile nel
      filtro
- [x] Soglia come **parametro** in `app_settings`

## 3. La chiave resta `ym`

- [x] Cambia il formato (`2026-06` / `2026-06-15`), non il nome
- [x] Rinominarlo avrebbe richiesto di toccare pagina, due report ed export —
      quattro occasioni di dimenticarne uno
- [x] **`grana` viaggia con i dati**: dedurla da `strlen($ym) === 10` sarebbe un
      accordo implicito che si rompe al primo formato settimanale

## 4. Il grafico si adatta

| | Mensile | Giornaliero |
|---|---|---|
| Raggio punti | 3 | **1,6** |
| Etichette | 1 su 8 | **1 su 12** |
| Formato | `26-06` | **`15/06`** |

- [x] Con punti da 3px su 92 date **la linea sparisce sotto i cerchi**
- [x] Sull'asse giornaliero l'anno è ridondante: il grafico copre al massimo tre
      mesi
- [x] **Titoli corretti**: dicevano «ultimi N mesi» anche su asse giornaliero

## 5. `$fmt` interpolato nella query

- [x] Non è un parametro perché il valore **non viene mai dall'esterno**: è uno di
      due letterali scelti da un booleano
- [x] `DATE_FORMAT` con formato parametrizzato impedirebbe l'uso dell'indice

## 6. QA

| Periodo | Giorni | Grana attesa | Esito |
|---|---|---|---|
| 1–31 gennaio | 31 | giorno | **OK** |
| gennaio–marzo | 90 | giorno | **OK** |
| **maggio–luglio** | **92** | **giorno** | **OK — caso limite** |
| **maggio–1 agosto** | **93** | **mese** | **OK — appena oltre** |
| semestre | 181 | mese | OK |
| anno | 365 | mese | OK |
| un giorno solo | 1 | giorno | OK |

| Controllo | Esito |
|---|---|
| Formato `ym` | 10 e 7 caratteri |
| Etichette | `26-06`, `15/06`, `01/12` |
| `giorniPeriodo`, 7 casi | inclusi invertito e vuoto |
| Funzione estratta **dal sorgente vero** | sì |
| Migration RUN1/RUN2/RUN3 | 5 stmt, **err=0** |
| `<div>` in stampa | **78 = 78** |
| `;` nei commenti SQL | **0** |

## 7. Aperto — dichiarato

- **I giorni senza ticket non compaiono sull'asse**: su un servizio con ticket
  sparsi, due punti adiacenti possono distare una settimana e la linea suggerisce
  una continuità che non c'è. Riempirli richiede di generare il calendario e
  unirlo ai dati — modifica circoscritta, non fatta senza sapere se il fenomeno si
  presenta sui dati reali.
- **Il collaudo usa 90 messaggi costruiti** su 9 mesi, poi rimossi:
  `cm_sd_messages` è vuota nel dump.
- Restano gli aperti precedenti: risincronizzazione necessaria dopo la v1.9.12,
  tariffe di listino da compilare, `workload_overview` e `dgb_activities` non
  uniformati.
