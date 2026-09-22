<?php
/**
 * certV 5.7.0 — app/TechnologyMapper.php
 *
 * Logica di associazione cross-brand per l'entità Tecnologia.
 *
 * Ogni Tecnologia (Networking, Security, AI, ecc.) è entità trasversale,
 * collegata in N:M a:
 *   - Brand (vendor stack)
 *   - Catalogo certificazioni
 *   - Certificati posseduti dai dipendenti
 *   - Skill matrix (competenze risorse)
 *
 * Tutti i metodi sono puri rispetto al PDO (nessuna sessione/log diretto).
 * Auditing automatico via entity_change_log se `EntityChangeLog` è disponibile.
 */

final class TechnologyMapper
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ════════════════════════════════════════════════════════════════════
    // CRUD Tecnologie
    // ════════════════════════════════════════════════════════════════════

    /**
     * Cerca o crea una Tecnologia per nome (idempotente).
     * Restituisce ['id' => N, 'created' => bool].
     */
    public function findOrCreate(string $name, array $extra = []): array
    {
        $name = trim($name);
        if ($name === '') throw new InvalidArgumentException("Nome tecnologia vuoto.");

        $s = $this->pdo->prepare("SELECT id FROM technologies WHERE name = ? LIMIT 1");
        $s->execute([$name]);
        $existing = $s->fetchColumn();
        if ($existing !== false) {
            return ['id' => (int)$existing, 'created' => false];
        }

        $slug = $extra['slug'] ?? self::slugify($name);
        $this->pdo->prepare(
            "INSERT INTO technologies (name, description, category_id, slug, icon, color, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        )->execute([
            $name,
            $extra['description'] ?? null,
            $extra['category_id'] ?? null,
            $slug,
            $extra['icon']        ?? null,
            $extra['color']       ?? null,
        ]);
        $newId = (int)$this->pdo->lastInsertId();
        return ['id' => $newId, 'created' => true];
    }

    public static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
        return trim((string)$s, '-');
    }

    // ════════════════════════════════════════════════════════════════════
    // N:M  Tecnologia ↔ Brand
    // ════════════════════════════════════════════════════════════════════

    public function attachBrand(int $techId, int $brandId, bool $isPrimary = false, ?int $userId = null): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO tech_brands (technology_id, brand_id, is_primary, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary)"
        );
        $stmt->execute([$techId, $brandId, $isPrimary ? 1 : 0, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function detachBrand(int $techId, int $brandId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM tech_brands WHERE technology_id = ? AND brand_id = ?");
        $stmt->execute([$techId, $brandId]);
        return $stmt->rowCount() > 0;
    }

    /** Brand associati a una Tecnologia. */
    public function getBrands(int $techId): array
    {
        $s = $this->pdo->prepare(
            "SELECT b.id, b.name, b.partnership_level, tb.is_primary, tb.notes,
                    (SELECT COUNT(*) FROM certifications c WHERE c.brand_id = b.id AND c.is_active = 1) AS cert_count
               FROM tech_brands tb
               JOIN brands b ON b.id = tb.brand_id
              WHERE tb.technology_id = ?
              ORDER BY tb.is_primary DESC, b.name"
        );
        $s->execute([$techId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tecnologie coperte da un Brand. */
    public function getTechByBrand(int $brandId): array
    {
        $s = $this->pdo->prepare(
            "SELECT t.id, t.name, t.slug, t.icon, t.color, tb.is_primary,
                    tc.name AS category_name
               FROM tech_brands tb
               JOIN technologies t  ON t.id = tb.technology_id
               LEFT JOIN tech_categories tc ON tc.id = t.category_id
              WHERE tb.brand_id = ? AND t.is_active = 1
              ORDER BY tb.is_primary DESC, t.name"
        );
        $s->execute([$brandId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sync bulk: imposta esattamente questi brand per una Tecnologia. */
    public function syncBrands(int $techId, array $brandIds, ?int $userId = null): array
    {
        $brandIds = array_values(array_unique(array_map('intval', array_filter($brandIds))));

        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare("SELECT brand_id FROM tech_brands WHERE technology_id = ?");
            $current->execute([$techId]);
            $cur = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN));

            $toAdd    = array_diff($brandIds, $cur);
            $toRemove = array_diff($cur, $brandIds);

            $ins = $this->pdo->prepare(
                "INSERT IGNORE INTO tech_brands (technology_id, brand_id, created_by) VALUES (?, ?, ?)"
            );
            foreach ($toAdd as $bid) $ins->execute([$techId, $bid, $userId]);

            $del = $this->pdo->prepare("DELETE FROM tech_brands WHERE technology_id = ? AND brand_id = ?");
            foreach ($toRemove as $bid) $del->execute([$techId, $bid]);

            $this->pdo->commit();
            return ['added' => count($toAdd), 'removed' => count($toRemove)];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // N:M  Tecnologia ↔ Catalogo certificazioni
    // ════════════════════════════════════════════════════════════════════

    public function attachCertification(int $techId, int $certId, string $relevance = 'primary', ?int $userId = null): bool
    {
        if (!in_array($relevance, ['primary','secondary','related'], true)) {
            throw new InvalidArgumentException("Relevance non valida.");
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO tech_certifications (technology_id, certification_id, relevance, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE relevance = VALUES(relevance)"
        );
        $stmt->execute([$techId, $certId, $relevance, $userId]);

        // Auto-propagazione ai certificati posseduti (tech_user_certifications con auto_inferred=1)
        $this->propagateToUserCertifications($techId, $certId);
        return $stmt->rowCount() > 0;
    }

    public function detachCertification(int $techId, int $certId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM tech_certifications WHERE technology_id = ? AND certification_id = ?"
        );
        $stmt->execute([$techId, $certId]);

        // Cleanup auto-propagati
        $this->pdo->prepare(
            "DELETE tuc FROM tech_user_certifications tuc
              JOIN user_certifications uc ON uc.id = tuc.user_certification_id
             WHERE tuc.technology_id = ?
               AND uc.certification_id = ?
               AND tuc.auto_inferred = 1"
        )->execute([$techId, $certId]);
        return $stmt->rowCount() > 0;
    }

    /** Certificazioni associate a una Tecnologia. */
    public function getCertifications(int $techId, ?string $relevance = null): array
    {
        $where = "tcrt.technology_id = ?";
        $params = [$techId];
        if ($relevance !== null) {
            $where .= " AND tcrt.relevance = ?";
            $params[] = $relevance;
        }
        $s = $this->pdo->prepare(
            "SELECT c.id, c.code, c.name, c.level, c.category, c.is_active,
                    b.name AS brand_name, b.id AS brand_id,
                    tcrt.relevance
               FROM tech_certifications tcrt
               JOIN certifications c ON c.id = tcrt.certification_id
               LEFT JOIN brands b   ON b.id = c.brand_id
              WHERE $where
              ORDER BY FIELD(tcrt.relevance, 'primary','secondary','related'), b.name, c.name"
        );
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function syncCertifications(int $techId, array $assoc, ?int $userId = null): array
    {
        // $assoc: array di [cert_id => relevance]
        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare(
                "SELECT certification_id, relevance FROM tech_certifications WHERE technology_id = ?"
            );
            $current->execute([$techId]);
            $cur = [];
            foreach ($current->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cur[(int)$r['certification_id']] = $r['relevance'];
            }

            $added = $updated = $removed = 0;
            foreach ($assoc as $certId => $relevance) {
                $cid = (int)$certId;
                if (!isset($cur[$cid])) {
                    $this->attachCertification($techId, $cid, $relevance, $userId);
                    $added++;
                } elseif ($cur[$cid] !== $relevance) {
                    $this->pdo->prepare(
                        "UPDATE tech_certifications SET relevance = ? WHERE technology_id = ? AND certification_id = ?"
                    )->execute([$relevance, $techId, $cid]);
                    $updated++;
                }
            }
            foreach ($cur as $cid => $rel) {
                if (!isset($assoc[$cid])) {
                    $this->detachCertification($techId, $cid);
                    $removed++;
                }
            }
            $this->pdo->commit();
            return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // N:M  Tecnologia ↔ Certificati posseduti (auto-inferiti + manuali)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Propaga la relazione tech-cert sul catalogo a tutti i certificati
     * posseduti dai dipendenti per quella certificazione.
     * Eseguito in auto al momento dell'attach.
     */
    public function propagateToUserCertifications(int $techId, int $certId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO tech_user_certifications (technology_id, user_certification_id, auto_inferred)
             SELECT ?, uc.id, 1
               FROM user_certifications uc
              WHERE uc.certification_id = ?"
        );
        $stmt->execute([$techId, $certId]);
        return $stmt->rowCount();
    }

    /** Tag manuale di una tecnologia su un certificato posseduto. */
    public function tagUserCertification(int $techId, int $userCertId, ?int $userId = null): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO tech_user_certifications (technology_id, user_certification_id, auto_inferred, created_by)
             VALUES (?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE auto_inferred = 0"
        );
        $stmt->execute([$techId, $userCertId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function untagUserCertification(int $techId, int $userCertId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM tech_user_certifications WHERE technology_id = ? AND user_certification_id = ?"
        );
        $stmt->execute([$techId, $userCertId]);
        return $stmt->rowCount() > 0;
    }

    // ════════════════════════════════════════════════════════════════════
    // N:M  Tecnologia ↔ Skill matrix
    // ════════════════════════════════════════════════════════════════════

    public function tagSkill(int $techId, int $skillId, ?int $userId = null): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO tech_employee_skills (technology_id, employee_skill_id, created_by)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$techId, $skillId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function untagSkill(int $techId, int $skillId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM tech_employee_skills WHERE technology_id = ? AND employee_skill_id = ?"
        );
        $stmt->execute([$techId, $skillId]);
        return $stmt->rowCount() > 0;
    }

    // ════════════════════════════════════════════════════════════════════
    // QUERIES AGGREGATE — viste analitiche
    // ════════════════════════════════════════════════════════════════════

    /**
     * Vista riepilogativa per ogni tecnologia: # brand, # cert, # certificati posseduti, # risorse skilled.
     */
    public function getOverview(?int $categoryId = null, bool $onlyActive = true): array
    {
        $where = "1=1";
        $params = [];
        if ($onlyActive)               { $where .= " AND t.is_active = 1"; }
        if ($categoryId !== null)      { $where .= " AND t.category_id = ?"; $params[] = $categoryId; }

        $s = $this->pdo->prepare(
            "SELECT t.id, t.name, t.description, t.category_id, t.slug, t.icon, t.color, t.is_active,
                    tc.name AS category_name, tc.color AS category_color,
                    (SELECT COUNT(*) FROM tech_brands tb WHERE tb.technology_id = t.id) AS brand_count,
                    (SELECT COUNT(*) FROM tech_certifications tcrt WHERE tcrt.technology_id = t.id) AS cert_count,
                    (SELECT COUNT(*) FROM certifications cf WHERE cf.technology_id = t.id) AS legacy_cert_count,
                    (SELECT COUNT(*) FROM tech_user_certifications tuc
                       JOIN user_certifications uc ON uc.id = tuc.user_certification_id
                      WHERE tuc.technology_id = t.id AND uc.status = 'active') AS held_count,
                    (SELECT COUNT(DISTINCT uc.employee_id) FROM tech_user_certifications tuc
                       JOIN user_certifications uc ON uc.id = tuc.user_certification_id
                      WHERE tuc.technology_id = t.id AND uc.status = 'active') AS skilled_employees,
                    (SELECT COUNT(*) FROM tech_employee_skills tes WHERE tes.technology_id = t.id) AS skill_count
               FROM technologies t
               LEFT JOIN tech_categories tc ON tc.id = t.category_id
              WHERE $where
              ORDER BY tc.display_order, t.name"
        );
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Per una Tecnologia, restituisce la matrice completa: dipendenti che la "coprono"
     * tramite certificati o skill, con dettaglio.
     */
    public function getCoverageMatrix(int $techId): array
    {
        $s = $this->pdo->prepare(
            "SELECT e.id AS employee_id,
                    CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS employee_name,
                    e.job_title,
                    GROUP_CONCAT(DISTINCT CONCAT(b.name, ' / ', c.code)
                                 ORDER BY b.name SEPARATOR ', ') AS certifications,
                    COUNT(DISTINCT uc.id) AS cert_count,
                    MAX(es.level) AS top_skill_level
               FROM employees e
               LEFT JOIN user_certifications uc ON uc.employee_id = e.id AND uc.status = 'active'
               LEFT JOIN tech_user_certifications tuc ON tuc.user_certification_id = uc.id AND tuc.technology_id = ?
               LEFT JOIN certifications c ON c.id = uc.certification_id
               LEFT JOIN brands b ON b.id = c.brand_id
               LEFT JOIN employee_skills es ON es.employee_id = e.id
               LEFT JOIN tech_employee_skills tes ON tes.employee_skill_id = es.id AND tes.technology_id = ?
              WHERE tuc.technology_id = ? OR tes.technology_id = ?
              GROUP BY e.id
              ORDER BY cert_count DESC, employee_name"
        );
        $s->execute([$techId, $techId, $techId, $techId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Skill gap: quali tecnologie hanno certificazioni in catalogo ma 0 dipendenti che le coprono. */
    public function getSkillGaps(): array
    {
        $s = $this->pdo->query(
            "SELECT t.id, t.name, t.slug,
                    COUNT(DISTINCT tcrt.certification_id) AS available_certs,
                    COUNT(DISTINCT tuc.user_certification_id) AS held_count
               FROM technologies t
               LEFT JOIN tech_certifications tcrt ON tcrt.technology_id = t.id
               LEFT JOIN tech_user_certifications tuc ON tuc.technology_id = t.id
              WHERE t.is_active = 1
              GROUP BY t.id
             HAVING available_certs > 0 AND held_count = 0
              ORDER BY available_certs DESC"
        );
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
