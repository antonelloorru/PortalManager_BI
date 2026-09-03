# Release Checklist — PortalManager v1.9.7

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.6.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.7` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 3 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.6 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.7**

## 2. Le assenze

- [x] `v_cm_assenze_serie` usa `Nome Cognome`, la stessa forma dei ticket: join
      **diretto**, nessun ponte necessario
- [x] Caso **opposto** ai moduli (v1.8.87), che usano `Cognome Nome`: vale la
      pena verificare la forma prima di assumerla
- [x] Dati: **1.436,5 h su 4 persone**, 179,6 giornate

## 3. La categoria «Altre» — trovata dal collaudo

- [x] Quattro voci = 1.428,5; totale = **1.436,5**. Scarto di **8 ore**
- [x] Causa: riga con tutte le voci a zero e totale valorizzato — Chiarini,
      10/08/2026
- [x] **Ignorarlo** avrebbe lasciato numeri che non tornano; **sommare le quattro
      voci** avrebbe fatto sparire 8 ore reali; **esporre la differenza** dice che
      esiste una categoria non classificata
- [x] Chiesto al cliente di identificarla per classificarla

## 4. Le visite fuori dal totale

- [x] Già comprese nelle altre voci (v1.8.81): riconosciute dalla descrizione,
      nessun tipo dedicato nel gestionale
- [x] Esposte perché quantificano un fenomeno invisibile, **non sommate**
- [x] La nota è ripetuta **ovunque compaiano**: una colonna in una tabella di
      totali viene istintivamente sommata

## 5. I report allineati alla schermata

- [x] Grafico dell'andamento dei ticket per classe di gestione
- [x] Riquadro assenze con grafico mensile a barre impilate
- [x] `$svgBarre` come **closure condivisa**: i due report hanno grafici identici
      nella resa, senza duplicare 50 righe di generazione SVG
- [x] **Non si disegna sotto due righe**: un grafico a barre con un punto solo
      mostra un rettangolo, non un andamento
- [x] SVG come **vettori**: escono a colori senza «Grafica di sfondo»

## 6. Difetto di sintassi intercettato

- [x] Un commento SQL dentro una stringa PHP conteneva `cio'`: l'apostrofo ha
      chiuso la stringa
- [x] `php -l` l'ha rilevato — errore di sintassi, non di runtime
- [x] **Regola**: i commenti esplicativi vanno fuori dalla stringa SQL; dentro,
      solo commenti brevi e senza apostrofi

## 7. QA sui dati reali

| Verifica | Esito |
|---|---|
| ferie+permessi+recuperi+malattia+altre = totale | **1.436,5 = 1.436,5** |
| Somma per componente = quadro | **1.436,5 = 1.436,5** |
| Somma per mese = quadro | **1.436,5 = 1.436,5** |
| Ordinamento per cognome | sì |
| Barre oltre il bordo | **0 su 58** |
| Grafico con una riga | vuoto |
| Filtro per componente | 742,0 < 1.436,5 |
| Bilanciamento `<div>` nel report | **64 = 64** |
| Chiamate a metodi inesistenti | **0** |
| Migration RUN1/RUN2 | 4 stmt, **err=0**, idempotente |
| `;` nei commenti SQL | **0** |

## 8. Aperto

- **La categoria «Altre» va identificata**: 8 ore su una riga sola oggi, ma se
  cresce le regole della v1.8.81 vanno estese.
- **Le giornate sono cablate a 8 ore**, mentre nella sezione Presidi è un
  parametro in tabella: incoerenza minore, dichiarata.
- **Il grafico dei ticket non si è disegnato** nel collaudo: `trend()` restituisce
  una riga sola sui dati attuali, perché `cm_sd_messages` è vuota nel dump. La
  funzione è verificata sul grafico delle assenze, che ha 35 mesi.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`,
  pagine mancanti per presidi e redditività, riepiloghi cadenzati.
