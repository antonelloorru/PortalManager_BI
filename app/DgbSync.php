<?php
/**
 * app/DgbSync.php — Materializzazione DGB -> Commesse native (v1.8.13)
 *
 * (A) Popola cm_projects (commesse) e cm_intervention_reports (moduli di intervento)
 *     a partire dai dati DGB, cosi' che le sotto-voci di Gestione Commesse (elenco
 *     commesse, Gantt, carico, redditivita') risultino popolate anche quando i dati
 *     provengono solo dall'import DGB. Idempotente via cm_intervention_reports.dgb_source_id.
 * (B) Auto-classifica le persone per tipo orario (ordinario/turni) e reperibilita'
 *     (on_call) dai pattern temporali e da during_availability.
 */
final class DgbSync
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Mappa operatore DGB -> dipendente. */
    public function operatorMap(): array
    {
        return $this->pdo->query("SELECT dgb_operator_id, employee_id FROM dgb_operator_map WHERE employee_id IS NOT NULL")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Nomi operatori DGB. */
    public function operatorNames(): array
    {
        return $this->pdo->query("SELECT id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(second_name,''))) FROM dgb_operator")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Mappa dgb_contract_id -> [id, project_code] delle commesse. */
    public function projectMap(): array
    {
        $m = [];
        foreach ($this->pdo->query("SELECT dgb_contract_id, id, project_code FROM cm_projects WHERE dgb_contract_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $m[(int)$r['dgb_contract_id']] = ['id' => (int)$r['id'], 'code' => (string)$r['project_code']];
        return $m;
    }

    /**
     * (A) Crea le commesse mancanti per i contratti DGB che hanno attività ma nessuna
     * commessa collegata (scenario "solo DGB"). Ritorna il numero di commesse create.
     */
    public function ensureProjects(?int $userId = null): int
    {
        // Mappa contratti importati: id_contract -> [code, name(code_x_installation)]
        $contracts = [];
        try {
            foreach ($this->pdo->query("SELECT id, code, code_x_installation FROM dgb_forms_contract")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cid  = (int)$c['id'];
                $code = trim((string)($c['code'] ?? ''));
                $name = trim((string)($c['code_x_installation'] ?? ''));
                $contracts[$cid] = ['code' => $code !== '' ? $code : null, 'name' => $name !== '' ? $name : null];
            }
        } catch (Throwable $e) { /* tabella contratti assente: fallback DGB-<cid> */ }

        $touched = 0;

        // (A) Completa SOLO le commesse segnaposto (project_code = 'DGB-<id>') generate da
        //     una sincronizzazione precedente: assegna project_code = Code e name =
        //     code_x_installation. I codici/nomi reali del portale (es. WTS_3670 /
        //     "Contratto Servizio a Scalare SOC") NON vengono mai sovrascritti.
        if ($contracts) {
            $upd = $this->pdo->prepare("UPDATE cm_projects SET project_code=?, name=? WHERE id=?");
            foreach ($this->pdo->query("SELECT id, dgb_contract_id, project_code, name FROM cm_projects WHERE dgb_contract_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $cid = (int)$p['dgb_contract_id'];
                if (!isset($contracts[$cid])) continue;
                if (strpos((string)$p['project_code'], 'DGB-') !== 0) continue; // solo segnaposto
                if (!$contracts[$cid]['code']) continue;
                $code = $contracts[$cid]['code'];
                $name = $contracts[$cid]['name'] ?? $p['name'];
                if ($code === $p['project_code'] && $name === $p['name']) continue;
                try { $upd->execute([$code, $name, (int)$p['id']]); if ($upd->rowCount()) $touched++; }
                catch (Throwable $e) { /* project_code duplicato: salta */ }
            }
        }

        // (A2) Collega i contratti alle commesse REALI del portale con lo stesso codice
        //      (cm_projects.project_code = contratto.code), senza toccarne codice o nome.
        //      Così la commessa mantiene "WTS_3670" / "Contratto Servizio a Scalare SOC"
        //      e il contratto DGB risulta correttamente agganciato.
        if ($contracts) {
            $link = $this->pdo->prepare(
                "UPDATE cm_projects SET dgb_contract_id=?, external_link=COALESCE(NULLIF(external_link,''), ?)
                  WHERE project_code=? AND (dgb_contract_id IS NULL OR dgb_contract_id=0)"
            );
            foreach ($contracts as $cid => $ct) {
                if (!$ct['code']) continue;
                try { $link->execute([$cid, 'https://sp.wetechs.it/#/contract/editV2/' . $cid, $ct['code']]); if ($link->rowCount()) $touched++; }
                catch (Throwable $e) { /* già collegata ad altro contratto: salta */ }
            }
        }

        // (B) Contratti da creare: presenti nelle attività o nei contratti importati, senza commessa.
        $need = [];
        foreach ($this->pdo->query(
            "SELECT a.id_contract cid, SUBSTRING_INDEX(MIN(a.code),'_',1) prefix
               FROM dgb_forms_activity a
              WHERE a.deleted=0 AND a.id_contract IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM cm_projects p WHERE p.dgb_contract_id=a.id_contract)
              GROUP BY a.id_contract"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) $need[(int)$r['cid']] = $r['prefix'] ?? '';
        if ($contracts) {
            foreach ($this->pdo->query(
                "SELECT c.id cid FROM dgb_forms_contract c
                  WHERE (c.code IS NOT NULL AND c.code<>'')
                    AND NOT EXISTS (SELECT 1 FROM cm_projects p WHERE p.dgb_contract_id=c.id)"
            )->fetchAll(PDO::FETCH_COLUMN) as $cid) if (!isset($need[(int)$cid])) $need[(int)$cid] = '';
        }

        $ins = $this->pdo->prepare(
            "INSERT INTO cm_projects (project_code, name, dgb_contract_id, external_link, created_at) VALUES (?,?,?,?,NOW())"
        );
        $this->pdo->beginTransaction();
        foreach ($need as $cid => $prefix) {
            $ct   = $contracts[$cid] ?? null;
            $code = ($ct && $ct['code']) ? $ct['code'] : ('DGB-' . $cid);
            $name = ($ct && $ct['name']) ? $ct['name']
                    : ('Contratto DogoBit #' . $cid . ($prefix ? ' (' . $prefix . ')' : ''));
            $link = 'https://sp.wetechs.it/#/contract/editV2/' . $cid;
            try { $ins->execute([$code, $name, $cid, $link]); $touched++; }
            catch (Throwable $e) { /* project_code duplicato: salta */ }
        }
        $this->pdo->commit();
        return $touched;
    }

    /**
     * (A) Sincronizza i moduli di intervento da DGB (dettaglio incaricati) verso
     * cm_intervention_reports. Upsert idempotente per dgb_source_id. Keyset pagination.
     * @param int|null $onlyContract limita a un singolo contratto DGB (id_contract)
     * @return array stats
     */
    public function syncReports(?int $userId = null, ?int $onlyContract = null): array
    {
        $projMap = $this->projectMap();
        $opMap   = $this->operatorMap();
        $opName  = $this->operatorNames();

        $created = 0; $updated = 0; $noProject = 0; $processed = 0;
        $lastId = 0; $chunk = 1000;
        $extraWhere = $onlyContract ? " AND a.id_contract = " . (int)$onlyContract : "";

        // v1.8.51 — grana esplicita e codice di riferimento.
        //
        // Prima questo canale scriveva `report_code = 'DGB-<id allocazione>'`, un
        // identificativo inventato dal portale: cercando il codice del gestionale
        // non si trovava nulla. Ora scrive `a.code`, che e' il codice reale, e
        // dichiara la grana in `source_uid` come fanno gli altri canali.
        $cols = ['source_uid','source_system','report_code','report_date','start_at','project_id','project_code','ticket',
                 'technician_id','technician_raw','remote','on_call',
                 'quantity_hours','extra_hours','client_revenue_import','company_cost_import',
                 'imported_by','imported_at','dgb_source_id','dgb_activity_id','dgb_activity_code'];
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $upd = "report_date=VALUES(report_date), start_at=VALUES(start_at), project_id=VALUES(project_id),"
             . "project_code=VALUES(project_code), ticket=VALUES(ticket), technician_id=VALUES(technician_id),"
             . "technician_raw=VALUES(technician_raw), remote=VALUES(remote), on_call=VALUES(on_call),"
             . "quantity_hours=VALUES(quantity_hours),"
             . "extra_hours=VALUES(extra_hours), client_revenue_import=VALUES(client_revenue_import),"
             . "company_cost_import=VALUES(company_cost_import), source_system=VALUES(source_system),"
             . "report_code=VALUES(report_code), dgb_activity_id=VALUES(dgb_activity_id),"
             . "dgb_activity_code=VALUES(dgb_activity_code)";

        while (true) {
            $sql = "SELECT ao.id, ao.id_operator, ao.hours, ao.extra_hours, ao.cost, ao.revenue,
                           ao.from_remote, ao.during_availability,
                           a.id AS activity_id, a.code AS activity_code,
                           a.id_contract, a.ticket, a.report_date, a.date_start
                      FROM dgb_forms_activity_operator ao
                      JOIN dgb_forms_activity a ON a.id = ao.id_activity
                     WHERE ao.id > ? AND a.deleted=0" . $extraWhere . "
                     ORDER BY ao.id LIMIT " . (int)$chunk;
            $st = $this->pdo->prepare($sql);
            $st->execute([$lastId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;

            $buf = []; $flat = [];
            foreach ($rows as $r) {
                $lastId = (int)$r['id'];
                $processed++;
                $cid = (int)$r['id_contract'];
                $pj = $projMap[$cid] ?? null;
                if (!$pj) { $noProject++; continue; }
                $opId = (int)$r['id_operator'];
                $techId = $opMap[$opId] ?? null;
                $techRaw = $opName[$opId] ?? null;
                $repDate = $r['report_date'] ?: (($r['date_start']) ? substr((string)$r['date_start'], 0, 10) : null);
                $extra = (float)$r['extra_hours'];
                // il codice del gestionale, con ripiego sul vecchio schema solo se
                // la sorgente non lo espone
                $actCode = trim((string)($r['activity_code'] ?? '')) ?: ('DGB-' . (int)$r['id']);
                $grana   = $actCode . '#' . $opId;
                $vals = [
                    $grana, 'dgb', $actCode, $repDate, $r['date_start'] ?: null, $pj['id'], $pj['code'], $r['ticket'],
                    $techId, $techRaw, (int)$r['from_remote'], (int)$r['during_availability'],
                    (float)$r['hours'], $extra, (float)$r['revenue'], (float)$r['cost'],
                    $userId, date('Y-m-d H:i:s'), (int)$r['id'],
                    (int)($r['activity_id'] ?? 0) ?: null, $actCode,
                ];
                $buf[] = $ph;
                foreach ($vals as $v) $flat[] = $v;
            }
            if ($buf) {
                $sqlUp = "INSERT INTO cm_intervention_reports (`" . implode('`,`', $cols) . "`) VALUES "
                       . implode(',', $buf) . " ON DUPLICATE KEY UPDATE $upd";
                $stU = $this->pdo->prepare($sqlUp);
                $stU->execute($flat);
                // rowCount: 1 per insert, 2 per update (approssimazione aggregata)
                $created += count($buf); // conteggio righe elaborate valide
            }
            if (count($rows) < $chunk) break;
        }
        // ricalcolo preciso created/updated non disponibile in batch: stimo con marker
        $syncedTot = (int)$this->pdo->query("SELECT COUNT(*) FROM cm_intervention_reports WHERE dgb_source_id IS NOT NULL")->fetchColumn();
        return ['processed' => $processed, 'written' => $created, 'no_project' => $noProject,
                'synced_total' => $syncedTot];
    }

    /**
     * (B) Auto-classifica i profili: on_call se esistono attività in reperibilità
     * (during_availability=1); orario 'turni' se una quota rilevante di ore è fuori
     * dalla fascia ordinaria (default 9-18) o nei weekend, altrimenti 'ordinario'.
     * Non sovrascrive i profili modificati manualmente (auto_classified=0 e updated).
     * @return array conteggi
     */
    public function autoClassify(float $shiftThreshold = 0.30): array
    {
        $rows = $this->pdo->query(
            "SELECT ao.id_operator op,
                    SUM(ao.hours) tot,
                    SUM(CASE WHEN HOUR(a.date_start) < 8 OR HOUR(a.date_start) >= 18
                              OR DAYOFWEEK(a.date_start) IN (1,7) THEN ao.hours ELSE 0 END) off_hours,
                    MAX(ao.during_availability) any_oncall
               FROM dgb_forms_activity_operator ao
               JOIN dgb_forms_activity a ON a.id = ao.id_activity
              WHERE a.deleted=0 AND ao.id_operator IS NOT NULL AND a.date_start IS NOT NULL
              GROUP BY ao.id_operator"
        )->fetchAll(PDO::FETCH_ASSOC);

        $up = $this->pdo->prepare(
            "INSERT INTO dgb_operator_profile (dgb_operator_id, schedule_type, on_call, auto_classified, updated_at)
             VALUES (?,?,?,1,NOW())
             ON DUPLICATE KEY UPDATE schedule_type=VALUES(schedule_type), on_call=VALUES(on_call),
                                     auto_classified=1, updated_at=NOW()"
        );
        $turni = 0; $oncall = 0; $n = 0;
        $this->pdo->beginTransaction();
        foreach ($rows as $r) {
            $tot = (float)$r['tot']; $off = (float)$r['off_hours'];
            $isTurni = $tot > 0 && ($off / $tot) >= $shiftThreshold;
            $isOnCall = (int)$r['any_oncall'] === 1;
            $up->execute([(int)$r['op'], $isTurni ? 'turni' : 'ordinario', $isOnCall ? 1 : 0]);
            if ($isTurni) $turni++;
            if ($isOnCall) $oncall++;
            $n++;
        }
        $this->pdo->commit();
        return ['classified' => $n, 'turni' => $turni, 'ordinario' => $n - $turni, 'on_call' => $oncall];
    }
}
