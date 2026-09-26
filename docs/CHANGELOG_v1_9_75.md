# PortalManager v1.9.75 — Ripartizione oraria (ordinarie / fuori orario / extra)

Pagine: Attività & Rendicontazione DGB, Relazione di Servizio IT, Service Desk.

## Caso di test — Imbrosciano Alessandro, 16/09/2026
| Modulo | Orario | Ore lavorate | Extra dichiarate |
|---|---|---|---|
| WTS_IMAL_26_025240 | 09:00–10:00 | 1 | 0 |
| WTS_IMAL_26_025239 | 10:00–12:00 | 2 | 0 |
| WTS_IMAL_26_021853 | 12:00–00:00 (+1 g) | 11 (+1 h viaggio) | 6 |

Regola aziendale: ordinario lun–ven 09:00–13:00 e 14:00–18:00.
Atteso: 1 + 2 + 5 = **8 h ordinarie**, 11 − 5 = **6 h fuori orario** (= 6 h extra dichiarate).

## Diagnosi
| Pagina | Logica precedente | Esito sul caso |
|---|---|---|
| DGB | frazione dell'intervallo calcolata sui soli ORARI: la fine 00:00 del giorno dopo valeva come 00:00 dello stesso giorno → turno 0% ordinario; frazione poi applicata alle ore nette (11 su 12) | 3 / **11** / 6 |
| Relazione IT (grafici, quadro) | fascia del modulo INTERO in base all'ora di inizio (`in_working_hours`) | 15 / **0** / 6 |
| Relazione IT (contratti) | un modulo con extra > 0 escluso per intero dalle ordinarie; regime «Straordinario» per tutte le 11 h | 3 + 6 straord. |
| Service Desk | fascia del modulo intero | 15 / **0** / 6 |

## Correzione
- `app/PmOrario.php`: regola unica. Ore ordinarie = sovrapposizione reale, giorno per
  giorno, fra inizio–fine e le fasce ordinarie dei giorni feriali, limitata alle ore
  dichiarate; fuori orario = ore − ordinarie. Gestisce turni oltre la mezzanotte, fine
  assente o precedente all'inizio (decide l'ora di inizio, come prima). Fasce in
  `app_settings.pm_orario_fasce` (default `09:00-13:00,14:00-18:00`).
- DGB (`DgbModel`): la regola sostituisce la frazione in riepilogo del periodo e nelle due
  distribuzioni temporali; chi lavora a turni resta ordinario.
- Relazione IT (`ItServiceModel`): se la vista espone `modulo`, il rapportino viene
  agganciato e le ore di ogni riga sono ripartite con la regola (grafici mensile e
  giornaliero, quadro, tabella aggregata: «moduli fuori orario» = moduli con ore fuori
  orario, più il nuovo totale di ore). Contratti: ordinarie = ore − extra dichiarate;
  regime «Ordinario + straordinario (x h)» per i moduli misti.
- Service Desk (`SdModel`): stessa ripartizione in quadro, dettaglio squadra, contratti e
  distribuzione per fascia, se `v_cm_sd_moduli` espone il codice del modulo (`modulo`,
  `report_code` o `codice_modulo`); altrimenti resta la logica precedente.
- Sistema → Prestazioni: stato della ripartizione per pagina e fasce in uso.

## Risultati (dati reali)
| | Ordinarie | Fuori orario | Extra |
|---|---|---|---|
| DGB, prima | 3 | 11 | 6 |
| **DGB, dopo** | **8** | **6** | 6 |
| Relazione IT / Service Desk, prima | 15 | 0 | 6 |
| **Relazione IT / Service Desk, dopo** | **8** | **7** | 6 |
| Relazione IT contratti, prima | 3 | — | 6 straord. |
| **Relazione IT contratti, dopo** | **8** | — | 6 straord. |

Nota: nel rapportino il turno 12–00 vale 12 h (viaggio compreso, nessuna colonna viaggio),
quindi IT e Service Desk mostrano 7 h fuori orario; la differenza con le 6 extra è l'ora di
viaggio. Se la colonna `ore` della vista di produzione esclude il viaggio, il risultato è 8 / 6.

Effetto sull'archivio DGB: fuori orario di agosto da 1.129,9 a 491,5 h (extra dichiarate 153).

## QA
Regola 14/14 (SQL e PHP identici: turni notturni, weekend, pausa, fine assente o
precedente all'inizio); caso di test sulle tre pagine; Service Desk senza codice modulo →
risultati identici a prima; fasce personalizzate lette da app_settings. Costo: circa
+70 ms per query su 3 mesi nella Relazione IT e nel Service Desk. `php -l` OK; migration
RUN1/RUN2 err=0; schema_version → 1.9.75.
