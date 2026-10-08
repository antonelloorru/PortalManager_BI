# MANUALE AMMINISTRATORE — v1.10.25

1. Aggiornamento: Sistema › Console › Aggiornamento → `update_v1.10.25.zip` → Aggiornamento DB.
2. Permessi (Amministrazione › Permessi ruoli, Gestione Commesse):
   - **Relazione Tecnici** (`tech_report.php`): la vista abilita la pagina e la stampa, l'export abilita CSV/XLSX/DOCX/PDF. La migrazione l'assegna ai ruoli che vedono la Relazione di Servizio IT.
   - **↳ Relazione Tecnici: valori** (`tech_report_economics.php`): produzione teorica e valore addebitato. La migrazione l'assegna ai ruoli che vedono il Report direzionale.
3. La voce compare nel menu Gestione Commesse dopo «Relazione di Servizio IT», anche con i menu personalizzati.
4. Stampe ed export finiscono nel log applicativo, modulo `TechReport`.
5. Definizioni da comunicare agli utenti:
   - **GG lavorabili**: lun–ven esclusi i festivi nazionali, senza ferie e assenze personali;
   - **Provenienza**: dipende dal campo ticket dei moduli importati dal gestionale.
