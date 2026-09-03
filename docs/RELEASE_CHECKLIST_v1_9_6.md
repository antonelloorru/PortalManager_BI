# Release Checklist — PortalManager v1.9.6

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.5.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.6` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 1 metodo** | OK |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.5 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.6**

## 2. Il filtro

- [x] Prima impostabile **solo cliccando un nome** in fondo alla pagina: la
      funzione c'era, l'accesso no
- [x] Menu nella barra, fra Coda e Livello
- [x] Ordinato **per cognome**, con **sotto-unità nell'etichetta**
- [x] Comprende chi **non è nel team** ma ha lavorato su ticket: il filtro lo
      supportava già, il menu lo rendeva irraggiungibile
- [x] `ORDER BY in_team DESC, ordina`: la squadra prima, sono i nomi più cercati
- [x] **Tecnico fuori elenco** marcato «fuori squadra»: senza, il menu mostrerebbe
      «Tutta la squadra» con il filtro attivo

## 3. Il riepilogo nel report generale

- [x] La tabella esistente è sull'**intero archivio**; mancava il **periodo**
- [x] Cinque indicatori del periodo + dettaglio per componente + ripartizione per
      contratto
- [x] **Due tabelle con le stesse colonne e numeri diversi**: ciascuna dichiara
      il proprio periodo, il riepilogo porta le date **nel titolo**
- [x] Non ho sostituito la tabella storica: avrebbe perso l'informazione su chi
      sono i componenti e quanto pesano

## 4. Zero è un valore

- [x] `LEFT JOIN` da `v_cm_sd_team`: chi non ha lavorato compare **con zero**
- [x] Un `INNER JOIN` lo avrebbe fatto sparire, e chi legge non saprebbe se non
      ha lavorato o se è uscito dalla squadra
- [x] Verificato su un periodo vuoto: **4 componenti presenti**

## 5. QA

| Verifica | Esito |
|---|---|
| Menu: voci e ordinamento | 4, per cognome |
| Sotto-unità nell'etichetta | sì |
| Il filtro restringe | 3 moduli → **1** |
| Quadratura dettaglio = quadro | **14,0 = 14,0** |
| Periodo vuoto | **4 componenti con zero** |
| Bilanciamento `<div>` nel report | **58 = 58** |
| Chiamate a metodi inesistenti | **0** |
| Avvisi o errori PHP | **0** |
| Migration RUN1/RUN2 | 4 stmt, **err=0**, idempotente |
| `;` nei commenti SQL | **0** |

- [x] Il controllo sui `<div>` intercetta un difetto che **non produce errori
      PHP**: una sezione che ingloba le successive, visibile solo stampando

## 6. Aperto

- **I dati di Service Desk restano vuoti nel dump**: il collaudo usa righe
  costruite, poi rimosse. La struttura e le quadrature sono verificate, i numeri
  reali si vedranno in produzione.
- **Verifica a schermo non eseguita**: non posso aprire il portale in un browser.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`
  invece di `margin_total`, pagine mancanti per presidi e redditività, riepiloghi
  cadenzati.
