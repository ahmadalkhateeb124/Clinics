// ════════════════════════════════════════════════════════════════════
// Nour's Touch — Admin JS
// ════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {

    // ── Auto-dismiss custom toasts ────────────────────────────────────
    const dismissToast = (t) => {
        if (!t || t.dataset.ntDismissed) return;
        t.dataset.ntDismissed = '1';
        t.classList.add('closing');
        // Fallback: remove after animation duration even if animationend never fires
        setTimeout(() => t.remove(), 350);
    };
    document.querySelectorAll('.nt-toast').forEach(t => {
        const ms = parseInt(t.dataset.autoDismiss || '6000', 10);
        if (ms > 0) setTimeout(() => dismissToast(t), ms);
    });
    // Manual close (X button): also use the safe dismisser
    document.addEventListener('click', e => {
        const closer = e.target.closest('.nt-toast-close');
        if (closer) dismissToast(closer.closest('.nt-toast'));
    });

    // ── Bootstrap dropdowns (mainnav + topbar) ────────────────────────
    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el => {
            bootstrap.Dropdown.getOrCreateInstance(el);
        });
    }

    // ── Auto-responsive tables: copy header text into td[data-label] ──
    // Lets CSS render each row as a card on small screens (see admin.css).
    document.querySelectorAll('.table-card .table').forEach(table => {
        if (table.dataset.noCards === '1') return;
        const heads = Array.from(table.querySelectorAll('thead th')).map(th =>
            (th.textContent || '').trim()
        );
        table.querySelectorAll('tbody tr').forEach(tr => {
            Array.from(tr.children).forEach((td, i) => {
                if (!td.hasAttribute('data-label') && heads[i]) {
                    td.setAttribute('data-label', heads[i]);
                }
            });
        });
        table.classList.add('table-cards');
    });
});

// ════════════════════════════════════════════════════════════════════
// Toast helper
// ════════════════════════════════════════════════════════════════════
window.ntToast = function (msg, type = 'info', duration = 5000) {
    let stack = document.querySelector('.nt-toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'nt-toast-stack';
        document.body.appendChild(stack);
    }
    const icons = {
        success: 'fa-circle-check',
        error:   'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation',
        info:    'fa-circle-info',
    };
    const toast = document.createElement('div');
    toast.className = 'nt-toast nt-toast-' + type;
    toast.innerHTML = `
        <div class="nt-toast-icon"><i class="fa-solid ${icons[type] || icons.info}"></i></div>
        <div class="nt-toast-body"></div>
        <button type="button" class="nt-toast-close"><i class="fa-solid fa-xmark"></i></button>
    `;
    toast.querySelector('.nt-toast-body').textContent = msg;
    toast.querySelector('.nt-toast-close').addEventListener('click', () => toast.classList.add('closing'));
    stack.appendChild(toast);
    if (duration > 0) setTimeout(() => toast.classList.add('closing'), duration);
    toast.addEventListener('animationend', () => {
        if (toast.classList.contains('closing')) toast.remove();
    });
    return toast;
};

