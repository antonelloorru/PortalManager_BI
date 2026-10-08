# MANUALE AMMINISTRATORE — v1.10.24

1. Aggiornamento: Sistema › Console › Aggiornamento → `update_v1.10.24.zip` → Aggiornamento DB.
2. Permessi (Amministrazione › Permessi ruoli, sezione Gestione Commesse):
   - **Ricerca** (`cm_search.php`): «Visualizza» abilita la pagina, «Esporta» abilita CSV/XLSX/DOCX/PDF. La migrazione l'assegna ai ruoli che vedono almeno una pagina del modulo.
   - **↳ Ricerca: importi e costi** (`cm_search_economics.php`): mostra valori, costi, ricavi, margini, costi orari e totali Pratix. La migrazione l'assegna ai ruoli che vedono il Report direzionale.
   - Ogni archivio della Ricerca compare solo se il ruolo vede la pagina corrispondente. Ad esempio Attività DGB richiede «Attività & Rendicontazione DGB» e Professionisti richiede «Professionisti esterni». Per nascondere un archivio basta togliere la vista della sua pagina.
3. La voce «Ricerca» compare nel menu Gestione Commesse anche per chi ha un menu personalizzato, grazie al merge automatico del MenuManager.
4. Gli export finiscono nel log applicativo (modulo `CmSearch`) con ambito, formato, righe e colonne.
5. Limiti dell'export: 50.000 righe per XLSX e CSV, 3.000 per DOCX e PDF. Il file indica quando è stato troncato.
