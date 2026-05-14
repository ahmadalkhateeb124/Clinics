<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('consultations.view');

$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$s = db()->prepare("
    SELECT c.*, p.code AS patient_code, p.first_name, p.last_name, p.phone, p.dob,
           u.name AS doctor_name, sv.name_ar AS service_name
    FROM consultations c
    JOIN patients p ON p.id = c.patient_id
    LEFT JOIN users u  ON u.id = c.doctor_id
    LEFT JOIN services sv ON sv.id = c.service_id
    WHERE c.id = ? AND c.deleted_at IS NULL
");
$s->execute([$id]);
$cs = $s->fetch();
if (!$cs) { flash('error', __('not_found')); redirect(BP_URL . 'admin/consultations.php'); }

$PageTitle = __('consultation_no') . ' ' . $id;

// ─── Convert to treatment plan ───────────────────────────────────────
if ($action === 'convert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_can('packages.create');

    $usePkgId = (int)($_POST['use_package_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $goals    = trim($_POST['goals'] ?? '');
    $sessions = max(1, (int)($_POST['total_sessions'] ?? 1));
    $startDate = trim($_POST['start_date'] ?? '') ?: null;
    $endDate   = trim($_POST['end_date'] ?? '')   ?: null;
    $services  = $_POST['services'] ?? [];   // [service_id => sessions_count]
    $assign    = !empty($_POST['assign_now']) || $usePkgId > 0;
    $price     = (float)($_POST['price'] ?? 0);
    $validity  = max(1, (int)($_POST['validity_days'] ?? 90));

    // If user picked an existing package, snap plan fields from that package
    if ($usePkgId > 0) {
        $existing = db()->prepare("SELECT * FROM packages WHERE id=? AND deleted_at IS NULL");
        $existing->execute([$usePkgId]);
        $existing = $existing->fetch();
        if (!$existing) { flash('error', __('not_found')); redirect(BP_URL . 'admin/consultation-view.php?id=' . $id); }
        $title    = $title !== '' ? $title : $existing['name_ar'];
        $sessions = (int)$existing['total_sessions'];
        $price    = (float)$existing['price'];
        $validity = (int)$existing['validity_days'];
    }

    if ($title === '') { flash('error', __('err_plan_title_required')); redirect(BP_URL . 'admin/consultation-view.php?id=' . $id); }

    // 1) Create the treatment plan
    db()->prepare("INSERT INTO treatment_plans
        (patient_id,consultation_id,title,goals,total_sessions,start_date,end_date,status,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,'active',?,NOW(),NOW())
    ")->execute([
        $cs['patient_id'], $id, $title, $goals, $sessions, $startDate, $endDate, $_SESSION['user_id']
    ]);
    $planId = (int)db()->lastInsertId();

    // Wire services to the plan (skip when using an existing package — plan inherits from package)
    if ($usePkgId === 0) {
        $insSv = db()->prepare("INSERT IGNORE INTO treatment_plan_services (plan_id, service_id, sessions_count) VALUES (?,?,?)");
        foreach ($services as $sid => $count) {
            $sid = (int)$sid; $cnt = max(0, (int)$count);
            if ($sid > 0 && $cnt > 0) $insSv->execute([$planId, $sid, $cnt]);
        }
    } else {
        // Copy package_services into treatment_plan_services
        $copyPS = db()->prepare("INSERT IGNORE INTO treatment_plan_services (plan_id, service_id, sessions_count)
                                 SELECT ?, service_id, sessions_included FROM package_services WHERE package_id=?");
        $copyPS->execute([$planId, $usePkgId]);
    }

    // 2) Assign to patient if requested
    if ($assign) {
        if ($usePkgId > 0) {
            $pkgId = $usePkgId;
        } else {
            // Create a NEW package from the plan data
            $slug = 'plan-' . $planId . '-' . substr(md5($title), 0, 6);
            db()->prepare("INSERT INTO packages
                (name_ar,name_en,slug,description_ar,total_sessions,price,validity_days,is_active,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,1,?,NOW(),NOW())
            ")->execute([
                $title, $title, $slug, $goals, $sessions, $price, $validity, $_SESSION['user_id']
            ]);
            $pkgId = (int)db()->lastInsertId();

            // Wire services to the new package
            $insPS = db()->prepare("INSERT IGNORE INTO package_services (package_id, service_id, sessions_included) VALUES (?,?,?)");
            foreach ($services as $sid => $count) {
                $sid = (int)$sid; $cnt = max(0, (int)$count);
                if ($sid > 0 && $cnt > 0) $insPS->execute([$pkgId, $sid, $cnt]);
            }
        }

        // Assign package to patient
        $expiry = (new DateTime())->modify('+'.$validity.' days')->format('Y-m-d');
        db()->prepare("INSERT INTO patient_packages
            (patient_id,package_id,purchase_date,expiry_date,total_sessions,used_sessions,price,paid_amount,status,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,0,?,0,'active',?,NOW(),NOW())
        ")->execute([
            $cs['patient_id'], $pkgId, date('Y-m-d'), $expiry, $sessions, $price, $_SESSION['user_id']
        ]);
        $ppId = (int)db()->lastInsertId();

        db()->prepare("UPDATE treatment_plans SET package_id=?, patient_package_id=? WHERE id=?")
            ->execute([$pkgId, $ppId, $planId]);

        log_activity('converted','consultations',
            "Converted consultation #$id → plan #$planId → package #$pkgId (assigned)",
            'consultation', $id,
            ['plan_id'=>$planId,'package_id'=>$pkgId,'patient_package_id'=>$ppId,'source'=>($usePkgId>0?'existing':'new')]);
    } else {
        log_activity('converted','consultations',
            "Created treatment plan #$planId from consultation #$id (not yet assigned)",
            'consultation', $id, ['plan_id'=>$planId]);
    }

    flash('success', $assign ? __('plan_created_assigned') : __('plan_created_draft'));
    redirect(BP_URL . 'admin/consultation-view.php?id=' . $id);
}

// Files attached to this consultation
$files = db()->prepare("SELECT * FROM patient_files WHERE consultation_id=? AND deleted_at IS NULL ORDER BY id DESC");
$files->execute([$id]); $files = $files->fetchAll();

// Linked treatment plans
$plans = db()->prepare("
    SELECT tp.*, COUNT(tps.id) AS service_count
    FROM treatment_plans tp
    LEFT JOIN treatment_plan_services tps ON tps.plan_id = tp.id
    WHERE tp.consultation_id = ? AND tp.deleted_at IS NULL
    GROUP BY tp.id ORDER BY tp.id DESC
");
$plans->execute([$id]); $plans = $plans->fetchAll();

$age = '';
if ($cs['dob']) $age = (new DateTime($cs['dob']))->diff(new DateTime())->y;

$services = db()->query("
    SELECT s.id,s.name_ar,s.duration_minutes,s.price,c.name_ar AS cat
    FROM services s LEFT JOIN service_categories c ON c.id = s.category_id
    WHERE s.deleted_at IS NULL AND s.is_active=1 AND s.is_consultation=0
    ORDER BY c.sort_order, s.sort_order
")->fetchAll();

// Available packages for "pick existing" mode
$availablePackages = db()->query("
    SELECT id, name_ar, total_sessions, price, validity_days, description_ar
    FROM packages
    WHERE deleted_at IS NULL AND is_active = 1
    ORDER BY name_ar
")->fetchAll();

// Map package_id → [{service_name, sessions_included}]
$pkgDetails = [];
foreach ($availablePackages as $ap) {
    $pkgDetails[(int)$ap['id']] = [
        'name'      => $ap['name_ar'],
        'sessions'  => (int)$ap['total_sessions'],
        'price'     => (float)$ap['price'],
        'validity'  => (int)$ap['validity_days'],
        'desc'      => $ap['description_ar'] ?? '',
        'services'  => [],
    ];
}
$psRows = db()->query("
    SELECT ps.package_id, ps.sessions_included, s.name_ar, s.duration_minutes, s.price
    FROM package_services ps
    JOIN services s ON s.id = ps.service_id
    WHERE s.deleted_at IS NULL
")->fetchAll();
foreach ($psRows as $r) {
    $pid = (int)$r['package_id'];
    if (!isset($pkgDetails[$pid])) continue;
    $pkgDetails[$pid]['services'][] = [
        'name'     => $r['name_ar'],
        'duration' => (int)$r['duration_minutes'],
        'price'    => (float)$r['price'],
        'count'    => (int)$r['sessions_included'],
    ];
}

$statusBg   = ['scheduled'=>'#0ea5e9','in_progress'=>'#f59e0b','completed'=>'#10b981','cancelled'=>'#ef4444'][$cs['status']] ?? '#64748b';
$statusIcon = ['scheduled'=>'fa-clock','in_progress'=>'fa-hourglass-half','completed'=>'fa-check-double','cancelled'=>'fa-ban'][$cs['status']] ?? 'fa-stethoscope';
$rtl = (($_SESSION['admin_lang'] ?? 'ar') === 'ar');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- ── HERO BANNER ─────────────────────────────────────────── -->
    <div class="appt-hero" style="background:linear-gradient(135deg, <?= $statusBg ?> 0%, <?= $statusBg ?>cc 100%)">
        <div class="appt-hero-left">
            <div class="appt-hero-icon"><i class="fa-solid <?= $statusIcon ?>"></i></div>
            <div>
                <div class="appt-hero-id"><?= __('consultation_no') ?> #<?= (int)$cs['id'] ?></div>
                <h3 class="appt-hero-title m-0"><?= __('st_'.$cs['status']) ?: $cs['status'] ?></h3>
                <div class="appt-hero-meta">
                    <span><i class="fa-regular fa-clock me-1"></i><?= format_date($cs['consultation_date'],'Y-m-d H:i') ?></span>
                    · <span><i class="fa-solid fa-stethoscope me-1"></i><?= __('mode_'.$cs['mode']) ?: $cs['mode'] ?></span>
                </div>
            </div>
        </div>
        <div class="appt-hero-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/consultations.php">
                <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?> me-1"></i><?= __('back_to_list') ?>
            </a>
            <?php if (can('consultations.edit')): ?>
                <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/consultations.php?action=edit&id=<?= $id ?>" data-modal>
                    <i class="fa-solid fa-pen me-1"></i><?= __('edit') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── MAIN GRID ────────────────────────────────────────────── -->
    <div class="row g-3 mt-1">
        <!-- LEFT: Clinical notes + Treatment plans -->
        <div class="col-lg-8">
            <!-- Clinical notes -->
            <div class="info-card mb-3">
                <div class="info-card-head"><i class="fa-solid fa-notes-medical"></i><?= __('clinical_notes') ?></div>
                <div class="info-card-grid">
                    <div><span class="info-label"><?= __('chief_complaint') ?></span><span class="info-value"><?= nl2br(e($cs['complaint'] ?: '—')) ?></span></div>
                    <div><span class="info-label"><?= __('examination') ?></span><span class="info-value"><?= nl2br(e($cs['examination'] ?: '—')) ?></span></div>
                    <div><span class="info-label"><?= __('diagnosis') ?></span><span class="info-value"><?= nl2br(e($cs['diagnosis'] ?: '—')) ?></span></div>
                    <div><span class="info-label"><?= __('recommendations') ?></span><span class="info-value"><?= nl2br(e($cs['recommendations'] ?: '—')) ?></span></div>
                </div>
                <?php if ($cs['prescription']): ?>
                    <div class="info-card-section">
                        <span class="info-label"><i class="fa-solid fa-prescription me-1"></i><?= __('prescription') ?></span>
                        <div class="info-value mt-1"><?= nl2br(e($cs['prescription'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Treatment plans -->
            <div class="info-card mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="info-card-head m-0 border-0 p-0"><i class="fa-solid fa-list-check"></i><?= __('treatment_plans') ?> <span class="text-muted">(<?= count($plans) ?>)</span></div>
                    <?php if (can('packages.create')): ?>
                        <button class="btn btn-sm btn-teal" data-bs-toggle="collapse" data-bs-target="#planForm">
                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i><?= __('convert') ?> → <?= __('plan') ?>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!$plans): ?>
                    <div class="empty-state py-3"><i class="fa-regular fa-clipboard"></i><div><?= __('no_treatment_plan') ?></div></div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($plans as $tp): ?>
                            <div class="pkg-card">
                                <div class="pkg-card-head">
                                    <div>
                                        <h6 class="pkg-card-title"><i class="fa-solid fa-clipboard-list"></i><?= e($tp['title']) ?></h6>
                                        <div class="pkg-card-dates">
                                            <?= (int)$tp['total_sessions'] ?> <?= __('sessions') ?> · <?= (int)$tp['service_count'] ?> <?= __('services') ?>
                                            <?php if ($tp['start_date']): ?> · <?= e($tp['start_date']) ?> → <?= e($tp['end_date'] ?: '—') ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        <span class="badge bg-<?= $tp['status']==='active'?'success':'secondary' ?>"><?= __('st_'.$tp['status']) ?: $tp['status'] ?></span>
                                        <?php if ($tp['package_id']): ?>
                                            <span class="badge bg-info"><?= __('linked_package') ?> #<?= (int)$tp['package_id'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($tp['patient_package_id']): ?>
                                            <span class="badge" style="background:var(--nt-teal);color:#fff"><?= __('assigned') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($tp['goals']): ?>
                                    <div class="small text-muted mt-2"><?= nl2br(e($tp['goals'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (can('packages.create')): ?>
                    <div class="collapse mt-3" id="planForm">
                        <form method="post" action="?id=<?= $id ?>&action=convert" class="border rounded-3 p-3 bg-light" id="planFormEl">
                            <?= csrf_field() ?>

                            <!-- Pick an existing package OR build a new one -->
                            <?php if ($availablePackages): ?>
                                <div class="mb-3">
                                    <label class="form-label small"><i class="fa-solid fa-box-open me-1 text-teal"></i><?= __('use_existing_package') ?></label>
                                    <select name="use_package_id" id="planUsePkg" class="form-select form-select-sm">
                                        <option value=""><?= __('build_new_package') ?> —</option>
                                        <?php foreach ($availablePackages as $ap): ?>
                                            <option value="<?= (int)$ap['id'] ?>">
                                                <?= e($ap['name_ar']) ?> · <?= (int)$ap['total_sessions'] ?> <?= __('sessions') ?> · <?= format_money($ap['price']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted"><?= __('use_existing_package_hint') ?></small>
                                </div>

                                <!-- Live preview of the selected package -->
                                <div id="pkgPreview" class="pkg-preview d-none mb-3"></div>
                            <?php endif; ?>

                            <div class="row g-2">
                                <div class="col-md-8">
                                    <label class="form-label small"><?= __('plan_title') ?> *</label>
                                    <input name="title" required class="form-control form-control-sm"
                                           placeholder="<?= e(__('plan_title')) ?> — <?= e($cs['first_name'].' '.$cs['last_name']) ?>">
                                </div>
                                <div class="col-md-2 plan-new-only">
                                    <label class="form-label small"><?= __('sessions') ?></label>
                                    <input type="number" name="total_sessions" min="1" class="form-control form-control-sm"
                                           value="<?= max(1,(int)$cs['prescribed_sessions']) ?>">
                                </div>
                                <div class="col-md-2 plan-new-only">
                                    <label class="form-label small"><?= __('validity_days') ?></label>
                                    <input type="number" name="validity_days" min="1" class="form-control form-control-sm" value="90">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small"><?= __('start_date') ?></label>
                                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small"><?= __('end_date') ?></label>
                                    <input type="date" name="end_date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3 plan-new-only">
                                    <label class="form-label small"><?= __('total') ?> <?= __('price') ?> (<?= APP_CURRENCY ?>)</label>
                                    <input type="number" step="0.01" min="0" name="price" class="form-control form-control-sm" value="0">
                                </div>
                                <div class="col-md-3 plan-new-only d-flex align-items-end">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="assign_now" value="1" checked>
                                        <span class="form-check-label small"><?= __('create_pkg_assign') ?></span>
                                    </label>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label small"><?= __('goals_notes') ?></label>
                                    <textarea name="goals" rows="2" class="form-control form-control-sm"><?= e($cs['recommendations']) ?></textarea>
                                </div>

                                <div class="col-md-12 plan-new-only">
                                    <label class="form-label small"><?= __('services_included_sessions') ?></label>
                                    <div class="table-responsive bg-white rounded">
                                        <table class="table table-sm mb-0">
                                            <thead><tr><th><?= __('services') ?></th><th><?= __('category') ?></th><th><?= __('duration') ?></th><th><?= __('price') ?></th><th style="width:120px"><?= __('sessions') ?></th></tr></thead>
                                            <tbody>
                                                <?php foreach ($services as $sv): ?>
                                                    <tr>
                                                        <td><?= e($sv['name_ar']) ?></td>
                                                        <td class="text-muted small"><?= e($sv['cat'] ?? '—') ?></td>
                                                        <td><?= (int)$sv['duration_minutes'] ?>m</td>
                                                        <td><?= format_money($sv['price']) ?></td>
                                                        <td><input type="number" min="0" class="form-control form-control-sm"
                                                                   name="services[<?= (int)$sv['id'] ?>]" value="0"></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <button class="btn btn-sm btn-teal"><i class="fa-solid fa-check me-1"></i><?= __('create_plan') ?></button>
                                </div>
                            </div>
                        </form>
                        <script>
                        (function(){
                            const sel = document.getElementById('planUsePkg');
                            if (!sel) return;
                            const form    = document.getElementById('planFormEl');
                            const newOnly = form.querySelectorAll('.plan-new-only');
                            const preview = document.getElementById('pkgPreview');
                            const PKG = <?= json_encode($pkgDetails, JSON_UNESCAPED_UNICODE) ?>;
                            const T = {
                                sessions:    <?= json_encode(__('sessions')) ?>,
                                price:       <?= json_encode(__('price')) ?>,
                                validity:    <?= json_encode(__('validity_days')) ?>,
                                services:    <?= json_encode(__('services_included_sessions')) ?>,
                                days:        <?= json_encode(__('days')) ?>,
                                currency:    <?= json_encode(APP_CURRENCY) ?>,
                                noServices:  <?= json_encode(__('no_services_in_package') ?: 'No services configured') ?>,
                            };
                            const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                            const renderPreview = (id) => {
                                if (!preview) return;
                                if (!id || !PKG[id]) { preview.classList.add('d-none'); preview.innerHTML=''; return; }
                                const p = PKG[id];
                                const svcRows = (p.services && p.services.length) ? p.services.map(s =>
                                    '<div class="pkg-preview-svc">' +
                                        '<span class="pkg-preview-svc-name"><i class="fa-solid fa-circle-dot me-1 text-teal"></i>' + esc(s.name) + '</span>' +
                                        '<span class="pkg-preview-svc-meta">' +
                                            '<span><i class="fa-regular fa-clock"></i>' + s.duration + 'm</span>' +
                                            '<span><i class="fa-solid fa-coins"></i>' + s.price.toFixed(2) + '</span>' +
                                            '<span class="badge bg-teal" style="background:var(--nt-teal-soft);color:var(--nt-teal-dark)">×' + s.count + ' ' + T.sessions + '</span>' +
                                        '</span>' +
                                    '</div>'
                                ).join('') : '<div class="text-muted small">' + T.noServices + '</div>';
                                preview.innerHTML =
                                    '<div class="pkg-preview-head">' +
                                        '<div class="pkg-preview-title"><i class="fa-solid fa-box-open"></i>' + esc(p.name) + '</div>' +
                                        '<div class="pkg-preview-stats">' +
                                            '<span><i class="fa-solid fa-list-check text-info"></i>' + p.sessions + ' ' + T.sessions + '</span>' +
                                            '<span><i class="fa-solid fa-coins text-warning"></i>' + p.price.toFixed(2) + ' ' + T.currency + '</span>' +
                                            '<span><i class="fa-regular fa-calendar text-muted"></i>' + p.validity + ' ' + T.days + '</span>' +
                                        '</div>' +
                                    '</div>' +
                                    (p.desc ? '<div class="pkg-preview-desc">' + esc(p.desc) + '</div>' : '') +
                                    '<div class="pkg-preview-body-head">' + T.services + '</div>' +
                                    '<div class="pkg-preview-body">' + svcRows + '</div>';
                                preview.classList.remove('d-none');
                            };
                            const apply = () => {
                                const isExisting = sel.value !== '';
                                newOnly.forEach(el => el.classList.toggle('d-none', isExisting));
                                renderPreview(parseInt(sel.value, 10));
                            };
                            sel.addEventListener('change', apply);
                            apply();
                        })();
                        </script>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Patient + visit details + attachments -->
        <div class="col-lg-4">
            <!-- Patient mini card -->
            <div class="info-card patient-mini-card mb-3">
                <div class="info-card-head"><i class="fa-solid fa-user"></i><?= __('patient_info') ?></div>
                <?php $initials = mb_strtoupper(mb_substr($cs['first_name'],0,1).mb_substr($cs['last_name'],0,1)); ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="entity-avatar" style="width:60px;height:60px;font-size:1.3rem"><span><?= e($initials) ?></span></div>
                    <div class="flex-1 min-w-0">
                        <a href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$cs['patient_id'] ?>" class="d-block fw-semibold text-dark text-decoration-none">
                            <?= e($cs['first_name'].' '.$cs['last_name']) ?>
                        </a>
                        <code class="small"><?= e($cs['patient_code']) ?></code>
                    </div>
                </div>
                <div class="patient-mini-row">
                    <i class="fa-solid fa-phone text-muted"></i>
                    <a href="tel:<?= e($cs['phone']) ?>" dir="ltr" class="text-decoration-none text-dark"><?= e($cs['phone']) ?></a>
                </div>
                <?php if ($age !== ''): ?>
                    <div class="patient-mini-row">
                        <i class="fa-solid fa-cake-candles text-muted"></i>
                        <span><?= (int)$age ?> <?= __('years_old') ?? 'years' ?></span>
                    </div>
                <?php endif; ?>
                <a class="btn btn-sm btn-light w-100 mt-3" href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$cs['patient_id'] ?>">
                    <i class="fa-solid fa-folder-open me-1"></i><?= __('view_profile') ?>
                </a>
            </div>

            <!-- Visit details -->
            <div class="info-card mb-3">
                <div class="info-card-head"><i class="fa-regular fa-clipboard"></i><?= __('visit_details') ?></div>
                <div class="info-card-grid" style="grid-template-columns: 1fr">
                    <div><span class="info-label"><?= __('doctor') ?></span><span class="info-value"><i class="fa-solid fa-user-doctor me-1 text-muted"></i><?= e($cs['doctor_name'] ?? '—') ?></span></div>
                    <div><span class="info-label"><?= __('duration') ?></span><span class="info-value"><?= (int)$cs['duration_minutes'] ?> <?= __('minutes') ?></span></div>
                    <?php if ($cs['mode'] === 'video' && $cs['video_link']): ?>
                        <div><span class="info-label"><?= __('video_link') ?></span><span class="info-value"><a target="_blank" href="<?= e($cs['video_link']) ?>"><i class="fa-solid fa-video me-1"></i><?= __('open') ?></a></span></div>
                    <?php endif; ?>
                    <?php if ($cs['service_name']): ?>
                        <div><span class="info-label"><?= __('services') ?></span><span class="info-value"><?= e($cs['service_name']) ?></span></div>
                    <?php endif; ?>
                    <div><span class="info-label"><?= __('fee') ?></span>
                        <span class="info-value">
                            <?php if ((float)$cs['fee'] <= 0): ?>
                                <span class="badge bg-success"><i class="fa-solid fa-gift me-1"></i><?= __('free') ?></span>
                            <?php else: ?>
                                <strong class="text-teal"><?= format_money($cs['fee']) ?></strong>
                                <?php if (!$cs['paid']): ?><span class="badge bg-warning ms-1"><?= __('unpaid') ?></span>
                                <?php else: ?><span class="badge bg-success ms-1"><?= __('paid') ?></span><?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($cs['follow_up_date']): ?>
                        <div><span class="info-label"><?= __('follow_up_date') ?></span><span class="info-value"><i class="fa-regular fa-calendar-check me-1"></i><?= e($cs['follow_up_date']) ?></span></div>
                    <?php endif; ?>
                    <?php if ((int)$cs['prescribed_sessions'] > 0): ?>
                        <div><span class="info-label"><?= __('prescribed_sessions') ?></span><span class="info-value"><i class="fa-solid fa-list-ol me-1 text-muted"></i><?= (int)$cs['prescribed_sessions'] ?></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attachments -->
            <?php if ($files): ?>
                <div class="info-card">
                    <div class="info-card-head"><i class="fa-solid fa-paperclip"></i><?= __('attachments') ?> <span class="text-muted">(<?= count($files) ?>)</span></div>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($files as $f): $isPdf = strpos($f['mime_type'],'pdf') !== false; ?>
                            <a class="file-tile" href="<?= UPLOADS_URL . e($f['file_name']) ?>" target="_blank">
                                <div class="file-tile-icon <?= $isPdf?'is-pdf':'' ?>">
                                    <i class="fa-solid <?= $isPdf?'fa-file-pdf':'fa-image' ?>"></i>
                                </div>
                                <div>
                                    <div class="file-tile-name"><?= e($f['original_name']) ?></div>
                                    <div class="file-tile-meta"><?= number_format($f['size_bytes']/1024,1) ?> KB</div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
