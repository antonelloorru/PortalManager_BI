# Manuale — Reimpostazione password

## Utente finale
1. Nella pagina di accesso scegli **«Password dimenticata?»**.
2. Inserisci l'email con cui accedi e premi **Invia il link**. Il messaggio è lo stesso anche se l'email non è registrata.
3. Apri l'email «Reimpostazione password» (controlla lo spam) e premi **Scegli una nuova password**.
   Il link vale 60 minuti e una sola volta; una nuova richiesta annulla la precedente.
4. Scegli la password: almeno 12 caratteri, tre tipi fra minuscole, maiuscole, numeri e simboli, senza il tuo nome
   account, diversa dall'attuale. L'indicatore mostra quando i requisiti sono soddisfatti.
5. Riceverai un'email di conferma. Se hai la verifica in due passaggi, verrà richiesta al login come sempre.
   Se non hai richiesto tu il cambio, avvisa subito l'amministratore.

## Amministratore
- Impostazioni in `app_settings`: `pwd_reset_enabled`, `pwd_reset_ttl_min`, `pwd_min_length`, `app_public_url`.
- Registro: Log eventi, categoria **Auth** — «Reset password richiesto (sent|unknown|inactive|limited|mail_failed)»,
  «link non valido o scaduto», «Password reimpostata tramite link email», blocchi per IP.
- Email inviate/fallite: log email del portale (modulo `password_reset`).
- Un account **disattivato** non riceve link. Gli account del solo SSO Microsoft possono impostare una password locale.
- Blocco di un IP: 10 richieste in un'ora bloccano l'IP per un'ora (file in `uploads/.ratelimit`).
