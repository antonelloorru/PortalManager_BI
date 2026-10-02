# CHANGELOG — v1.9.95

## Report direzionale — Link SP e Scheda Progetto
Subito dopo la colonna **Commessa**, in tutte le tabelle della pagina che la contengono:

| Tabella | Colonne aggiunte |
|---|---|
| Commesse da presidiare | Link SP · Scheda Progetto |
| Valore ordini per competenza | Link SP · Scheda Progetto |

- **Link SP**: `cm_projects.external_link` (apre il gestionale/SharePoint in una nuova scheda), «—» se assente.
- **Scheda Progetto**: pulsante verso `project_dashboard` della commessa, stessa etichetta e destinazione di Commesse / Progetti.
- La tabella «Agenti commerciali» non ha una colonna Commessa: invariata. Stampa ed export XLSX invariati.
- `DirModel::attenzione()` e `competenza()` restituiscono anche `project_id` ed `external_link`; `commesse()` anche `external_link`.
