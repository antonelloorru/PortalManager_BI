# Deployment — PortalManager v1.9.3

## 1. Contenuto

```
VERSION                          1.9.3
header.php                       (ROOT)  inclusione di CSS e JS
assets/pm-tables.css             NUOVO — tabelle scorrevoli
assets/pm-tables.js              NUOVO — attivazione automatica
app/DgbModel.php                 nomi in forma Cognome Nome
app/Version.php                  PM_VERSION = 1.9.3
gli altri file                   invariati da v1.9.2
sql/migration_v1_9_3.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_3.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`header.php` in ROOT**, `app/DgbModel.php`, `app/Version.php`.
3. **Creare la cartella `assets\`** in ROOT e copiarvi i due file.
4. SQL Runner: `sql/migration_v1_9_3.sql` oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.

La cartella `assets\` è nuova: se non esiste, i due file non vengono trovati e le
tabelle restano come prima — senza errori, ma senza il miglioramento.

## 3. Verifica

**Commesse / Progetti**: scorrendo l'elenco, l'intestazione delle colonne deve
**restare visibile** in cima.

Stesso comportamento su tutte le altre viste: Service Desk, Relazione IT, Report
direzionale, Carico & Sovrapposizioni.

**Ridimensionate la finestra**: su una finestra bassa la tabella si accorcia, su
una stretta il corpo cala. Se la tabella diventa più larga della finestra, anche
la **prima colonna resta ferma** durante lo scorrimento orizzontale.

## 4. Dove NON si attiva

Deliberatamente:

- tabelle con **meno di 8 righe** — l'intestazione fissa non serve e il bordo
  aggiunto è solo rumore
- **report di stampa** — hanno un impaginato proprio
- tabelle **senza intestazione**

## 5. La stampa

Nei report la tabella si stampa **per intero**, con l'intestazione ripetuta a ogni
pagina.

Era il rischio principale: un contenitore scorrevole stampa solo la porzione
visibile, e il resto non esce dalla stampante. Il foglio di stile lo disattiva in
stampa.

## 6. I nomi

I menu a tendina di **Attività & Rendicontazione DGB** mostravano `Enrico Mancini`
invece di `Mancini Enrico`.

`dgb_operator` tiene il cognome in `second_name`, e la concatenazione era
invertita in tre punti di `DgbModel`. Corretta: il nome esce nella forma giusta e
l'ordinamento segue da solo.

Se ne trovate altri, ditemi **in quale schermata**: la forma dipende da dove il
nome viene costruito, e ogni classe lo fa per conto proprio.

## 7. Personalizzare le altezze

```css
/* in assets/pm-tables.css */
.pm-scroll { max-height: 62vh; }
```

`vh` è la percentuale dell'altezza della finestra. Alzandolo si vede più tabella e
meno del resto della pagina.

## 8. Rollback

Rimuovere la cartella `assets\` e ripristinare `header.php`, `app/DgbModel.php`,
`app/Version.php` dalla v1.9.2.

```sql
UPDATE app_settings SET setting_value='1.9.2'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
