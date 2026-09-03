# Release Checklist — PortalManager v1.9.14

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.13.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.14` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.13 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.14**

## 2. Il difetto

- [x] Tre resi diverse: linee a video, barre in stampa, barre nella scheda
- [x] Nate da **tre release diverse** (v1.8.84, v1.8.86, v1.9.7), ciascuna
      ragionevole da sola
- [x] Il difetto è nato dal **non aver mai guardato le tre insieme**

## 3. Linee e non barre

- [x] Le barre impilate affermano che le serie **si sommano in un totale**
- [x] Vero per i ticket, ma il riquadro mostra accanto il **tasso di
      escalation**, che è una percentuale e in una pila non si interpreta
- [x] Le linee affermano meno ed è sempre vero per questi dati

## 4. Le assenze restano impilate

- [x] Ferie, permessi, recuperi, malattia **si sommano davvero**
- [x] A linee si sarebbe dovuto sommare a occhio quattro linee per il totale
- [x] **La coerenza che serve è fra rappresentazioni della stessa grandezza**,
      non fra tutti i grafici del portale

## 5. Scelte tecniche

- [x] Il riquadro a video resta inline: ha un **secondo asse** in percentuale che
      gli altri due non hanno. Generalizzare per un solo chiamante costa più del
      beneficio
- [x] Raggio marcatori `> 40 punti ? 1.4 : 2.5`: su 92 punti in 650px i cerchi da
      2,5 occupano 5 pixel su 7 e **la linea scompare**
- [x] `$mx` parte da **0.01 e non da 0**: con dati tutti a zero produrrebbe `INF`
      nelle coordinate — un SVG che il browser non disegna, **senza alcun errore
      visibile**

## 6. Un difetto trovato per caso

- [x] Nella scheda personale il colore delle note era `#cbd5e1` nella legenda e
      `#94a3b8` nel grafico
- [x] Due grigi vicini: **invisibile a occhio ma reale**
- [x] Trovato solo perché la conversione ha fatto rileggere entrambi i punti —
      nessun controllo lo intercetta e nessun utente lo segnala

## 7. QA

| Verifica | Esito |
|---|---|
| Mensile, 6 punti | 4 polyline, 24 cerchi, **0 rect** |
| Giornaliero, 92 punti | 4 polyline, 368 cerchi, **0 rect** |
| Raggio si riduce | 2,5 → **1,4** |
| Etichette per grana | `26-01` / `01/05` |
| Punti fuori dal riquadro | **0 su 368** |
| Un punto solo | non disegna |
| Serie tutte a zero | nessuna divisione per zero |
| Colori legenda = grafico | coerenti |
| Barre residue | **1** — solo assenze |
| Funzione estratta **dal sorgente vero** | sì |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| `<div>` in stampa | **78 = 78** |
| `;` nei commenti SQL | **0** (uno intercettato e corretto) |

## 8. Aperto

- **Verifica a schermo non eseguita**: la resa è verificata sull'SVG generato —
  numero di elementi, coordinate, raggi — non aprendo il portale.
- Restano gli aperti precedenti: risincronizzazione dopo la v1.9.12, tariffe di
  listino da compilare, giorni vuoti non riempiti sull'asse giornaliero,
  `workload_overview` e `dgb_activities` non uniformati.
