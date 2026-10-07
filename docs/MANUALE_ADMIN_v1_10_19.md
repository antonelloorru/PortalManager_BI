# MANUALE AMMINISTRATORE — v1.10.19 · Pagina «Lavora con noi»

1. WordPress: caricare `pm-ats-1.3.0.zip` (sostituisci versione installata).
2. *Lavora con noi › Impostazioni › Aspetto*:
   - **Elenco = Fisarmonica con modulo a lato (Lavora con noi)**;
   - colori titoli `#234d85` e accento `#ec7f31` (predefiniti);
   - **Sezione di testata**: attivarla solo se la pagina non ne ha già una (con Divi la testata della pagina resta quella del tema);
   - titolo (`{We}` evidenziato), introduzione, titoli «Posizioni Aperte» / «Compila il form».
3. Nella pagina «Lavora con noi» sostituire elenco e Contact Form 7 con lo shortcode `[pm_ats_jobs]`, oppure `[pm_ats_jobs layout="accordion" hero="0"]` solo su quella pagina. Le candidature arrivano così in PortalManager con il CV.
4. PortalManager › Sito web › **Sincronizza tutto**: riscrive le posizioni con la nuova struttura e senza le note interne.
5. Se il tema contiene copie di `job-single.php` o `apply-form.php` (`<tema>/pm-ats/`), la scheda *Versione e manutenzione* le segnala come obsolete: aggiornarle partendo da quelle del plugin 1.3.0.
6. In PortalManager il campo «Descrizione» della posizione resta solo per uso interno. Il testo pubblico va in Presentazione azienda, Informazioni sull'offerta, Competenze (requisiti, tecniche, trasversali), Titolo preferenziale, Cosa offriamo e Benefit.
