# TECHNICAL DESIGN — v1.10.28 · Relazione Tecnici: Linea di servizio

- La sorgente è `v_cm_it_servizio.linea_label`, già usata dal filtro «Linea di servizio» e da `ItServiceModel::DIM['linea_label']`.

## `ItServiceModel`
| Lettura | Modifica |
|---|---|
| `tecniciLinea()` | espone già `MAX(s.linea_label) AS linea_label` per tecnico × codice linea |
| `rapportiCommessa()` | `MAX(s.linea_label) AS linea_label` |
| `rapportiModuli()` | `s.linea_label` |

## `TechReport`
- `H_MAIN` e `H_DET` hanno «Linea di servizio» in posizione 2.
- `main()` e `det()` inseriscono `linea_label` in posizione 2 (vuota per le righe di totale). Gli indici di `dec` e `right` delle colonne numeriche scalano di +1.
- Nella tabella «Per commessa» e in «Dettaglio moduli» la nuova colonna segue «Codice linea». Gli indici numerici scalano di +1.

## `tech_report.php`
- Intestazioni, celle e righe di totale delle due tabelle Tecnici: `colspan` 9 per il messaggio «nessun dato».
- Tabella «Per commessa»: etichetta abbreviata a 34 caratteri con il testo completo nel tooltip; `colspan` del totale portato a 5.
- Frammento AJAX del drill-down: nuova colonna.
