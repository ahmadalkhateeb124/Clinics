<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('appointments.view');

$PageTitle = __('appointments_calendar');

// Quick stats for the header strip
$today = date('Y-m-d');
$kpi = db()->query("
    SELECT
      SUM(DATE(start_at) = '$today' AND status NOT IN ('cancelled','no_show'))                                 AS today_count,
      SUM(start_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status NOT IN ('cancelled','no_show')) AS week_count,
      SUM(status = 'scheduled')                                                                                AS scheduled,
      SUM(status = 'confirmed')                                                                                AS confirmed
    FROM appointments WHERE deleted_at IS NULL
")->fetch() ?: ['today_count'=>0,'week_count'=>0,'scheduled'=>0,'confirmed'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-regular fa-calendar text-teal me-2"></i><?= __('appointments_calendar') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/appointments.php">
                <i class="fa-solid fa-list me-1"></i><?= __('list') ?>
            </a>
            <?php if (can('appointments.create')): ?>
                <a class="btn btn-teal btn-sm" href="<?= BP_URL ?>admin/appointments.php?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_appointment') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('today') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['today_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('this_week') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['week_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_scheduled') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['scheduled'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#3b82f6"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_confirmed') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['confirmed'] ?></div>
            </div>
        </div>
    </div>

    <!-- Status legend -->
    <div class="cal-legend">
        <span class="cal-legend-item"><span class="cal-dot" style="background:#0ea5e9"></span><?= __('st_scheduled') ?></span>
        <span class="cal-legend-item"><span class="cal-dot" style="background:#3b82f6"></span><?= __('st_confirmed') ?></span>
        <span class="cal-legend-item"><span class="cal-dot" style="background:#10b981"></span><?= __('st_completed') ?></span>
        <span class="cal-legend-item"><span class="cal-dot" style="background:#f59e0b"></span><?= __('st_no_show') ?></span>
        <span class="cal-legend-item"><span class="cal-dot" style="background:#ef4444"></span><?= __('st_cancelled') ?></span>
        <span class="ms-auto small text-muted"><i class="fa-solid fa-circle-info me-1"></i><?= __('drag_resize_hint') ?></span>
    </div>

    <!-- Calendar card -->
    <div class="cal-wrap">
        <div id="calendar"></div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const isRTL = document.documentElement.dir === 'rtl';
const apiUrl = '<?= BP_URL ?>admin/calendar-api.php';

const STATUS_COLORS = {
    scheduled:  { bg: '#0ea5e9', border: '#0284c7' },
    confirmed:  { bg: '#3b82f6', border: '#2563eb' },
    completed:  { bg: '#10b981', border: '#059669' },
    no_show:    { bg: '#f59e0b', border: '#d97706' },
    cancelled:  { bg: '#ef4444', border: '#dc2626' },
};

document.addEventListener('DOMContentLoaded', function () {
    const isMobile = window.matchMedia('(max-width: 767px)').matches;
    const cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: isMobile ? 'listWeek' : 'timeGridWeek',
        direction: isRTL ? 'rtl' : 'ltr',
        locale: isRTL ? 'ar' : 'en',
        firstDay: 0,
        slotMinTime: '<?= e(setting('working_hours_from','09:00')) ?>',
        slotMaxTime: '<?= e(setting('working_hours_to','21:00')) ?>',
        slotDuration: '00:30:00',
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        nowIndicator: true,
        editable: <?= can('appointments.edit') ? 'true' : 'false' ?>,
        selectable: false,
        height: 'auto',
        contentHeight: 720,
        expandRows: true,
        dayMaxEvents: 4,
        headerToolbar: {
            left:   isMobile ? 'prev,next' : 'prev,next today',
            center: 'title',
            right:  isMobile ? 'timeGridDay,listWeek' : 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        },
        buttonText: {
            today:  <?= json_encode(__('today')) ?>,
            month:  <?= json_encode(__('month')) ?>,
            week:   <?= json_encode(__('week')) ?>,
            day:    <?= json_encode(__('day')) ?>,
            list:   <?= json_encode(__('list')) ?>,
        },
        events: function (info, success, fail) {
            fetch(apiUrl + '?action=list&from=' + info.startStr + '&to=' + info.endStr)
                .then(r => r.json())
                .then(rows => {
                    success(rows.map(ev => {
                        const status = (ev.extendedProps && ev.extendedProps.status) || 'scheduled';
                        const c = STATUS_COLORS[status] || STATUS_COLORS.scheduled;
                        return Object.assign({}, ev, {
                            backgroundColor: c.bg,
                            borderColor:     c.border,
                            textColor: '#fff',
                        });
                    }));
                })
                .catch(fail);
        },
        eventDidMount: function (info) {
            // Add a tooltip with full details
            const p = info.event.extendedProps;
            const parts = [info.event.title];
            if (p.therapist) parts.push('👨‍⚕️ ' + p.therapist);
            if (p.room)      parts.push('🚪 ' + p.room);
            info.el.title = parts.join('\n');
            // Visual cue for cancelled
            if (p.status === 'cancelled') info.el.style.opacity = '.65';
        },
        eventClick: function (info) {
            window.location = '<?= BP_URL ?>admin/appointments.php?action=view&id=' + info.event.id;
        },
        eventDrop:   info => persistMove(info),
        eventResize: info => persistMove(info),
    });

    function persistMove(info) {
        const fd = new FormData();
        fd.append('_csrf', csrfToken);
        fd.append('action', 'reschedule');
        fd.append('id', info.event.id);
        fd.append('start', info.event.start.toISOString());
        fd.append('end',   info.event.end ? info.event.end.toISOString() : info.event.start.toISOString());

        fetch(apiUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!j.ok) {
                    if (typeof ntToast === 'function') ntToast(j.msg || 'Failed', 'error');
                    info.revert();
                } else if (typeof ntToast === 'function') {
                    ntToast(<?= json_encode(__('appointment_rescheduled') ?: 'Appointment rescheduled') ?>, 'success');
                }
            })
            .catch(() => {
                if (typeof ntToast === 'function') ntToast(<?= json_encode(__('network_error') ?: 'Network error') ?>, 'error');
                info.revert();
            });
    }

    cal.render();
});
</script>
<?php include BP_PARTIALS . '/footer.php'; ?>
