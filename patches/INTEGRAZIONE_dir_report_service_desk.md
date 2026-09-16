# PortalManager v1.9.30 — Integrazione multi-select + Cognome Nome
## Su `dir_report.php` e `service_desk.php`

Le due pagine usano un pattern MVC (`DirModel`, `SdModel`) diverso da
`report_servizi_it.php`. Le patch di seguito sono minimali e non toccano
la logica di business.

---

## Passi comuni ai due file

### 1) Includi CSS/JS del componente
Nella `<head>` (o subito prima del primo `<select>`) aggiungi:
```html
<link rel="stylesheet" href="assets/css/pm-multiselect.css">
<script src="assets/js/pm-multiselect.js" defer></script>
```

### 2) Includi il helper PHP
In alto al file, dopo `require_once('functions.php');`:
```php
require_once(__DIR__ . '/app/PmFilters.php');
```

### 3) Marca le select da convertire
Su ogni `<select>` da rendere multi-select, aggiungi/modifica:
```html
<select name="agente[]" multiple class="pm-ms" data-placeholder="Cerca agente…" data-allow-clear>
  <option value="1">Rossi Mario</option>
  ...
</select>
```
Note:
- `name="foo[]"` invece di `name="foo"` (permette invio multiplo).
- `class="pm-ms"` attiva il componente.
- `data-placeholder`, `data-allow-clear`: opzionali.

### 4) Lato PHP — leggi array e costruisci IN(...)
Al posto di:
```php
$ag = (int)($_GET['agente'] ?? 0);
```
usa:
```php
$agenti = PmFilters::ints($_GET['agente'] ?? []);
[$sqlAg, $bindAg] = PmFilters::inClause('a.agente_id', $agenti);
// Poi nel costruttore WHERE:
if ($sqlAg) { $wheres[] = $sqlAg; $binds = array_merge($binds, $bindAg); }
```

### 5) Etichetta anagrafiche "Cognome Nome"
Nelle query SELECT sostituisci `CONCAT_WS(' ', first_name, last_name)` con:
```sql
TRIM(CONCAT_WS(' ', second_name, first_name))    -- tabella dgb_operator
TRIM(CONCAT_WS(' ', last_name,  first_name))     -- tabella employees / users
```
Helper: `PmFilters::personSql('first_name', 'last_name')`.

---

## Patch mirata `dir_report.php`

Nel model `DirModel::normFilters()`, sostituisci la lettura di `agente`:

```php
// PRIMA
$out['agente'] = trim((string)($in['agente'] ?? ''));

// DOPO (v1.9.30)
$out['agenti'] = PmFilters::ints($in['agente'] ?? $in['agenti'] ?? []);
$out['agente'] = $out['agenti'] ? implode(',', $out['agenti']) : ''; // compat storica
```

Nelle query di `DirModel` che filtrano per `agente`, sostituisci:
```php
if ($f['agente'] !== '') {
    $sql .= ' AND a.agente_id = ?';
    $binds[] = (int)$f['agente'];
}
```
con:
```php
[$s, $bb] = PmFilters::inClause('a.agente_id', $f['agenti'] ?? []);
if ($s) { $sql .= ' AND ' . $s; $binds = array_merge($binds, $bb); }
```

Nella view del file `dir_report.php`, sostituisci il `<select name="agente">`
con multi-select come da passo 3.

---

## Patch mirata `service_desk.php`

Il file usa `SdModel`. Le liste sono già disponibili (`$elencoTeam`,
`$team`). Basta:

1) Nella view HTML:
```html
<!-- PRIMA -->
<select name="team_id">
  <option value="">Tutti</option>
  <?php foreach ($elencoTeam as $t): ?>
    <option value="<?= (int)$t['id'] ?>"><?= h($t['nm']) ?></option>
  <?php endforeach; ?>
</select>

<!-- DOPO -->
<select name="team_id[]" multiple class="pm-ms" data-placeholder="Cerca membro team…" data-allow-clear>
  <?php foreach ($elencoTeam as $t): ?>
    <option value="<?= (int)$t['id'] ?>"
      <?= in_array((int)$t['id'], PmFilters::ints($_GET['team_id'] ?? []), true) ? 'selected' : '' ?>>
      <?= h($t['nm']) ?>
    </option>
  <?php endforeach; ?>
</select>
```

2) In `SdModel::normFilters()`:
```php
$out['team_ids'] = PmFilters::ints($in['team_id'] ?? []);
```

3) Nelle query del model (metodo `headline`, `operatori`, `teamDettaglio`, ecc.):
```php
[$sqlT, $bindT] = PmFilters::inClause('t.employee_id', $f['team_ids'] ?? []);
if ($sqlT) { $where[] = $sqlT; $binds = array_merge($binds, $bindT); }
```

4) Format "Cognome Nome" nelle liste già ordinate per cognome (`elencoTeam()` lo è):
```php
SELECT id, TRIM(CONCAT_WS(' ', last_name, first_name)) AS nm
FROM employees WHERE ...
ORDER BY last_name, first_name
```

---

## Verifica UX

Dopo il deploy, apri la pagina e verifica:
- Cliccando la select si apre un dropdown con **barra di ricerca in cima**.
- Digitando 3-4 lettere del cognome, la lista si filtra.
- **Click semplice** aggiunge/rimuove l'elemento (nessun Ctrl).
- Chip con X per rimuovere singole voci.
- X globale (se `data-allow-clear`) svuota tutto.
- Il form invia array (`operator[]=100&operator[]=101`).
- Le anagrafiche in tabella sono nel formato `Cognome Nome`.
