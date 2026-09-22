# Manuale Amministratore — v1.7.58

## Gestione Dipartimenti / Unità Organizzative
Menu: **Amministrazione → Dipartimenti / Unità Org.** (permesso `manage_departments.php`).

### Creare
1. Nome (max 150, univoco).
2. Tipologia: `Servizio a Valore` o `Non a Valore` (obbligatoria).
3. Aggiungi. I nomi duplicati sono rifiutati.

### Modificare
- Editare nome/tipologia in tabella e premere Salva.
- Casella Attivo: se deselezionata, il dipartimento non compare più nella select del form dipendente ma resta storicizzato.
- Ogni modifica è registrata nello Storico modifiche (old→new, autore, data).

### Eliminare
- Elimina rimuove il dipartimento; i dipendenti collegati vengono scollegati (campo svuotato), non cancellati. Tracciato come DELETE.

### Colonna "Dipendenti"
Numero di dipendenti associati: valutare prima di disattivare/eliminare.

## Assegnazione nel form dipendente
In Anagrafica → Inquadramento HR il Dipartimento è un menu a discesa alimentato dai soli dipartimenti attivi (opzione "— Nessuno —" per lasciare vuoto).

## Permessi
Assegnare `manage_departments.php` (view + edit/create) ai ruoli abilitati in Gestione Permessi (matrice RBAC).
