# MANUALE AMMINISTRATORE — v1.10.18

- Aggiornare il plugin WordPress a **1.2.0** (`pm-ats-1.2.0.zip`, sostituisci versione installata): necessario per la bozza sul sito e per l'anteprima con il tema. Con plugin precedenti la pubblicazione funziona, la bozza viene trattata come pubblicata e l'anteprima è solo locale.
- Permessi: lo stato di pubblicazione richiede **edit** su *Sito web (WordPress)* (`wp_ats_sync.php`); l'anteprima richiede **view**.
- Le posizioni esistenti partono come «Pubblicata»: il comportamento non cambia finché non si modifica lo stato.
- L'anteprima si apre nel browser dell'utente sull'indirizzo pubblico del sito, che deve quindi essere raggiungibile dal client.
