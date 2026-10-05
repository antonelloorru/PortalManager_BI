# Manuale — Import candidati LinkedIn (v1.9.85)

## Utente (Recruiter / HR)
1. Recruiting → Posizioni: in «Codice Posizione LinkedIn» indicare l'ID dell'annuncio (es. `4412730757`)
   oppure incollare l'URL `https://www.linkedin.com/jobs/view/4412730757`: viene salvato solo il numero.
2. Recruiting → Importa candidati LinkedIn: caricare il «Report candidati» (.xlsx) esportato da LinkedIn.
3. Anteprima: ogni riga mostra ID offerta (solo cifre) e posizione associata. «Non associato» = nessuna posizione con quel codice.
4. Confermare l'import: i candidati associati ricevono la candidatura in fase «CV ricevuto».

## Amministratore
- La migration v1.9.85 bonifica i codici già salvati e ricollega i candidati LinkedIn rimasti senza candidatura.
- Se uno stesso codice è su più posizioni, l'import usa quella aperta più recente: evitare duplicati.
