<?php
// ════════════════════════════════════════════════════════════════════════
// Notifications — email sending + log (Phase 9)
// Email-only (no SMS / WhatsApp / payment / birthday by user request)
// ════════════════════════════════════════════════════════════════════════

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Send an email via PHPMailer using SMTP settings from `settings` table.
 * Logs every attempt to `notifications`. Idempotent on (subject_type, subject_id, kind).
 *
 * @return array{ok:bool, log_id:int, msg:string, status:string}
 */
function send_email_notification(
    string $to,
    string $subject,
    string $htmlBody,
    string $kind = 'generic',
    ?string $subjectType = null,
    $subjectId = null,
    ?string $textBody = null
): array {
    $pdo = db();

    // Idempotency: skip if a sent record already exists for this (subject_type, subject_id, kind)
    if ($subjectType && $subjectId !== null) {
        $check = $pdo->prepare("
            SELECT id, status FROM notifications
            WHERE subject_type = ? AND subject_id = ? AND kind = ?
            LIMIT 1
        ");
        $check->execute([$subjectType, (int)$subjectId, $kind]);
        if ($r = $check->fetch()) {
            if (in_array($r['status'], ['sent','queued'], true)) {
                return ['ok' => true, 'log_id' => (int)$r['id'], 'msg' => 'Already sent / queued', 'status' => $r['status']];
            }
            // failed before → allow retry by deleting old row
            $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([(int)$r['id']]);
        }
    }

    // Insert queued log row first
    $pdo->prepare("INSERT INTO notifications (kind, channel, recipient, subject, body, subject_type, subject_id, status, created_at)
                   VALUES (?, 'email', ?, ?, ?, ?, ?, 'queued', NOW())")
        ->execute([$kind, $to, $subject, $htmlBody, $subjectType, $subjectId !== null ? (int)$subjectId : null]);
    $logId = (int)$pdo->lastInsertId();

    if (!class_exists(PHPMailer::class)) {
        $err = 'PHPMailer not installed.';
        $pdo->prepare("UPDATE notifications SET status='failed', error=? WHERE id=?")->execute([$err, $logId]);
        return ['ok' => false, 'log_id' => $logId, 'msg' => $err, 'status' => 'failed'];
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = "Invalid recipient email: $to";
        $pdo->prepare("UPDATE notifications SET status='skipped', error=? WHERE id=?")->execute([$err, $logId]);
        return ['ok' => false, 'log_id' => $logId, 'msg' => $err, 'status' => 'skipped'];
    }

    $host  = setting('mail_host', '');
    $port  = (int)setting('mail_port', '587');
    $user  = setting('mail_user', '');
    $pass  = setting('mail_pass', '');
    $from  = setting('mail_from', $user);
    $name  = setting('mail_from_name', setting('site_name_ar', APP_NAME_AR));
    $sec   = setting('mail_secure', 'tls');   // tls / ssl / ''

    $mail = new PHPMailer(true);
    try {
        if ($host !== '') {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port ?: 587;
            $mail->SMTPAuth   = ($user !== '');
            $mail->Username   = $user;
            $mail->Password   = $pass;
            if ($sec === 'ssl')      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            elseif ($sec === 'tls')  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->isMail();   // PHP mail() fallback
        }
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom($from ?: 'no-reply@localhost', $name);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $mail->send();

        $pdo->prepare("UPDATE notifications SET status='sent', sent_at=NOW() WHERE id=?")->execute([$logId]);
        return ['ok' => true, 'log_id' => $logId, 'msg' => 'Sent', 'status' => 'sent'];
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($mail->ErrorInfo) $err .= ' | ' . $mail->ErrorInfo;
        $pdo->prepare("UPDATE notifications SET status='failed', error=? WHERE id=?")->execute([$err, $logId]);
        error_log("Notification send failed: $err");
        return ['ok' => false, 'log_id' => $logId, 'msg' => $err, 'status' => 'failed'];
    }
}

/** Render the appointment reminder email body. */
function render_appointment_reminder_email(array $appt, string $kind): string {
    $hoursAhead = $kind === 'appointment_24h' ? 24 : 2;
    $startTs = strtotime($appt['start_at']);
    $weekdayMap = ['Sun'=>'الأحد','Mon'=>'الإثنين','Tue'=>'الثلاثاء','Wed'=>'الأربعاء','Thu'=>'الخميس','Fri'=>'الجمعة','Sat'=>'السبت'];
    $monthMap   = ['01'=>'يناير','02'=>'فبراير','03'=>'مارس','04'=>'أبريل','05'=>'مايو','06'=>'يونيو','07'=>'يوليو','08'=>'أغسطس','09'=>'سبتمبر','10'=>'أكتوبر','11'=>'نوفمبر','12'=>'ديسمبر'];
    $weekday = $weekdayMap[date('D',$startTs)] ?? '';
    $day     = date('j',$startTs);
    $month   = $monthMap[date('m',$startTs)] ?? '';
    $year    = date('Y',$startTs);
    $time    = date('g:i',$startTs);
    $ampmEn  = date('A',$startTs);
    $ampm    = $ampmEn === 'AM' ? 'صباحاً' : 'مساءً';

    $patient = e($appt['first_name'] . ' ' . $appt['last_name']);
    $svcs    = e($appt['svc_names'] ?? '—');
    $ther    = e($appt['therapist_name'] ?? '—');
    $room    = e($appt['room_name'] ?? '—');
    $clinic   = e(setting('site_name_ar', APP_NAME_AR));
    $clinicEn = e(setting('site_name_en', APP_NAME));
    $phone    = e(setting('contact_phone', ''));
    $phoneRaw = preg_replace('/[^0-9+]/','', setting('contact_phone',''));
    $email    = e(setting('contact_email', ''));
    $address  = e(setting('address', ''));
    $mapsUrl  = $address ? 'https://maps.google.com/?q='.urlencode(setting('address','')) : '';
    $logoRel  = setting('clinic_logo', '');
    $logoUrl  = $logoRel ? UPLOADS_URL . $logoRel : '';

    $heading = $hoursAhead === 24 ? "تذكير بموعدك" : "موعدك يقترب";
    $sub     = $hoursAhead === 24
        ? "نتشرّف باستقبالكم غداً"
        : "نتشرّف باستقبالكم بعد ساعتين";

    // Brand mark: logo image if available, otherwise monogram circle
    $monogram = mb_substr(setting('site_name_ar', APP_NAME_AR), 0, 1);
    $brandMark = $logoUrl
        ? '<img src="'.e($logoUrl).'" alt="" style="display:block;max-width:120px;max-height:80px;margin:0 auto;">'
        : '<div style="display:inline-block;width:56px;height:56px;border:1.5px solid #0d9488;border-radius:50%;text-align:center;line-height:54px;font-size:24px;color:#0d9488;font-family:\'Times New Roman\',Georgia,serif;font-weight:400;">'.$monogram.'</div>';

    // Phone link & map text link only (no buttons)
    $mapLink = $mapsUrl
        ? '<a href="'.$mapsUrl.'" style="color:#0d9488;text-decoration:none;border-bottom:1px solid #5eead4;padding-bottom:2px;">عرض الموقع على الخريطة</a>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$heading — $clinic</title>
</head>
<body style="margin:0;padding:0;background:#f6fbfa;font-family:'Times New Roman',Georgia,'Cambria',serif;color:#1c1b18;">
<!-- preheader -->
<div style="display:none;max-height:0;overflow:hidden;color:transparent;visibility:hidden;mso-hide:all;">
$sub · $weekday $day $month $year · $time $ampm
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6fbfa;padding:48px 12px;">
  <tr><td align="center">
    <table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;width:100%;background:#ffffff;border:1px solid #d4ede9;">

      <!-- Top accent line -->
      <tr><td style="height:4px;background:#0d9488;line-height:4px;font-size:0;">&nbsp;</td></tr>

      <!-- Masthead -->
      <tr><td style="padding:44px 48px 36px;text-align:center;border-bottom:1px solid #d4ede9;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
          <tr><td align="center">$brandMark</td></tr>
          <tr><td align="center">
            <div style="margin-top:18px;font-size:11px;letter-spacing:.36em;color:#0f766e;text-transform:uppercase;font-family:Arial,sans-serif;font-weight:500;">$clinicEn</div>
            <div style="margin-top:10px;font-size:24px;color:#1c1b18;font-weight:400;letter-spacing:.02em;">عيادة $clinic</div>
          </td></tr>
        </table>
      </td></tr>

      <!-- Greeting / heading -->
      <tr><td style="padding:48px 56px 12px;text-align:center;">
        <div style="font-size:13px;letter-spacing:.32em;color:#0d9488;text-transform:uppercase;font-family:Arial,sans-serif;font-weight:500;">$heading</div>
        <div style="margin-top:18px;font-size:30px;color:#1c1b18;font-weight:400;line-height:1.35;letter-spacing:.01em;">عزيزنا $patient</div>
        <div style="margin-top:18px;height:1px;width:48px;background:#0d9488;margin-inline-start:auto;margin-inline-end:auto;line-height:1px;font-size:0;">&nbsp;</div>
        <div style="margin-top:22px;color:#5c574f;font-size:15px;line-height:1.95;font-family:Arial,sans-serif;">
          $sub. نُرحّب بكم في عيادتنا، ونرجو التكرّم بالحضور قبل عشر دقائق من الموعد المحدّد لإتمام إجراءات الاستقبال.
        </div>
      </td></tr>

      <!-- Date / time -->
      <tr><td style="padding:32px 56px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="padding:18px 0;border-top:1px solid #d4ede9;border-bottom:1px solid #d4ede9;text-align:center;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>
                <td style="padding:0 28px;text-align:center;vertical-align:top;">
                  <div style="font-size:10px;letter-spacing:.36em;color:#0f766e;text-transform:uppercase;font-family:Arial,sans-serif;font-weight:500;">التاريخ</div>
                  <div style="margin-top:10px;font-size:18px;color:#1c1b18;font-weight:400;line-height:1.5;">$weekday</div>
                  <div style="font-size:15px;color:#5c574f;font-family:Arial,sans-serif;">$day $month $year</div>
                </td>
                <td style="padding:0;border-inline-start:1px solid #d4ede9;width:1px;">&nbsp;</td>
                <td style="padding:0 28px;text-align:center;vertical-align:top;">
                  <div style="font-size:10px;letter-spacing:.36em;color:#0f766e;text-transform:uppercase;font-family:Arial,sans-serif;font-weight:500;">الوقت</div>
                  <div style="margin-top:10px;font-size:22px;color:#1c1b18;font-weight:400;direction:ltr;display:inline-block;letter-spacing:.02em;">$time</div>
                  <div style="font-size:13px;color:#5c574f;font-family:Arial,sans-serif;letter-spacing:.1em;">$ampm</div>
                </td>
              </tr></table>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Details -->
      <tr><td style="padding:24px 56px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;">
          <tr>
            <td style="padding:14px 0;border-bottom:1px solid #e6f4f2;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="width:130px;font-size:11px;letter-spacing:.28em;color:#0f766e;text-transform:uppercase;font-weight:500;vertical-align:middle;">الخدمة</td>
                <td style="font-size:15px;color:#1c1b18;line-height:1.6;vertical-align:middle;">$svcs</td>
              </tr></table>
            </td>
          </tr>
          <tr>
            <td style="padding:14px 0;border-bottom:1px solid #e6f4f2;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="width:130px;font-size:11px;letter-spacing:.28em;color:#0f766e;text-transform:uppercase;font-weight:500;vertical-align:middle;">المعالج المختصّ</td>
                <td style="font-size:15px;color:#1c1b18;line-height:1.6;vertical-align:middle;">$ther</td>
              </tr></table>
            </td>
          </tr>
          <tr>
            <td style="padding:14px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="width:130px;font-size:11px;letter-spacing:.28em;color:#0f766e;text-transform:uppercase;font-weight:500;vertical-align:middle;">قاعة الاستقبال</td>
                <td style="font-size:15px;color:#1c1b18;line-height:1.6;vertical-align:middle;">$room</td>
              </tr></table>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Reschedule note -->
      <tr><td style="padding:32px 56px 8px;text-align:center;">
        <div style="height:1px;width:36px;background:#0d9488;margin:0 auto 22px;line-height:1px;font-size:0;">&nbsp;</div>
        <div style="font-family:Arial,sans-serif;font-size:14px;color:#5c574f;line-height:1.85;">
          لإعادة جدولة الموعد أو الاستفسار، يُسعدنا تواصلكم معنا قبل الموعد بـ 24 ساعة.
        </div>
      </td></tr>

      <!-- Contact -->
      <tr><td style="padding:28px 56px 48px;text-align:center;font-family:Arial,sans-serif;">
        <div style="font-size:11px;letter-spacing:.32em;color:#0f766e;text-transform:uppercase;font-weight:500;">للتواصل</div>
        <div style="margin-top:14px;font-size:18px;color:#1c1b18;direction:ltr;display:inline-block;letter-spacing:.05em;">
          <a href="tel:$phoneRaw" style="color:#1c1b18;text-decoration:none;">$phone</a>
        </div>
        <div style="margin-top:8px;font-size:14px;direction:ltr;display:inline-block;">
          <a href="mailto:$email" style="color:#0d9488;text-decoration:none;">$email</a>
        </div>
        <div style="margin-top:14px;font-size:14px;color:#5c574f;line-height:1.7;max-width:380px;margin-inline-start:auto;margin-inline-end:auto;">$address</div>
        <div style="margin-top:18px;font-size:13px;font-family:Arial,sans-serif;">$mapLink</div>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#1c1b18;padding:32px 48px;text-align:center;">
        <div style="font-size:11px;letter-spacing:.36em;color:#0d9488;text-transform:uppercase;font-family:Arial,sans-serif;font-weight:500;">$clinicEn</div>
        <div style="margin-top:14px;height:1px;width:32px;background:#0d9488;margin-inline-start:auto;margin-inline-end:auto;line-height:1px;font-size:0;">&nbsp;</div>
        <div style="margin-top:16px;font-size:12px;color:#94a3b8;font-family:Arial,sans-serif;line-height:1.85;">
          هذه رسالة آلية صادرة من نظام العيادة.<br>
          نشكر ثقتكم — مع تحيات فريق $clinic.
        </div>
      </td></tr>

      <!-- Bottom accent line -->
      <tr><td style="height:4px;background:#0d9488;line-height:4px;font-size:0;">&nbsp;</td></tr>

    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

