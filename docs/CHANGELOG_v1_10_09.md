# CHANGELOG — v1.10.09 (2026-10-06)

Software 1.10.09 · Schema 1.10.09 · Upgrade `sql/migration_v1_10_09.sql` (pacchetto cumulativo da 1.10.06)

## Problema
In Service SOC i ticket non risultavano associati agli incaricati del team SOC (componenti «unità SOC, nessun ticket»).

## Causa
1. La query predefinita sul DB SOC legge `tt_article` (messaggi): **non contiene incaricato né responsabile** (`tt_ticket` non è disponibile). Con la sola sorgente DB tutti i ticket avevano incaricato vuoto: il Team, che raggruppa per incaricato, li escludeva.
2. L'abbinamento persone → dipendenti considerava solo incaricati e responsabili: gli operatori presenti solo come **autori** dei messaggi non venivano abbinati, quindi nessun ticket arrivava ai dipendenti dell'unità SOC.

Con l'export XLSX (colonna «Incaricato») il problema non si presentava.

## Correzioni
- **Incaricato dedotto** quando la sorgente non lo riporta: primo operatore che risponde al cliente o scrive una nota interna (presa in carico). Confronto con l'export dello stesso periodo: 702 su 762 ticket (92%). Alternative misurate: più messaggi 90%, ultimo messaggio 84%.
  L'incaricato della sorgente (export o query personalizzata con alias `incaricato`) ha sempre la precedenza. Nuova colonna `cm_soc_tickets.assignee_source` = `sorgente` | `dedotto`.
- **Abbinamento operatori**: anche gli autori di risposte e note entrano in «Abbinamento persone → dipendenti».
- **Team**: colonna «Incaricato» (con il numero dei dedotti) e nuova colonna «Seguiti» (ticket su cui la persona ha scritto risposte o note). Compaiono anche gli operatori che hanno risposto senza essere incaricati.
- Filtro tecnico (tab Ticket): ticket di cui è incaricato o responsabile **oppure** su cui ha scritto.
- Elenco e dettaglio ticket: «(ded.)» / «(dedotto dai messaggi)» accanto all'incaricato dedotto.
- La migrazione forza la ricostruzione dei ticket alla prossima esecuzione della pipeline.

## Prova (solo sorgente DB, 3.566 eventi, 763 ticket)
| | prima | dopo |
|---|---|---|
| Ticket con incaricato | 0 | 756 (7 senza risposta di operatori) |
| Ticket con incaricato abbinato a un dipendente | 0 | 736 |
| Componenti SOC con ticket | 0 | 6 |
