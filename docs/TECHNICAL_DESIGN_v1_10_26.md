# TECHNICAL DESIGN — v1.10.26 · Filtro globale

## pm-multiselect v2: tendina flottante

| Fase | Comportamento |
|---|---|
| `open()` | `document.body.appendChild(dropdown)`, classe `pm-ms-floating` (`position:fixed`, `z-index:10050`), `place()`, listener `scroll` (in cattura) e `resize` |
| `place(ev)` | ignora lo scroll interno della lista; calcola il rettangolo del campo e lo chiude se è fuori schermo o nascosto; sceglie sopra o sotto (`below < 240 && above > below`); `maxHeight` della lista = `clamp(96, spazio − intestazione, 300)`; `left` limitato alla finestra; `min-width` = larghezza del campo, `max-width` = min(460, viewport − 16) |
| `close()` | rimuove i listener, toglie la classe e rimette la tendina nel `.pm-ms-wrap` (stato DOM iniziale) |

Tastiera ed eventi:
- `onKey` è registrato sia su `wrap` sia su `dropdown`, perché la ricerca si trova nella tendina spostata in body. Tab chiude la tendina.
- Il clic esterno si controlla con `api.owns(node)`, cioè `wrap` più `dropdown`, al posto di `closest('.pm-ms-wrap')`.

CSS della lista:
- `overflow-x:hidden`, `overscroll-behavior:contain`, `-webkit-overflow-scrolling:touch`, `scroll-padding`;
- voci con `line-height` fissa ed ellissi;
- nessuna `contain: paint`, per non tagliare l'ombra.

La select nativa resta la fonte di verità: nessun cambiamento per form, `onchange`, reset e API (`PmMultiselect.init`, `enhance`, `refresh`).

## Filtro unico
- `PmContractFilter::banner()` non viene più chiamato dalle sei pagine. Il metodo resta disponibile.
- In tutte e sei le pagine il contatore del pannello include `contratti`, quindi il pannello si apre quando il filtro è attivo, anche se ereditato dalla sessione.
- `dgb_activities.php`: rimosso il form GET con `input[type=month]` nel grafico della distribuzione; il mese corrente è un'etichetta tra le frecce. Un solo `form[method=get]` per pagina, verificato.
- `tech_report.php`: `det` è un checkbox del form principale, gruppo «Dettagli da includere»; le celle tipologia sono solo testo.
