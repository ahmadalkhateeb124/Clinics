<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('cms.view');

$PageTitle = __('faqs');
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($action==='delete' && $id) {
    csrf_check(); require_can('cms.delete');
    db()->prepare("UPDATE faqs SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    flash('success','Deleted.'); redirect(BP_URL.'admin/faqs.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['create','edit'],true)) {
    csrf_check(); require_can($action==='create'?'cms.create':'cms.edit');
    $qAr = trim($_POST['question_ar'] ?? '');
    $qEn = trim($_POST['question_en'] ?? '');
    $aAr = trim($_POST['answer_ar'] ?? '');
    $aEn = trim($_POST['answer_en'] ?? '');
    $cat = trim($_POST['category'] ?? '');
    $sort= (int)($_POST['sort_order'] ?? 0);
    $act = isset($_POST['is_active']) ? 1 : 0;
    if ($qAr === '' || $aAr === '') { flash('error','Question and answer (AR) required.'); back(); }
    if ($action==='create') {
        db()->prepare("INSERT INTO faqs (question_ar,question_en,answer_ar,answer_en,category,sort_order,is_active,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$qAr,$qEn,$aAr,$aEn,$cat,$sort,$act]);
    } else {
        db()->prepare("UPDATE faqs SET question_ar=?,question_en=?,answer_ar=?,answer_en=?,category=?,sort_order=?,is_active=?,updated_at=NOW() WHERE id=?")
            ->execute([$qAr,$qEn,$aAr,$aEn,$cat,$sort,$act,$id]);
    }
    flash('success','Saved.'); redirect(BP_URL.'admin/faqs.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'cms.create':'cms.edit');
    $f = ['question_ar'=>'','question_en'=>'','answer_ar'=>'','answer_en'=>'','category'=>'','sort_order'=>0,'is_active'=>1];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM faqs WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $f = $s->fetch();
    }
    include BP_PARTIALS.'/header.php'; ?>
    <div class="page-wrap"><h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — FAQ</h4>
    <form method="post" class="card"><div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-9"><label class="form-label"><?= __('question_ar') ?> *</label><input name="question_ar" required class="form-control" value="<?= e($f['question_ar']) ?>"></div>
            <div class="col-md-3"><label class="form-label"><?= __('category') ?></label><input name="category" class="form-control" value="<?= e($f['category']??'') ?>"></div>
            <div class="col-md-12"><label class="form-label"><?= __('question_en') ?></label><input name="question_en" class="form-control" value="<?= e($f['question_en']??'') ?>"></div>
            <div class="col-md-6"><label class="form-label"><?= __('answer_ar') ?> *</label><textarea name="answer_ar" rows="4" required class="form-control"><?= e($f['answer_ar']) ?></textarea></div>
            <div class="col-md-6"><label class="form-label"><?= __('answer_en') ?></label><textarea name="answer_en" rows="4" class="form-control"><?= e($f['answer_en']??'') ?></textarea></div>
            <div class="col-md-2"><label class="form-label"><?= __('sort') ?></label><input type="number" name="sort_order" class="form-control" value="<?= (int)$f['sort_order'] ?>"></div>
            <div class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" <?= !empty($f['is_active'])?'checked':'' ?>><span class="form-check-label"><?= __('active') ?></span></label></div>
        </div>
        <div class="mt-3"><button class="btn btn-teal"><?= __('save') ?></button> <a class="btn btn-light" href="<?= BP_URL ?>admin/faqs.php"><?= __('cancel') ?></a></div>
    </div></form></div>
    <?php include BP_PARTIALS.'/footer.php'; exit;
}

$rows = db()->query("SELECT * FROM faqs WHERE deleted_at IS NULL ORDER BY sort_order, id")->fetchAll();
$kpi = ['total'=>count($rows),'active'=>0,'inactive'=>0,'cats'=>[]];
foreach ($rows as $r) {
    if ($r['is_active']) $kpi['active']++; else $kpi['inactive']++;
    if (!empty($r['category'])) $kpi['cats'][$r['category']] = true;
}
$kpi['cats_count'] = count($kpi['cats']);

include BP_PARTIALS.'/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-circle-question text-teal me-2"></i><?= __('faqs') ?>
        </h4>
        <div class="page-header-actions">
            <?php if (can('cms.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_faq') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-circle-question"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_faqs') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#94a3b8"><i class="fa-solid fa-circle-minus"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('inactive') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['inactive'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-tags"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('categories') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['cats_count'] ?></div>
            </div>
        </div>
    </div>
    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr><th>#</th><th><?= __('question') ?></th><th><?= __('category') ?></th><th><?= __('status') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $f): ?>
                    <tr>
                        <td><?= (int)$f['id'] ?></td>
                        <td><?= e($f['question_ar']) ?></td>
                        <td class="small text-muted"><?= e($f['category']??'—') ?></td>
                        <td><span class="badge bg-<?= $f['is_active']?'success':'secondary' ?>"><?= __($f['is_active']?'active':'inactive') ?></span></td>
                        <td class="text-end">
                            <?= render_actions([
                                (can('cms.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$f['id'],'modal'=>true] : null),
                                (can('cms.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$f['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-circle-question"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $f):
                $chips = [['label'=>__($f['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$f['is_active']?'success':'']];
                if (!empty($f['category'])) $chips[] = ['label'=>$f['category'],'icon'=>'fa-tag','class'=>'teal'];
                echo render_entity_card([
                    'avatar_icon' => 'fa-circle-question',
                    'avatar_class' => 'square indigo',
                    'title' => $f['question_ar'],
                    'chips' => $chips,
                    'actions' => [
                        (can('cms.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$f['id'],'modal'=>true] : null),
                        (can('cms.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$f['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS.'/footer.php'; ?>
