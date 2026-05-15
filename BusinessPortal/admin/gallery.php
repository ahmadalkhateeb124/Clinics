<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('cms.view');

$PageTitle = __('gallery');
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($action==='delete' && $id) {
    csrf_check(); require_can('cms.delete');
    db()->prepare("UPDATE gallery SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    flash('success','Deleted.'); redirect(BP_URL.'admin/gallery.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='upload') {
    csrf_check(); require_can('cms.create');
    $title = trim($_POST['title'] ?? '');
    $cat = trim($_POST['category'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $count = 0;
    $failures = [];
    if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        $files = $_FILES['images'];
        for ($i=0; $i<count($files['name']); $i++) {
            $f = ['name'=>$files['name'][$i],'tmp_name'=>$files['tmp_name'][$i],'type'=>$files['type'][$i],'size'=>$files['size'][$i],'error'=>$files['error'][$i]];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $failures[] = ($f['name'] ?: 'file '.$i) . ': PHP upload error #' . $f['error'];
                continue;
            }
            $reason = null;
            $up = upload_file($f,'gallery',['jpg','jpeg','jfif','png','webp','gif','avif','bmp','heic','heif','tiff','tif','ico'], 16 * 1048576, $reason);
            if ($up) {
                db()->prepare("INSERT INTO gallery (title,image,category,sort_order,is_active,created_at) VALUES (?,?,?,?,1,NOW())")
                    ->execute([$title !== '' ? $title : null, $up['relative_path'], $cat ?: null, $sort]);
                $count++;
            } else {
                $failures[] = ($f['name'] ?: 'file '.$i) . ': ' . ($reason ?: 'unknown');
            }
        }
    }
    if ($count > 0) {
        flash('success', "$count image(s) uploaded.");
    } else {
        $msg = 'No images uploaded.';
        if ($failures) $msg .= ' Reason: ' . implode(' | ', $failures);
        flash('error', $msg);
    }
    redirect(BP_URL.'admin/gallery.php');
}

$catFilter = trim($_GET['category'] ?? '');
$where = "deleted_at IS NULL"; $params = [];
if ($catFilter !== '') { $where .= " AND category = ?"; $params[] = $catFilter; }

$rs = db()->prepare("SELECT * FROM gallery WHERE $where ORDER BY sort_order, id DESC");
$rs->execute($params); $rows = $rs->fetchAll();

$all = db()->query("SELECT category, COUNT(*) c FROM gallery WHERE deleted_at IS NULL GROUP BY category")->fetchAll();
$kpi = ['total'=>0,'categories'=>0];
$catList = [];
foreach ($all as $r) {
    $kpi['total'] += (int)$r['c'];
    if (!empty($r['category'])) { $kpi['categories']++; $catList[$r['category']] = (int)$r['c']; }
}

include BP_PARTIALS.'/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-image text-teal me-2"></i><?= __('gallery') ?>
        </h4>
        <div class="page-header-actions">
            <?php if (can('cms.create')): ?>
                <button type="button" class="btn btn-teal btn-sm" onclick="document.getElementById('dropzone').scrollIntoView({behavior:'smooth'});document.getElementById('dz-input').click()">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i><?= __('upload_images') ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-image"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_images') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-tags"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('categories') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['categories'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-filter"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('showing') ?></div>
                <div class="appt-kpi-value"><?= count($rows) ?></div>
            </div>
        </div>
    </div>

    <?php if (can('cms.create')): ?>
    <!-- Drag & drop upload zone -->
    <form id="upload-form" method="post" action="?action=upload" enctype="multipart/form-data" class="mb-3">
        <?= csrf_field() ?>
        <div id="dropzone" class="dropzone">
            <input id="dz-input" type="file" name="images[]" multiple accept="image/*" hidden>
            <div class="dz-empty">
                <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="dz-title"><?= __('drop_images_here') ?></div>
                <div class="dz-sub"><?= __('or_click_to_browse') ?> · JPG, PNG, WebP</div>
            </div>
            <div id="dz-previews" class="dz-previews"></div>
            <div class="dz-meta-row">
                <input name="title" class="form-control form-control-sm" placeholder="<?= __('title_optional') ?>">
                <input name="category" class="form-control form-control-sm" placeholder="<?= __('category_optional') ?>" list="cat-list">
                <datalist id="cat-list">
                    <?php foreach (array_keys($catList) as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
                </datalist>
                <input type="number" name="sort_order" class="form-control form-control-sm" value="0" placeholder="<?= __('sort') ?>" style="max-width:90px">
                <button type="submit" id="dz-submit" class="btn btn-teal btn-sm" disabled>
                    <i class="fa-solid fa-upload me-1"></i><span id="dz-submit-text"><?= __('upload') ?></span>
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <!-- Category chips filter -->
    <?php if ($catList): ?>
        <div class="mb-3 d-flex flex-wrap gap-2">
            <a href="?" class="badge text-decoration-none <?= $catFilter===''?'bg-teal text-white':'bg-light text-dark border' ?>" style="padding:.5rem .8rem;font-size:.8rem">
                <?= __('all') ?> · <?= (int)$kpi['total'] ?>
            </a>
            <?php foreach ($catList as $c => $cnt): ?>
                <a href="?category=<?= urlencode($c) ?>" class="badge text-decoration-none <?= $catFilter===$c?'bg-teal text-white':'bg-light text-dark border' ?>" style="padding:.5rem .8rem;font-size:.8rem">
                    <i class="fa-solid fa-tag me-1"></i><?= e($c) ?> · <?= $cnt ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Masonry-style grid -->
    <div class="gallery-grid">
        <?php foreach ($rows as $g): ?>
            <div class="gallery-tile">
                <a href="<?= UPLOADS_URL.e($g['image']) ?>" target="_blank" class="gallery-img">
                    <img src="<?= UPLOADS_URL.e($g['image']) ?>" alt="<?= e($g['title']??'') ?>" loading="lazy">
                </a>
                <div class="gallery-overlay">
                    <div class="gallery-title text-truncate"><?= e($g['title']??'') ?></div>
                    <?php if (!empty($g['category'])): ?>
                        <div class="gallery-cat"><i class="fa-solid fa-tag me-1"></i><?= e($g['category']) ?></div>
                    <?php endif; ?>
                    <?php if (can('cms.delete')): ?>
                        <a class="gallery-del" data-confirm="<?= __('are_you_sure') ?>"
                           href="?action=delete&id=<?= (int)$g['id'] ?>&_csrf=<?= e(csrf_token()) ?>"
                           title="<?= __('delete') ?>">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <div class="empty-state w-100 py-5"><i class="fa-regular fa-image"></i><div><?= __('no_data') ?></div></div>
        <?php endif; ?>
    </div>
</div>

<style>
.dropzone{border:2px dashed #cbd5e1;border-radius:14px;background:#f8fafc;padding:1.5rem;text-align:center;transition:.2s}
.dropzone.is-drag{border-color:#0d9488;background:#ecfdf5}
.dz-empty{cursor:pointer;padding:1.5rem 0}
.dz-icon{font-size:2.5rem;color:#0d9488;margin-bottom:.5rem}
.dz-title{font-weight:600;font-size:1.05rem;color:#0f172a}
.dz-sub{font-size:.85rem;color:#64748b;margin-top:.25rem}
.dz-previews{display:none;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.5rem;margin:1rem 0}
.dz-previews.has-items{display:grid}
.dz-thumb{position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;background:#fff;border:1px solid #e2e8f0}
.dz-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.dz-thumb-remove{position:absolute;top:4px;inset-inline-end:4px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;border:0;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.7rem}
.dz-meta-row{display:none;gap:.5rem;align-items:center;padding-top:.75rem;border-top:1px dashed #e2e8f0;flex-wrap:wrap}
.dropzone.has-files .dz-empty{padding:0;display:none}
.dropzone.has-files .dz-meta-row{display:flex}

.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem}
.gallery-tile{position:relative;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.05);aspect-ratio:1}
.gallery-img{display:block;width:100%;height:100%}
.gallery-img img{width:100%;height:100%;object-fit:cover;display:block;transition:.3s}
.gallery-tile:hover .gallery-img img{transform:scale(1.05)}
.gallery-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.75) 0%,transparent 50%);color:#fff;padding:.6rem;display:flex;flex-direction:column;justify-content:flex-end;opacity:0;transition:.2s;pointer-events:none}
.gallery-tile:hover .gallery-overlay{opacity:1}
.gallery-overlay .gallery-del{pointer-events:auto}
.gallery-title{font-size:.85rem;font-weight:600}
.gallery-cat{font-size:.7rem;opacity:.85;margin-top:.15rem}
.gallery-del{position:absolute;top:.5rem;inset-inline-end:.5rem;width:32px;height:32px;border-radius:50%;background:rgba(239,68,68,.95);color:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:.8rem;pointer-events:auto}
.gallery-del:hover{background:#dc2626;color:#fff}
</style>

<script>
(function(){
    const dz = document.getElementById('dropzone');
    if (!dz) return;
    const input = document.getElementById('dz-input');
    const previews = document.getElementById('dz-previews');
    const submitBtn = document.getElementById('dz-submit');
    const submitText = document.getElementById('dz-submit-text');
    const empty = dz.querySelector('.dz-empty');
    let dt = new DataTransfer();

    empty.addEventListener('click', () => input.click());

    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('is-drag'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('is-drag'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('is-drag');
        addFiles(e.dataTransfer.files);
    });
    input.addEventListener('change', () => addFiles(input.files));

    function addFiles(files){
        for (const f of files) {
            if (!f.type.startsWith('image/')) continue;
            dt.items.add(f);
        }
        input.files = dt.files;
        render();
    }
    function render(){
        previews.innerHTML = '';
        const items = Array.from(dt.files);
        items.forEach((f, idx) => {
            const url = URL.createObjectURL(f);
            const wrap = document.createElement('div');
            wrap.className = 'dz-thumb';
            wrap.innerHTML = `<img src="${url}"><button type="button" class="dz-thumb-remove" data-idx="${idx}"><i class="fa-solid fa-xmark"></i></button>`;
            previews.appendChild(wrap);
        });
        if (items.length){
            previews.classList.add('has-items');
            dz.classList.add('has-files');
            submitBtn.disabled = false;
            submitText.textContent = (window.NT_I18N?.upload || '<?= __('upload') ?>') + ' (' + items.length + ')';
        } else {
            previews.classList.remove('has-items');
            dz.classList.remove('has-files');
            submitBtn.disabled = true;
            submitText.textContent = '<?= __('upload') ?>';
        }
    }
    previews.addEventListener('click', e => {
        const btn = e.target.closest('.dz-thumb-remove');
        if (!btn) return;
        const idx = +btn.dataset.idx;
        const next = new DataTransfer();
        Array.from(dt.files).forEach((f,i) => { if (i!==idx) next.items.add(f); });
        dt = next; input.files = dt.files; render();
    });
})();
</script>
<?php include BP_PARTIALS.'/footer.php'; ?>
