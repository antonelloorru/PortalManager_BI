# CHANGELOG — v1.9.91

## 1. Relazione di Servizio IT — sezione «Attività DGB senza modulo di intervento»
Le attività DGB del periodo senza modulo di intervento (escluse dai totali dalla v1.9.90) hanno una sezione dedicata
(in fondo alla pagina, ancora `#dgb-senza-modulo`, link dalla nota del Riepilogo per Codice Contratto):
- riquadri per **motivo** (attività, ore, contratti, operatori);
- tabella per contratto × operatore × motivo: PM project, codice linea, cliente, attività, ore, dal/al;
- motivi dallo stato DGB: *Assegnata, non ancora rendicontata* · *In corso* · *Congelata / sospesa* ·
  *Eseguita ma senza modulo (da sincronizzare)* (in rosso: da recuperare) · *Annullata*;
- filtri applicati: periodo (data attività), contratto, stato commessa, incaricato, cliente, linea, codice linea, ricerca;
  i filtri esistenti solo sui moduli (settore, azienda, modalità, fascia, durata, sede, natura) sono dichiarati non applicabili;
- export: foglio XLSX «DGB senza modulo» (una riga per attività), tabelle in Word e stampa; voce «Attività DGB senza modulo» tra i dettagli da includere.

Settembre 2026: 314 attività / 1.818,5 h = 305 assegnate non rendicontate (1.798 h pianificate) + 9 congelate (20,5 h).
Nessuna attività eseguita senza modulo nel mese (nel complesso 35 tra chiuse/completate, da sincronizzare).

## 2. Service Desk, Report direzionale, Attività & Rendicontazione DGB — filtro unico
Stesso intervento della Relazione IT (v1.9.90): `$GLOBALS['PM_NO_AUTOFILTER'] = true` → nessuna barra automatica
(ricerca / filtri per colonna / viste / esporta client-side) agganciata alla tabella più grande; i filtri sono solo quelli del pannello della pagina.
Service Desk: rimosso anche `pm-ui-boost` (secondo motore multi-select sulle 4 select del pannello).

| Pagina | Prima | Dopo |
|---|---|---|
| Service Desk | barra automatica + pm-ui-boost (10 widget per 5 select) | nessuna barra, 5 widget |
| Report direzionale | barra automatica | nessuna |
| Attività & Rendicontazione DGB | barra automatica | nessuna |

## 3. Correzioni interne
- `ItServiceModel`: `attivitaSenzaModulo()` ora per motivo e filtrata; nuovi `attivitaSenzaModuloDettaglio()`, `filtriNonApplicabiliSenzaModulo()`.
