# certV — Manuale Utente

Guida pratica per gli utenti del portale certV.

## Indice

1. [Primo accesso](#primo-accesso)
2. [Login con 2FA](#login-2fa)
3. [Il mio dossier](#dossier)
4. [Sicurezza account](#sicurezza)
5. [Recruiting (per ruoli HR/Brand Manager/Recruiter)](#recruiting)
6. [Certificazioni](#certificazioni)
7. [Notifiche](#notifiche)
8. [Domande frequenti](#faq)

---

## 1. Primo accesso <a id="primo-accesso"></a>

Apri il portale al link che ti è stato comunicato dall'amministratore.

1. Inserisci **email aziendale** e **password** (creata dall'admin)
2. Click **Entra nel sistema**

Al primo accesso ti consigliamo di cambiare la password dal tuo profilo.

### Recupero password dimenticata

Se hai perso la password, **contatta l'amministratore** del portale. Per motivi di sicurezza non c'è recupero automatico via email.

---

## 2. Login con 2FA <a id="login-2fa"></a>

Se l'amministratore ha attivato la 2FA per il tuo account, dopo email + password ti verrà chiesto un secondo codice.

### Metodi disponibili

- **App authenticator** (TOTP): codice a 6 cifre da Google/Microsoft Authenticator
- **Email**: codice a 6 cifre inviato all'email del tuo account
- **Recovery code**: codici di emergenza salvati durante il setup

### Come fare login con TOTP

1. Apri l'app authenticator sul telefono
2. Cerca la voce "certV"
3. Digita il codice a 6 cifre nella casella
4. Click **Verifica**

Il codice cambia ogni 30 secondi. Se non funziona, verifica che l'orologio del telefono sia sincronizzato.

### Come fare login con Email

1. Tab **Email** nella schermata 2FA
2. Click **Invia codice email**
3. Apri la casella di posta — riceverai un codice a 6 cifre
4. Inseriscilo e click **Verifica**

Il codice scade in 10 minuti. Puoi richiedere un nuovo invio dopo 60 secondi.

### Come usare i Recovery codes

Solo se hai perso accesso ad app e email:

1. Tab **Recupero**
2. Inserisci uno dei codici tipo `XXXX-XXXX`
3. Click **Verifica codice di recupero**

**Ogni codice è valido una sola volta.** Una volta usato, è bruciato.

---

## 3. Il mio dossier <a id="dossier"></a>

Menu **Il mio dossier** mostra:

- Dati anagrafici personali
- Brand assegnati al tuo profilo
- Storico certificazioni
- Skill e competenze
- Documenti personali

Puoi modificare alcune informazioni (telefono, indirizzo, foto profilo). I dati professionali sono modificabili solo dall'HR.

---

## 4. Sicurezza account <a id="sicurezza"></a>

Menu **Sicurezza account** (visibile solo se l'admin ha autorizzato la 2FA per te).

### Setup TOTP (App authenticator)

1. Click **⚙ Configura TOTP**
2. Scarica un'app authenticator sul telefono:
   - **Google Authenticator** (consigliato)
   - **Microsoft Authenticator**
   - **Authy**
   - 1Password / Bitwarden
3. Apri l'app, scegli "Aggiungi account" → "Scansiona QR code"
4. Inquadra il QR mostrato sullo schermo
5. L'app salva l'account e mostra un codice a 6 cifre
6. Inserisci quel codice nella casella del portale
7. Click **Verifica e attiva**

### Recovery codes

Subito dopo aver configurato TOTP, il portale ti mostra **10 codici di recupero** (`XXXX-XXXX`).

**Salvali subito**:
- **Stampa** la pagina (bottone 🖨 Stampa)
- **Oppure copia** in un password manager (1Password, Bitwarden, KeePass)
- **Non screenshottare** e basta — se perdi il telefono perdi anche lo screenshot

I codici sono mostrati **una sola volta**. Se li perdi, devi rigenerarli (i vecchi non funzioneranno più).

### Rigenerare i recovery codes

Quando vuoi puoi cliccare **🔄 Rigenera codici**. I vecchi vengono invalidati. Custodisci i nuovi nello stesso modo dei precedenti.

### Email OTP

Se l'admin ha attivato l'email OTP per te, riceverai automaticamente i codici via email al login. Non serve fare nulla in più. Mostrato come "Attivo" nella pagina Sicurezza account.

### Disattivazione TOTP

Per **disattivare** TOTP devi contattare l'amministratore. Non puoi farlo dal pannello utente per motivi di sicurezza.

---

## 5. Recruiting (HR / Brand Manager / Recruiter) <a id="recruiting"></a>

### Posizioni aperte

Menu **Recruiting → Posizioni aperte**.

**Filtri** in alto: status, brand, priorità.

**Azioni disponibili**:
- ✅ **Esporta XLSX** — file Excel con indice tabellare + foglio dettaglio per ogni posizione
- ✅ **Stampa PDF** — scheda formattata per ogni posizione (apri pagina di stampa, "Salva come PDF")
- 🆕 **Nuova posizione** — crea una nuova JD
- 📋 **Master text** — gestisce i testi standard (presentazione aziendale, GDPR)
- 🎨 **Template** — salva blocchi di testo riutilizzabili (hard skills, we offer, ecc.)

### Pipeline candidati

Menu **Recruiting → Pipeline candidati**.

Drag & drop dei candidati tra gli stage:
- CV ricevuto → Screening → Test tecnico → Colloquio HR → Colloquio tecnico → Offerta inviata → Assunto / Rifiutato

Click sul nome del candidato per vedere il dossier completo.

### Pubblicazione su portali

Da una posizione aperta, click **Pubblica su portali**:
- LinkedIn
- Indeed
- InfoJobs
- Glassdoor
- Monster
- JobRapido
- Custom

Inserisci URL/ID dell'annuncio per tracciare la pubblicazione.

### Agenzie e contratti

- **Agenzie** — anagrafica delle agenzie partner
- **Contratti agenzie** — termini commerciali per ogni partner

---

## 6. Certificazioni <a id="certificazioni"></a>

### Caricare un certificato

Menu **Competenze → Carica certificato**.

1. Selezione certificazione dal catalogo
2. Date di rilascio e scadenza
3. Upload PDF/JPG del certificato (max 10 MB)
4. Salva

### Visualizza storico

Menu **Competenze → Storico competenze** mostra tutte le certificazioni passate, attuali e in scadenza.

### Pianificare un esame

Menu **Competenze → Pianifica esame**:
1. Seleziona certificazione
2. Data prevista esame
3. Centro di test
4. Note logistiche

Una volta sostenuto, l'HR carica l'esito.

---

## 7. Notifiche <a id="notifiche"></a>

L'icona campanella in alto a destra mostra il numero di notifiche non lette.

### Tipi di notifiche

- **Certificazione in scadenza** — 90 / 60 / 30 / 7 giorni prima
- **Nuovo candidato** assegnato (per recruiter)
- **Esame pianificato** confermato
- **Posizione aperta** nuova nel tuo dipartimento
- **Sistema** — comunicazioni admin

### Email di notifica

Le notifiche più importanti vengono **anche** inviate via email all'indirizzo del tuo account. Se non ricevi email, contatta l'admin (potrebbe essere SMTP non configurato o tu nello spam).

---

## 8. Domande frequenti <a id="faq"></a>

### Non vedo "Sicurezza account" nel menu

L'amministratore non ha ancora autorizzato la 2FA per il tuo account. Se vuoi attivarla, contattalo.

### Il codice TOTP non è accettato

- Verifica che l'orologio del telefono sia sincronizzato (impostazioni → ora automatica)
- Il codice cambia ogni 30 secondi: digitalo prima che scada
- Riprova con il codice successivo

### Non ricevo l'email con il codice OTP

- Controlla la cartella spam / indesiderata
- Verifica che il tuo indirizzo email sia corretto in **Il mio dossier**
- Se persiste, contatta l'admin (problema SMTP)

### Ho perso il telefono

Se hai i recovery codes:
1. Login normale → schermata 2FA
2. Tab **Recupero** → inserisci uno dei codici
3. Una volta dentro, vai in **Sicurezza account** e configura TOTP dal nuovo telefono

Se non hai i recovery codes: contatta l'admin per il reset.

### "Sessione scaduta" dopo poco tempo

Il timeout è 30 minuti di inattività. Se ti capita spesso, l'admin può aumentarlo modificando `Session::IDLE_TIMEOUT` in `app/Session.php`.

### Posso cambiare la mia email di accesso?

Solo l'admin può modificare l'email del tuo account, perché è anche destinataria dei codici 2FA. Richiedi all'admin il cambio.

### Come stampo una scheda posizione?

In **Recruiting → Posizioni aperte**, click sul bottone **🔴 Stampa PDF**. Si apre una pagina con tutte le posizioni filtrate. Click sul bottone in alto **🖨 Stampa / Salva PDF**. Nel dialog del browser scegli "Salva come PDF" come stampante.

### Come esporto i candidati in Excel?

In **Recruiting → Pipeline candidati**, è disponibile l'export. (Funzionalità simile alle posizioni — chiedi all'admin se non la vedi.)

### Niente più? Contatta l'admin

Per qualunque dubbio non coperto in questa guida, contatta l'amministratore di sistema. Non aprire ticket esterni o forum pubblici per problemi specifici del portale.
