# CHANGELOG — v1.10.37 (2026-10-09)

Software 1.10.37 · Schema 1.10.37 · Upgrade `sql/migration_v1_10_37.sql` (cumulativo da 1.10.06) · **Plugin pm-ats 1.3.5** (`integrations/wordpress/pm-ats-1.3.5.zip`)

## pm-ats 1.3.5 — testata «Lavora con noi» a tutta larghezza della finestra
- **Problema**: con il codice breve inserito in una riga/colonna del tema (Divi: riga 80%, max 1080 px), l'immagine della «Sezione di testata» era larga il 100% del contenitore. Su finestre più ampie restava a 1080 px.
- **Correzione**:
  - nuova impostazione «Larghezza» (`wt_hero_width`), predefinita «tutta la finestra del browser»;
  - la testata esce dal contenitore e segue la larghezza utile della finestra, ricalcolata a ogni ridimensionamento;
  - altezza della fascia, corpo del titolo e spaziature diventano proporzionali alla finestra;
  - gli antenati con `overflow:hidden` vengono resi visibili;
  - senza JavaScript vale la regola CSS `calc(50% − 50vw)`.
- L'opzione «contenitore della pagina» ripristina il comportamento della 1.3.4.
- Versioni: plugin 1.3.5, schema impostazioni 8 (chiave aggiunta automaticamente con il valore predefinito), template invariati (1.3.4).

## PortalManager
- `WpAtsConfig::PLUGIN_RECOMMENDED = 1.3.5`: la pagina Sito web (WordPress) segnala i plugin più vecchi.