// ════════════════════════════════════════════════════════════════════
// Modal system — AJAX page loader (create/edit/view) + confirm
// ════════════════════════════════════════════════════════════════════
(function () {
    const ntModal = {

        _root: null,
        _url: null,

        _ensure() {
            if (this._root) return this._root;
            const root = document.createElement('div');
            root.className = 'nt-modal';
            root.innerHTML = `
                <div class="nt-modal-backdrop"></div>
                <div class="nt-modal-panel">
                    <div class="nt-modal-header">
                        <h5 class="nt-modal-title"></h5>
                        <button class="nt-modal-close" type="button" aria-label="Close">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="nt-modal-body"></div>
                </div>
            `;
            document.body.appendChild(root);
            root.querySelector('.nt-modal-backdrop').addEventListener('click', () => this.close());
            root.querySelector('.nt-modal-close').addEventListener('click', () => this.close());
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && root.classList.contains('show')) this.close();
            });
            this._root = root;
            return root;
        },

        open(url, opts = {}) {
            const root = this._ensure();
            root.querySelector('.nt-modal-title').innerHTML =
                (opts.icon ? `<i class="fa-solid ${opts.icon}"></i>` : '') + this._esc(opts.title || '…');
            root.querySelector('.nt-modal-body').innerHTML =
                '<div class="nt-modal-loading"><div class="spinner"></div>جاري التحميل…</div>';
            root.querySelector('.nt-modal-panel').className = 'nt-modal-panel' + (opts.size ? ' ' + opts.size : '');
            root.classList.add('show');
            document.body.classList.add('modal-open');
            this._url = url;

            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.text())
                .then(html => this._inject(html, root))
                .catch(() => {
                    root.querySelector('.nt-modal-body').innerHTML =
                        '<div class="text-center text-danger p-4"><i class="fa-solid fa-circle-exclamation fa-2x mb-2"></i><div>فشل التحميل</div></div>';
                });
        },

        _inject(html, root) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const wrap = doc.querySelector('.page-wrap') || doc.querySelector('main') || doc.body;
            const ph = wrap.querySelector('.page-header h4, h4');
            if (ph) {
                const txt = ph.textContent.trim().replace(/\s+/g, ' ');
                root.querySelector('.nt-modal-title').innerHTML =
                    '<i class="fa-solid fa-pen-to-square"></i>' + this._esc(txt);
                const ph2 = wrap.querySelector('.page-header');
                if (ph2) ph2.remove();
            }
            // Surface any server-side flash messages that came back in the response
            // (these would otherwise be hidden behind the modal).
            doc.querySelectorAll('.nt-toast-stack .nt-toast').forEach(t => {
                const body = t.querySelector('.nt-toast-body')?.textContent?.trim() || '';
                if (!body) return;
                let type = 'info';
                if (t.classList.contains('nt-toast-error')) type = 'error';
                else if (t.classList.contains('nt-toast-success')) type = 'success';
                else if (t.classList.contains('nt-toast-warning')) type = 'warning';
                if (typeof ntToast === 'function') ntToast(body, type);
            });
            root.querySelector('.nt-modal-body').innerHTML = wrap.innerHTML;
            this._bindForms(root);
            this._executeInlineScripts(root);
        },

        _bindForms(root) {
            const self = this;
            root.querySelectorAll('form').forEach(form => {
                if (form.dataset.ntBound) return;
                form.dataset.ntBound = '1';
                form.addEventListener('submit', e => {
                    e.preventDefault();
                    const submitBtn = form.querySelector('[type=submit]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.dataset._t = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>' + ((window.NT_I18N?.saving) || 'جاري الحفظ…');
                    }
                    const fd = new FormData(form);
                    const action = form.getAttribute('action') || self._url || window.location.href;
                    const method = (form.getAttribute('method') || 'POST').toUpperCase();

                    fetch(action, {
                        method, body: fd, credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        redirect: 'follow'
                    }).then(r => {
                        // Permission/auth failure → surface the toast and keep the modal open
                        if (r.status === 403 || r.status === 401) {
                            return r.text().then(html => {
                                const doc = new DOMParser().parseFromString(html, 'text/html');
                                const t = doc.querySelector('.nt-toast-body')?.textContent?.trim()
                                       || doc.querySelector('h3,h5')?.textContent?.trim()
                                       || (window.NT_I18N?.access_denied) || 'لا تملك صلاحية';
                                ntToast(t, 'error');
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = submitBtn.dataset._t || 'حفظ';
                                }
                            });
                        }
                        const finalUrl = new URL(r.url, window.location.origin);
                        const finalAction = finalUrl.searchParams.get('action') || '';
                        // If the server bounced us back to the create/edit form, it's a validation failure.
                        const bouncedToForm = (finalAction === 'create' || finalAction === 'edit');

                        if (bouncedToForm) {
                            // Re-inject the returned HTML so the user sees flashes + can fix the form.
                            return r.text().then(html => {
                                self._inject(html, root); // emits server flash toasts as ntToast
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = submitBtn.dataset._t || 'حفظ';
                                }
                            });
                        }
                        // Otherwise the server redirected somewhere else → save succeeded.
                        self.close();
                        ntToast((window.NT_I18N?.saved_ok) || 'تم الحفظ بنجاح', 'success');
                        setTimeout(() => window.location.reload(), 400);
                    }).catch(() => {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = submitBtn.dataset._t || 'حفظ';
                        }
                        ntToast((window.NT_I18N?.submit_failed) || 'فشل الإرسال — حاول مجدداً', 'error');
                    });
                });
            });
        },

        _executeInlineScripts(root) {
            root.querySelectorAll('.nt-modal-body script').forEach(old => {
                const s = document.createElement('script');
                [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
                s.textContent = old.textContent;
                old.replaceWith(s);
            });
        },

        close() {
            if (!this._root) return;
            this._root.classList.remove('show');
            document.body.classList.remove('modal-open');
            this._root.querySelectorAll('form').forEach(f => delete f.dataset.ntBound);
        },

        confirm(opts) {
            const o = Object.assign({
                msg: (window.NT_I18N?.are_you_sure) || 'هل أنت متأكد؟',
                title: (window.NT_I18N?.confirm) || 'تأكيد',
                confirmText: (window.NT_I18N?.confirm) || 'تأكيد',
                cancelText:  (window.NT_I18N?.cancel)  || 'إلغاء',
                danger: true, icon: null, onConfirm: () => {},
            }, opts || {});
            const root = this._ensure();
            root.querySelector('.nt-modal-title').innerHTML =
                '<i class="fa-solid fa-triangle-exclamation"></i>' + this._esc(o.title);
            root.querySelector('.nt-modal-panel').className = 'nt-modal-panel sm';
            root.querySelector('.nt-modal-body').innerHTML = `
                <div class="nt-confirm ${o.danger ? 'danger' : ''}">
                    <div class="nt-confirm-icon"><i class="fa-solid ${o.icon || (o.danger ? 'fa-trash' : 'fa-triangle-exclamation')}"></i></div>
                    <div class="nt-confirm-msg">${this._esc(o.msg)}</div>
                    <div class="nt-confirm-actions">
                        <button type="button" class="btn btn-light" data-ntm-cancel>${this._esc(o.cancelText)}</button>
                        <button type="button" class="btn ${o.danger ? 'btn-danger' : 'btn-teal'}" data-ntm-confirm>${this._esc(o.confirmText)}</button>
                    </div>
                </div>
            `;
            root.classList.add('show');
            document.body.classList.add('modal-open');
            root.querySelector('[data-ntm-cancel]').onclick = () => this.close();
            root.querySelector('[data-ntm-confirm]').onclick = () => { this.close(); o.onConfirm(); };
        },

        _esc(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        },
    };

    window.ntModal = ntModal;

    // ── Delegated click handlers ───────────────────────────────────────
    document.addEventListener('click', e => {

        // 1) Modal trigger: <a data-modal href="?action=create"> or <button data-modal data-modal-url="…">
        const m = e.target.closest('[data-modal]');
        if (m && (m.tagName === 'A' || m.tagName === 'BUTTON')) {
            e.preventDefault();
            const url = m.getAttribute('href') || m.dataset.modal;
            if (url && url !== 'true' && url !== '#') {
                ntModal.open(url, {
                    title: m.dataset.modalTitle || m.getAttribute('title') || '',
                    icon:  m.dataset.modalIcon  || '',
                    size:  m.dataset.modalSize  || '',
                });
                return;
            }
        }

        // 2) Confirmation: any element with data-confirm now goes through the modal
        const c = e.target.closest('[data-confirm]');
        if (c) {
            e.preventDefault();
            const isDelete = (c.classList.contains('btn-outline-danger') ||
                              c.classList.contains('btn-danger') ||
                              /delete/i.test(c.getAttribute('href') || ''));
            ntModal.confirm({
                msg: c.dataset.confirm || (window.NT_I18N?.are_you_sure) || 'هل أنت متأكد؟',
                danger: isDelete,
                icon: isDelete ? 'fa-trash' : 'fa-triangle-exclamation',
                confirmText: isDelete ? ((window.NT_I18N?.delete) || 'حذف') : ((window.NT_I18N?.confirm) || 'تأكيد'),
                onConfirm: () => {
                    // Anchor with href → submit as POST (works with CSRF in query string)
                    if (c.tagName === 'A' && c.getAttribute('href')) {
                        const f = document.createElement('form');
                        f.method = 'POST';
                        f.action = c.getAttribute('href');
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                        if (csrf) {
                            const inp = document.createElement('input');
                            inp.type = 'hidden';
                            inp.name = '_csrf';
                            inp.value = csrf;
                            f.appendChild(inp);
                        }
                        document.body.appendChild(f);
                        f.submit();
                    } else if (c.tagName === 'FORM') {
                        c.submit();
                    } else if (c.tagName === 'BUTTON') {
                        const f = c.closest('form');
                        if (f) f.submit();
                    }
                },
            });
        }
    });
})();
