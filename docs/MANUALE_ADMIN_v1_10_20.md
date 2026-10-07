# MANUALE AMMINISTRATORE — v1.10.20 · Impostare la pagina «Lavora con noi»

1. WordPress › Plugin › Carica plugin → `pm-ats-1.3.1.zip` → Sostituisci.
2. Pagina «Lavora con noi» (editor Divi):
   - lasciare la sezione di testata del tema («Lavora con Noi» con l'immagine);
   - **eliminare** la sezione a due colonne esistente (testo + accordion Divi + Contact Form 7);
   - aggiungere una **sezione con una riga a una colonna** (larghezza piena) con un modulo Testo o Codice contenente `[pm_ats_lavora_con_noi hero="0"]`.
   - Il plugin crea da solo le due colonne: elenco a sinistra, modulo a destra. Non va inserito dentro una colonna ½: in quel caso il modulo finisce sotto l'elenco.
3. Impostazioni › Aspetto: titolo (`{We}` evidenziato), introduzione, titoli «Posizioni Aperte» / «Compila il form», colori #234d85 / #ec7f31. «Elenco posizioni» = Titolo cliccabile.
4. Se il sito usa una cache (plugin di cache, Divi «Static CSS»), svuotarla dopo l'aggiornamento.
