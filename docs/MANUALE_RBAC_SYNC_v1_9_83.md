# Manuale amministratore — Sincronizzazione permessi

**Dove**: Sistema → Sincronizzazione permessi (solo Super Admin).

- **Simula**: mostra cosa verrebbe cambiato, senza modificare nulla.
- **Sincronizza ora**: applica. Di norma non serve: la sincronizzazione parte da sola dopo ogni aggiornamento.
- **Automatica attiva/disattivata**: interruttore della sincronizzazione automatica.
- **Regole di seeding**: «quando compare una pagina nuova in [sezione | pagina | tutte], concedi a [ruolo] [permessi]».
  Valgono solo per le pagine future. Esempio: Finance → sezione «Anagrafica & HR» → vista, esporta.
- **Catalogo pagine**: ogni pagina con provenienza (menu, router, descritta), limite del codice, numero di ruoli che la
  vedono (in arancione se nessuno), data di comparsa, etichetta NUOVA.
- **Registro esecuzioni**: ultime 20 sincronizzazioni con esito, modifiche e avvisi.

**Gestione ruoli**: alla creazione di un ruolo si può scegliere «Copia da …» per partire dalla matrice di un ruolo
esistente; altrimenti il ruolo nasce senza accessi.

**Permessi**: le pagine comparse dopo la prima sincronizzazione mostrano l'etichetta NUOVA; nessuna pagina è più
esclusa dalla matrice.
