# Release Checklist — PortalManager v1.9.19

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.18.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.19` |
| `it_service.php` | ROOT, **modificato** | OK |
| `app/ItServiceModel.php` | **+ 5 metodi** | OK |
| `app/it_service_print.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 29 file restanti | invariati da v1.9.18 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.19**

## 2. Le tre destinazioni

- [x] **Schermata**: riquadro con intestazione raggruppata
- [x] **Export XLSX**: 3 fogli, il terzo solo se ha righe
- [x] **Report generale e personale**: entrambi

## 3. Il riquadro dice quando non fidarsi

- [x] Fascia letta sotto il 50% → avvertenza automatica
- [x] Interventi senza tariffa → avvertenza automatica
- [x] **Un riquadro che mostra numeri senza dire quanto sono fondati è peggio di
      uno che non li mostra**

## 4. La riconciliazione condizionale

- [x] Compare **solo se ci sono operatori con giorni su commesse chiuse**
- [x] Sempre vuoto avrebbe abituato a ignorarlo; assente avrebbe lasciato
      inspiegabile un totale che cambia fra due stampe

## 5. Le aree con tinta stabile

- [x] La stessa area ha lo stesso colore in tutte le righe
- [x] Un elenco testuale avrebbe richiesto di leggere ogni riga per confrontare
      due operatori
- [x] Verificato: 3 aree, 3 colori distinti

## 6. QA

| Verifica | Esito |
|---|---|
| Somma giorni per operatore = giorni-uomo | **4 = 4** |
| Ore per area = ore per operatore | OK |
| Quote per area | **100,0%** entrambi |
| Etichette area = aree dichiarate | OK |
| Filtro incaricati | 2 → **1** |
| Periodo vuoto | 0 operatori |
| `if`/`endif` in it_service | **10 = 10** |
| `if`/`endif` in it_service_print | **11 = 11** |
| `<div>` bilanciati | **64 = 64** e **29 = 29** |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| **Consolidato completo** | **755 stmt, err=0** |
| `;` nei commenti SQL | **0** (uno intercettato) |

## 7. Due difetti intercettati

- [x] **`if` senza `endif` in entrambi i file**: il blocco inserito non chiudeva
      il proprio condizionale
- [x] `php -l` li ha rilevati subito — sono errori di sintassi, non di runtime
- [x] È il difetto tipico dell'inserimento in template PHP con sintassi
      alternativa: il controllo `if`/`endif`, `foreach`/`endforeach`,
      `<div>`/`</div>` è ora parte della verifica di ogni release che tocca un
      template

## 8. Aperto

- **`fascia_letta_pct` è 0,0% sui dati di prova**: i moduli costruiti non hanno
  l'attività DGB collegata. Sui dati reali va verificato — se resta basso, il
  conteggio per fascia è in gran parte dedotto dall'orario.
- **Verifica a schermo non eseguita**: il comportamento è verificato sui dati e
  sulle espressioni del template, non aprendo il portale.
- Restano gli aperti precedenti: risincronizzazione dopo la v1.9.12,
  valorizzazione a costo (`CEH`), `workload_overview` e `dgb_activities` non
  uniformati.
