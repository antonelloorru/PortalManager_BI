# CHANGELOG — v1.9.96

## Fix — «Scheda Progetto» nella Scheda commerciale / Report direzionale non apriva la commessa
- Causa: l'indirizzo del pulsante era codificato due volte. `url_safe()` restituisce già l'URL con `&` → `&amp;`; la v1.9.95 lo
  ripassava in `htmlspecialchars()`, producendo `r.php?r=<slug>&amp;amp;id=…`. Con gli URL non riscritti (`USE_PRETTY_URLS = 0`,
  configurazione XAMPP) il browser riceveva il parametro `amp;id` invece di `id`: la scheda progetto si apriva senza commessa.
- Correzione: `dir_report.php` usa `url_safe()` senza seconda codifica. Verificato: da «Commesse da presidiare» e da «Valore ordini per
  competenza» della Scheda commerciale (agente) il pulsante apre la stessa scheda di Commesse / Progetti (`r.php?r=…&id=<id commessa>`).

## Stesso difetto, altra pagina — Reimpostazione password (v1.9.81)
- `password_reset.php` usava `h(url_safe(...))`: con URL non riscritti il modulo della nuova password perdeva il parametro `step=new`.
  Rimossa la doppia codifica in tutti i link e moduli della pagina.
- Il link «Accedi con la nuova password» passava `r=pwreset` attraverso il router, dove `r` è il parametro dello slug: ora punta a
  `login.php?r=pwreset` (stesso schema dei rinvii di sessione).
