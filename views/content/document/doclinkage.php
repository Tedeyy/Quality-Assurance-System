<?php
require_once __DIR__ . '/../../../config/database.php';
$db = (new Database())->getConnection();

$linkages = [];
$accreditations = [];
$offices = [];
$categories = [];

try {
    $stmt = $db->query("
        SELECT
            b.bridge_id,
            b.proof_name,
            d.doc_id,
            d.doc_code,
            d.office_of_origin,
            d.category AS doc_category,
            d.confidentiality,
            d.purpose,
            r.requirement_id,
            COALESCE(NULLIF(r.codename, ''), CONCAT('REQ-', r.requirement_id)) AS req_code,
            r.name AS requirement_name,
            c.name AS category_name,
            a.accreditation_id,
            a.code AS accreditation_code,
            a.name AS accreditation_name,
            a.status AS accreditation_status
        FROM document_bridge b
        INNER JOIN documents d ON b.document_id = d.doc_id
        LEFT JOIN accreditation_requirement r ON b.requirement_id = r.requirement_id
        LEFT JOIN accreditation_categories c ON r.category_id = c.category_id
        LEFT JOIN accreditations a ON c.accreditation_id = a.accreditation_id
        ORDER BY a.name ASC, c.name ASC, r.name ASC, b.proof_name ASC
    ");
    $linkages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("doclinkage query failed: " . $e->getMessage());
}

foreach ($linkages as $row) {
    if (!empty($row['accreditation_name'])) $accreditations[$row['accreditation_name']] = true;
    if (!empty($row['office_of_origin'])) $offices[$row['office_of_origin']] = true;
    if (!empty($row['doc_category'])) $categories[$row['doc_category']] = true;
}
ksort($accreditations);
ksort($offices);
ksort($categories);

$linked_documents = array_unique(array_map(static function ($row) {
    return $row['doc_id'];
}, $linkages));

$confidentiality_levels = [
    1 => ['label' => 'Public', 'color' => '#10b981', 'bg' => '#ecfdf5'],
    2 => ['label' => 'Internal', 'color' => '#3b82f6', 'bg' => '#eff6ff'],
    3 => ['label' => 'Restricted', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
    4 => ['label' => 'Confidential', 'color' => '#f97316', 'bg' => '#fff7ed'],
    5 => ['label' => 'Strictly Confidential', 'color' => '#ef4444', 'bg' => '#fef2f2']
];
?>

<style>
    :root {
        --accent-blue: #001C57;
        --accent-gold: #DFB641;
        --border-color: #e2e8f0;
        --text-primary: #1e293b;
        --text-secondary: #64748b;
    }

    body {
        background-color: #f8fafc;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
    }

    .qa-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    }

    .linkage-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .linkage-table-wrap {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        overflow: visible;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
    }

    .linkage-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .linkage-table th {
        padding: 1.2rem;
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        background: #f8fafc;
        border-bottom: 2px solid var(--border-color);
    }

    .linkage-table td {
        padding: 1.2rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: top;
    }

    .linkage-row:last-child td {
        border-bottom: none;
    }

    @media (max-width: 980px) {
        .linkage-table-wrap {
            overflow-x: auto;
        }

        .linkage-table {
            min-width: 980px;
        }
    }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem; padding-bottom: 3rem;">
    <div class="container" style="max-width: 1300px; margin: 0 auto; padding: 0 20px;">

        <div style="display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem; flex-wrap: wrap;">
            <div>
                <h1 style="font-size: 2rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 12px; color: var(--accent-blue);">
                    <div style="background: var(--accent-blue); color: white; padding: 8px; border-radius: 10px; display: flex;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07L11 4.93"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07L13 19.07"/></svg>
                    </div>
                    Accreditation Linkages
                </h1>
                <p style="color: var(--text-secondary); font-size: 0.95rem; font-weight: 500;">View institutional documents currently linked to accreditation proof requirements.</p>
            </div>

            <a href="feed.php?action=document" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; padding: 12px 18px; font-weight: 700; border-radius: 8px; cursor: pointer; text-decoration: none;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                Back to Mapping
            </a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
            <div class="qa-card" style="padding: 1.5rem;">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Linked Proofs</span>
                <div id="stat-visible-linkages" style="font-size: 2.2rem; font-weight: 800; color: var(--accent-blue); margin-top: 5px;"><?= count($linkages) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #10b981; display: flex; align-items: center; gap: 4px; font-weight: 600;">Mapped proof requirements</div>
            </div>

            <div class="qa-card" style="padding: 1.5rem;">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Linked Documents</span>
                <div style="font-size: 2.2rem; font-weight: 800; color: var(--accent-gold); margin-top: 5px;"><?= count($linked_documents) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #64748b; font-weight: 500;">Unique document records</div>
            </div>

            <div class="qa-card" style="padding: 1.5rem;">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Accreditations</span>
                <div style="font-size: 2.2rem; font-weight: 800; color: #f97316; margin-top: 5px;"><?= count($accreditations) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #64748b; font-weight: 500;">With document-linked proofs</div>
            </div>
        </div>

        <div style="background: white; padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; position: relative; min-width: 250px;">
                <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="linkageSearch" oninput="filterLinkages()" placeholder="Search by document, proof, requirement, accreditation or office..." style="width: 100%; padding: 0.7rem 0.7rem 0.7rem 2.5rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;">
            </div>

            <div style="width: 230px;">
                <select id="accreditationFilter" onchange="filterLinkages()" style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white; cursor: pointer;">
                    <option value="all">All Accreditations</option>
                    <?php foreach (array_keys($accreditations) as $acc): ?>
                        <option value="<?= htmlspecialchars($acc) ?>"><?= htmlspecialchars($acc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="width: 200px;">
                <select id="officeFilter" onchange="filterLinkages()" style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white; cursor: pointer;">
                    <option value="all">All Offices</option>
                    <?php foreach (array_keys($offices) as $office): ?>
                        <option value="<?= htmlspecialchars($office) ?>"><?= htmlspecialchars($office) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="linkage-table-wrap">
            <table class="linkage-table">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Origin / Category</th>
                        <th>Proof of Compliance</th>
                        <th>Requirement</th>
                        <th>Accreditation</th>
                        <th style="width: 130px; text-align: center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($linkages)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.8rem;"><path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07L11 4.93"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07L13 19.07"/></svg>
                                <p style="margin: 0; font-weight: 600; font-size: 0.95rem;">No accreditation proof linkages found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($linkages as $row): ?>
                            <?php
                                $cLevel = (int)($row['confidentiality'] ?? 0);
                                $cData = $confidentiality_levels[$cLevel] ?? ['label' => 'Unknown', 'color' => '#64748b', 'bg' => '#f1f5f9'];
                            ?>
                            <tr class="linkage-row"
                                data-search="<?= htmlspecialchars(strtolower(($row['doc_code'] ?? '') . ' ' . ($row['office_of_origin'] ?? '') . ' ' . ($row['doc_category'] ?? '') . ' ' . ($row['proof_name'] ?? '') . ' ' . ($row['req_code'] ?? '') . ' ' . ($row['requirement_name'] ?? '') . ' ' . ($row['category_name'] ?? '') . ' ' . ($row['accreditation_code'] ?? '') . ' ' . ($row['accreditation_name'] ?? ''))) ?>"
                                data-accreditation="<?= htmlspecialchars($row['accreditation_name'] ?? '') ?>"
                                data-office="<?= htmlspecialchars($row['office_of_origin'] ?? '') ?>">
                                <td>
                                    <div style="font-weight: 800; color: var(--accent-blue); font-size: 0.95rem;"><?= htmlspecialchars($row['doc_code'] ?? 'Untitled Document') ?></div>
                                    <div style="margin-top: 6px;">
                                        <span class="linkage-badge" style="color: <?= $cData['color'] ?>; background: <?= $cData['bg'] ?>;"><?= htmlspecialchars($cData['label']) ?></span>
                                    </div>
                                </td>

                                <td>
                                    <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem; margin-bottom: 4px;"><?= htmlspecialchars($row['office_of_origin'] ?? 'Unassigned Office') ?></div>
                                    <span style="font-size: 0.75rem; background: rgba(0, 28, 87, 0.05); color: var(--accent-blue); padding: 2px 6px; border-radius: 4px; font-weight: 700; text-transform: uppercase;"><?= htmlspecialchars($row['doc_category'] ?? 'Uncategorized') ?></span>
                                </td>

                                <td>
                                    <div style="font-weight: 800; color: #0f172a; font-size: 0.92rem; line-height: 1.4;"><?= htmlspecialchars($row['proof_name'] ?? 'Unnamed proof') ?></div>
                                </td>

                                <td>
                                    <div style="font-size: 0.78rem; color: var(--accent-blue); font-weight: 800; margin-bottom: 4px;"><?= htmlspecialchars($row['req_code'] ?? 'REQ') ?></div>
                                    <div style="font-size: 0.9rem; color: #334155; font-weight: 650; line-height: 1.45;"><?= htmlspecialchars($row['requirement_name'] ?? 'No requirement title') ?></div>
                                    <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 4px;"><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></div>
                                </td>

                                <td>
                                    <div style="font-weight: 800; color: #0f172a; font-size: 0.9rem; line-height: 1.4;"><?= htmlspecialchars($row['accreditation_name'] ?? 'No accreditation') ?></div>
                                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 4px;"><?= htmlspecialchars($row['accreditation_code'] ?? '') ?></div>
                                </td>

                                <td style="text-align: center;">
                                    <span class="linkage-badge" style="color: #10b981; background: #ecfdf5;">Linked</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noLinkageResults" style="display: none;">
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <p style="margin: 0; font-weight: 600; font-size: 0.95rem;">No linkages match your filters.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; padding: 0 0.5rem; color: var(--text-secondary); font-size: 0.85rem;">
            <div id="showing-count-container">Showing <b id="showing-count"><?= count($linkages) ?></b> linked proofs</div>
        </div>
    </div>
</main>

<script>
    function filterLinkages() {
        const term = (document.getElementById('linkageSearch')?.value || '').toLowerCase();
        const accreditation = document.getElementById('accreditationFilter')?.value || 'all';
        const office = document.getElementById('officeFilter')?.value || 'all';
        const rows = document.querySelectorAll('.linkage-row');
        let visible = 0;

        rows.forEach(row => {
            const matchesSearch = !term || row.dataset.search.includes(term);
            const matchesAccreditation = accreditation === 'all' || row.dataset.accreditation === accreditation;
            const matchesOffice = office === 'all' || row.dataset.office === office;
            const shouldShow = matchesSearch && matchesAccreditation && matchesOffice;

            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) visible++;
        });

        const emptyRow = document.getElementById('noLinkageResults');
        if (emptyRow) emptyRow.style.display = rows.length > 0 && visible === 0 ? '' : 'none';

        const count = document.getElementById('showing-count');
        if (count) count.textContent = visible;

        const stat = document.getElementById('stat-visible-linkages');
        if (stat) stat.textContent = visible;
    }
</script>
