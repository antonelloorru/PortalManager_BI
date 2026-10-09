# TECHNICAL DESIGN — v1.10.31

- `pratix_orders.php`:
  - la query delle righe diventa `v_cm_pratix_righe r LEFT JOIN cm_projects pj ON pj.id = r.commessa_id`, con `pj.external_link AS link_sp`, in una sola query come prima;
  - il link è reso nella cella del link Commessa;
  - nell'export XLSX `$rigHead` e `$rigRows` hanno una colonna in più (14).
- `project_dashboard.php`: intestazione `.page-header h1`, link da `ProjectModel::find()` (`p.*`, campo `external_link`).
- Il link è validato con `~^https?://~i` prima del rendering, altrimenti è mostrato come testo inattivo.
- Schema ER: nessuna modifica. Relazione usata: `v_cm_pratix_righe.commessa_id → cm_projects.id`.
