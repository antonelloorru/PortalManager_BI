# certV — HOTFIX v5.7.1

## Bug fix
**Errore:** `PDOException SQLSTATE[42S22]: Column not found: 1054 Unknown column 'is_active' in 'field list'` su `manage_technologies.php` (riga 97) e in `header.php` (filtro gerarchico Catalogo, voce sidebar).

**Causa:** la tabella `brands` non possiede la colonna `is_active` (nessuna migration la introduce). Le query la referenziavano erroneamente assumendo presenza analoga ad altre tabelle.

## File modificati (2)

- `manage_technologies.php` — query `$brands` rimosso filtro `WHERE is_active = 1`
- `header.php` — query `$nav_brands_sidebar` rimosso filtro `WHERE b.is_active = 1` (resta il filtro su `certifications.is_active` per mostrare solo brand con cert attive)

## Deployment

Estrarre i 2 file sopra il portale esistente, sovrascrivendo. Nessuna SQL migration necessaria.

```powershell
cd C:\Data\SviluppoSoftware\xampp\htdocs\portalbrand
Expand-Archive -Path "C:\percorso\al\certV-5.7.1-hotfix.zip" -DestinationPath . -Force
```
