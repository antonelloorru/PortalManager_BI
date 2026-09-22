# Release Checklist — PortalManager v1.8.88

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.87.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.88` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 7 in `app/` | invariati da v1.8.87 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.88**

## 2. Il difetto corretto

- [x] `tec` era letto da `$_GET` e usato **solo per mostrare la scheda**: tutti
      gli altri riquadri restavano generali
- [x] Nessun dato sbagliato, ma **dati giusti riferiti a un'altra cosa** accanto a
      una scheda personale: nessun controllo automatico poteva rilevarlo
- [x] Ora normalizzato in `normFilters()` e applicato da `where()`, usata da
      pannello, ripartizione, scoperti, code ed export

## 3. Un difetto dell'ottimizzatore, non della clausola

| Forma | Risultato |
|---|---|
| `EXISTS` correlato | **2** |
| `IN (SELECT …)` | **2** |
| `JOIN` | **520** |

- [x] Riprodotto in **SQL puro**: non è un errore della condizione
- [x] `v_cm_sd_ticket` aggrega `v_cm_sd_messaggi`, costruita su `cm_sd_messages` e
      `v_cm_sd_team`: tre livelli di annidamento
- [x] Il join evita il problema ed è più leggibile
- [x] **Lezione annotata**: su viste annidate, verificare sempre una sottoquery
      contro il join equivalente

## 4. Ordine dei parametri

- [x] Il join precede la `WHERE`, quindi i suoi parametri precedono gli altri:
      `array_merge($pre, $a)`
- [x] Errore muto se invertito: query valida, risultato assurdo
- [x] Intercettato dal collaudo che confronta pannello e scheda

## 5. QA — il filtro agisce su tutti i riquadri

| | Ticket | Presi | Esc% | Scoperti | Operatori | Classi |
|---|---|---|---|---|---|---|
| GENERALE | 3.512 | 1.470 | 7,1 | 14 | 40 | 6 |
| Enrico Mancini | 613 | 613 | 5,1 | 0 | 1 | 2 |
| Sebastiano Chiarini | 520 | 520 | 6,2 | 0 | 1 | 2 |
| Emanuele Bressi | 278 | 278 | 10,8 | 0 | 1 | 2 |
| Greta Ferrante | 49 | 49 | 4,1 | 0 | 1 | 2 |

- [x] **Pannello filtrato = scheda** su presi in carico e tasso, per tutti e 4
- [x] Operatori filtrati = 1, ed è il tecnico giusto
- [x] Ticket filtrati mai superiori ai generali

## 6. QA — report stampabili

| Report | Ticket | Classi | Righe tabella | Barra |
|---|---|---|---|---|
| generale | 3.512 | 6 | 4 | 100,0% |
| personale | 520 | 2 | 1 | 100,0% |

- [x] Il personale aggiunge 12 contratti, 11 code, 1.402,0 h di moduli
- [x] Barra mai oltre il 100%
- [x] Pagina autonoma con `exit` prima di `header.php`: nessun menu né barra
- [x] CSS in **millimetri e punti**, non pixel: sono le unità del foglio
- [x] `page-break-inside: avoid` contro il titolo orfano
- [x] **Avvertenze nel piè di pagina**: un documento stampato circola senza chi lo
      ha prodotto

## 7. QA — chiamate

| Verifica | Esito |
|---|---|
| Metodi statici inesistenti | **0** |
| Metodi `SdModel` inesistenti | **0** |
| Avvisi o errori nel collaudo | **0** |

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c88` fresco | 588 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c88` | 588 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c88` | 588 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **587 → 588**

## 9. Nota di metodo

Il difetto segnalato non produceva dati errati: produceva dati **corretti
riferiti a un insieme diverso**, accostati a una scheda personale.

Nessuna quadratura lo avrebbe intercettato, perché ogni numero era giusto. Serve
un occhio che guardi la pagina intera e chieda «a chi si riferisce questo?» — ed è
esattamente quello che avete fatto.

## 10. Aperto

- I colori di sfondo nella stampa richiedono **«Grafica di sfondo»** attiva nel
  browser: è una limitazione dei browser, non aggirabile dal server.
- Il report non contiene i **grafici**: le barre SVG del pannello non sono
  riprodotte, solo le tabelle e la barra di ripartizione. Aggiungerli è possibile
  ma va deciso quali, perché su carta lo spazio è finito.
- Restano aperti: SLA non definiti, `tt_ticket` e `tt_ticket_act` non esportate.
