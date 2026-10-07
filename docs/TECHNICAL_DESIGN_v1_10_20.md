# TECHNICAL DESIGN — v1.10.20 · Layout «Lavora con noi» (plugin 1.3.1)

```
[pm_ats_lavora_con_noi] / [pm_ats_jobs] (layout = accordion, predefinito)
  └ PM_ATS_Public::scJobs → jobs-accordion.php ($mode = wt_list_mode | attributo elenco)
       .pm-ats-wt (container-type:inline-size)
         .pm-ats-wt-cols (flex row, gap 5,5%)
           .pm-ats-wt-col-jobs 47,25%  h2 · intro · h3 · ul.pm-ats-wt-list > li.pm-ats-wt-item-link > h5 > a.pm-ats-wt-link (permalink)
           .pm-ats-wt-col-form 47,25%  h3 «Compila il form» · PM_ATS_Public::form(0,'',posizioni)
       @container ≤ 760px · @media ≤ 980px → colonne impilate
```
- `a.pm-ats-wt-link::after{position:absolute;inset:0}`: l'intera casella è l'area cliccabile, con un solo link per voce (accessibile). Focus visibile.
- Regole `!important` limitate a display/flex/list-style per resistere agli stili di colonna e di elenco del tema (Divi: `.et_pb_text ul`).
- Impostazioni schema 4: `layout` predefinito `accordion`; nuova chiave `wt_list_mode` (`link` | `accordion`).
  - `PM_ATS_Upgrade::maybe` porta `grid`/`list` ad `accordion` solo nel passaggio da schema < 4, e registra la modifica.
- Stili `pm-ats-wetechs.css` accodati nell'`<head>` per ogni pagina che contiene `[pm_ats_jobs]`, `[pm_ats_apply]` o `[pm_ats_lavora_con_noi]`.
- Struttura vincolante della Job Description (1.3.0) invariata: vale per la scheda aperta dal link.
