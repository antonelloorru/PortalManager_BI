# MANUALE AMMINISTRATORE — v1.10.14 · Sito web (WordPress)

## Prima installazione
1. **WordPress**: Plugin › Aggiungi nuovo › Carica plugin → `integrations/wordpress/pm-ats-1.1.0.zip` → Attiva. Si apre la **Configurazione guidata**.
2. Passo **Connessione**: Client ID, IP consentiti (IP pubblico di PortalManager), «Genera il segreto e il codice di connessione» → copiare il **codice PMATS1.…** (mostrato una sola volta).
3. Completare Pagina e modulo, Aspetto, Verifica.
4. **PortalManager**: Recruiting › Sito web — Configurazione guidata → passo 2 incollare il codice → Verifica (test + compatibilità) → Opzioni → Avvio (invio posizioni, prelievo, comando schtasks, attivazione).

## Modifiche successive
- WordPress: **Lavora con noi › Impostazioni** (schede; ognuna salva solo i propri campi). Rigenerare il segreto da *Connessione* e incollare il nuovo codice in PortalManager › Impostazioni › «Compila da codice di connessione».
- PortalManager: **Sito web — Impostazioni** (URL, client, segreto, TLS/CA/proxy, timeout, lotto, attivazione, test, versioni).

## Manutenzione e versioni
- WordPress › Impostazioni › **Versione e manutenzione**: versioni, storico aggiornamenti, template del tema obsoleti, export/import JSON (senza segreto), ripristino predefiniti, ripeti wizard.
- PortalManager › Impostazioni › **Versioni e compatibilità**: plugin richiesto ≥ 1.1.0 (1.0.0 funziona con avviso), API v1.
- Aggiornamento plugin 1.0.0 → 1.1.0: caricare lo ZIP sopra l'esistente; impostazioni e segreto conservati, wizard non richiesto.

## Errori tipici
401 segreto/orologio · 403 IP non consentito · «SSL certificate problem» → File CA · compatibilità KO → aggiornare il plugin.
