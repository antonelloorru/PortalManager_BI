# Manuale — Attività DGB senza modulo e filtro unico (v1.9.91)

## Utente
- Relazione di Servizio IT → in fondo **Attività DGB senza modulo di intervento**: attività presenti nel DGB nel periodo che non hanno
  un modulo di intervento e quindi non sono nei totali. Per ciascuna combinazione contratto/operatore: motivo, numero di attività, ore, date.
- Motivi: *Assegnata* (pianificata, da rendicontare) · *In corso* · *Congelata* · *Eseguita ma senza modulo* (da sincronizzare) · *Annullata*.
- I filtri del pannello si applicano dove il dato esiste (periodo, contratto, stato commessa, incaricato, cliente, linea, codice linea, ricerca);
  la sezione segnala quelli non applicabili.
- Service Desk, Report direzionale, Attività & Rendicontazione DGB: i filtri si impostano solo dal pannello della pagina; non c'è più la barra
  di ricerca/filtri sopra le tabelle (filtrava solo le righe visibili).

## Amministratore
- Le righe *Eseguita ma senza modulo* indicano attività chiuse nel DGB non arrivate come moduli: rieseguire la sincronizzazione DGB → moduli.
