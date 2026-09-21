<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';
require_once __DIR__ . '/payroll_attendance_calendar.php';

$page_title  = 'Employee daily history';
$active_menu = 'attendance_history';

/* ---------- Which employee / month ---------- */
$empId = (int) ($_GET['employee_id'] ?? 0);
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int) date('n');
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$stmt = $conn->prepare("
    SELECT e.id, e.name, e.status, d.name AS designation, d.department
    FROM payroll_employees e
    LEFT JOIN payroll_designations d ON d.id = e.designation_id
    WHERE e.id = ?
");
$stmt->bind_param('i', $empId);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();

if (!$emp) {
    flash_set('danger', 'Employee not found.');
    header('Location: attendance_history.php');
    exit;
}

/* ---------- Dropdown data ---------- */
$switch = $conn->prepare("SELECT id, name FROM payroll_employees WHERE status = 'Active' OR id = ? ORDER BY name");
$switch->bind_param('i', $empId);
$switch->execute();
$employees = $switch->get_result()->fetch_all(MYSQLI_ASSOC);

$currentYear = (int) date('Y');
$firstYear   = min($currentYear, attFirstYear($conn) ?? $currentYear);
$yearOptions = range($firstYear, $currentYear);
if (!in_array($year, $yearOptions, true)) { $yearOptions[] = $year; sort($yearOptions); }

/* ---------- Build one row per day ---------- */
$daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
$rec         = loadEmployeeMonthDetails($conn, $empId, $month, $year);
$today       = date('Y-m-d');

$counts = ['P' => 0, 'A' => 0, 'L' => 0];
$notRecorded = 0;
$weeklyOff = 0;
$totalMin = 0;
$workedDays = 0;
$rows  = [];
$codes = [];

for ($d = 1; $d <= $daysInMonth; $d++) {
    $date  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $ts    = strtotime($date);
    $dow   = (int) date('w', $ts);
    $r     = $rec[$d] ?? null;
    $code  = $r['code'] ?? '';
    $isWk  = in_array($dow, ATT_WEEKEND_DOW, true);
    $isFut = $date > $today;
    $isNow = $date === $today;

    $mins = ($code === 'P') ? attMinutes($r['in'] ?? null, $r['out'] ?? null) : null;
    if ($code !== '') {
        $codes[$d] = $code;
        $counts[$code]++;
    } elseif ($isWk) {
        $weeklyOff++;
    } elseif (!$isFut) {
        $notRecorded++;
    }
    if ($mins !== null) { $totalMin += $mins; $workedDays++; }

    $rows[] = compact('d', 'ts', 'code', 'r', 'isWk', 'isFut', 'isNow', 'mins');
}
$avgMin = $workedDays ? (int) round($totalMin / $workedDays) : null;

/* ---------- Print version: formal employee attendance record (A4 portrait, black & white) ---------- */
$printDoc = employeeDailyPrintDocument([
    'org'         => attResortDetails($conn),
    'emp'         => $emp,
    'month'       => $month,
    'year'        => $year,
    'rows'        => $rows,
    'counts'      => $counts,
    'notRecorded' => $notRecorded,
    'weeklyOff'   => $weeklyOff,
    'totalMin'    => $totalMin,
    'workedDays'  => $workedDays,
    'avgMin'      => $avgMin,
]);

/* ---------- Links ---------- */
$qs = fn(array $x) => '?' . http_build_query(['employee_id' => $empId] + $x);
$prevM = $month === 1  ? 12 : $month - 1;  $prevY = $month === 1  ? $year - 1 : $year;
$nextM = $month === 12 ? 1  : $month + 1;  $nextY = $month === 12 ? $year + 1 : $year;
$prevUrl = $qs(['month' => $prevM, 'year' => $prevY]);
$nextUrl = $qs(['month' => $nextM, 'year' => $nextY]);
$backUrl = 'attendance_history.php?' . http_build_query(['month' => $month, 'year' => $year]);

$initial   = function_exists('mb_substr') ? mb_strtoupper(mb_substr(trim($emp['name']), 0, 1)) : strtoupper(substr(trim($emp['name']), 0, 1));
$roleLine  = trim(($emp['designation'] ?? '') . ((!empty($emp['designation']) && !empty($emp['department'])) ? ' · ' : '') . ($emp['department'] ?? ''));
$mLabel    = monthName($month) . ' ' . $year;
$statusMap = ['P' => 'Present', 'A' => 'Absent', 'L' => 'Leave'];

require_once __DIR__ . '/navbar.php';
echo attendanceCalendarCss();
echo attendanceDailyCss();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <a href="<?= e($backUrl) ?>" class="btn btn-outline-secondary" title="Back to attendance history"><i class="bi bi-arrow-left me-1"></i>Attendance history</a>
    <select name="employee_id" class="form-select" style="width:auto;max-width:240px;" onchange="this.form.submit()">
      <?php foreach ($employees as $o): ?>
        <option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === $empId ? 'selected' : '' ?>><?= e($o['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="<?= e($prevUrl) ?>" class="btn btn-outline-secondary" title="Previous month"><i class="bi bi-chevron-left"></i></a>
    <select name="month" class="form-select" style="width:auto;" onchange="this.form.submit()">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= monthName($m) ?></option>
      <?php endfor; ?>
    </select>
    <select name="year" class="form-select" style="width:auto;" onchange="this.form.submit()">
      <?php foreach ($yearOptions as $y): ?>
        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <a href="<?= e($nextUrl) ?>" class="btn btn-outline-secondary" title="Next month"><i class="bi bi-chevron-right"></i></a>
    <noscript><button class="btn btn-outline-brand">Go</button></noscript>
  </form>
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <?= attendanceLegend() ?>
    <button type="button" class="btn btn-outline-secondary" onclick="printEmployeeRecord()" title="Print formal attendance record"><i class="bi bi-printer me-1"></i>Print</button>
  </div>
</div>

<?= attendanceDiagnostic(true) ?>

<div class="row g-3">
  <!-- ============ Date-wise table ============ -->
  <div class="col-lg-8">
    <div class="ed-card">
      <div class="ed-card-h">
        <span class="t"><?= e($mLabel) ?> &mdash; daily record</span>
        <span class="s"><?= $daysInMonth ?> days</span>
      </div>
      <div class="ed-scroll">
        <table class="ed-table">
          <thead>
            <tr><th>Date</th><th>Day</th><th>Status</th><th>Check-in</th><th>Check-out</th><th>Worked</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
                $cls = trim(($r['isWk'] ? 'wk ' : '') . ($r['isNow'] ? 'today ' : '') . ($r['isFut'] ? 'fut' : ''));
                $in  = $r['r']['in']  ?? null;
                $out = $r['r']['out'] ?? null;
            ?>
              <tr<?= $cls ? ' class="' . $cls . '"' : '' ?>>
                <td class="ed-date"><?= date('d M Y', $r['ts']) ?></td>
                <td class="ed-dow"><?= date('D', $r['ts']) ?></td>
                <td>
                  <?php if ($r['code']): ?>
                    <span class="att-badge att-<?= $r['code'] ?>"><?= $statusMap[$r['code']] ?></span>
                  <?php elseif ($r['isWk']): ?>
                    <span class="ed-off">Weekly off</span>
                  <?php elseif ($r['isFut']): ?>
                    <span class="ed-mute">&mdash;</span>
                  <?php else: ?>
                    <span class="ed-mute">No record</span>
                  <?php endif; ?>
                </td>
                <td class="ed-time"><?= $r['code'] === 'P' && $in ? e(attFmtTime($in)) : '<span class="ed-mute">&mdash;</span>' ?></td>
                <td class="ed-time">
                  <?php if ($r['code'] === 'P' && $out): ?>
                    <?= e(attFmtTime($out)) ?>
                  <?php elseif ($r['code'] === 'P' && $in && !$r['isNow'] && !$r['isFut']): ?>
                    <span class="ed-warn">Missing</span>
                  <?php else: ?>
                    <span class="ed-mute">&mdash;</span>
                  <?php endif; ?>
                </td>
                <td class="ed-hrs"><?= $r['mins'] !== null ? e(attFmtMinutes($r['mins'])) : '<span class="ed-mute">&mdash;</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2">Total</td>
              <td><?= $counts['P'] ?> present &middot; <?= $counts['A'] ?> absent &middot; <?= $counts['L'] ?> leave</td>
              <td colspan="2" class="text-end">Hours worked</td>
              <td class="ed-hrs"><?= $workedDays ? e(attFmtMinutes($totalMin)) : '&mdash;' ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="ed-note">Worked time = check-out minus check-in. A check-out earlier than the check-in is treated as an overnight shift. &ldquo;Missing&rdquo; means a past day marked Present with no check-out time.</div>
    </div>
  </div>

  <!-- ============ Profile + summary + calendar ============ -->
  <div class="col-lg-4">
    <div class="ed-card mb-3">
      <div class="ed-profile">
        <div class="av"><?= e($initial) ?></div>
        <div>
          <div class="nm"><?= e($emp['name']) ?></div>
          <?php if ($roleLine): ?><div class="rl"><?= e($roleLine) ?></div><?php endif; ?>
          <span class="ed-pill <?= $emp['status'] === 'Active' ? 'on' : 'off' ?>"><?= e($emp['status']) ?></span>
        </div>
      </div>
      <div class="ed-stats">
        <div class="ed-stat sP"><span class="n"><?= $counts['P'] ?></span><span class="l">Present</span></div>
        <div class="ed-stat sA"><span class="n"><?= $counts['A'] ?></span><span class="l">Absent</span></div>
        <div class="ed-stat sL"><span class="n"><?= $counts['L'] ?></span><span class="l">Leave</span></div>
        <div class="ed-stat"><span class="n"><?= $notRecorded ?></span><span class="l">Not recorded</span></div>
        <div class="ed-stat"><span class="n"><?= $workedDays ? e(attFmtMinutes($totalMin)) : '&mdash;' ?></span><span class="l">Total hours</span></div>
        <div class="ed-stat"><span class="n"><?= $avgMin !== null ? e(attFmtMinutes($avgMin)) : '&mdash;' ?></span><span class="l">Avg / day</span></div>
      </div>
    </div>

    <div class="ed-card">
      <div class="ed-card-h"><span class="t">Calendar</span><span class="s"><?= e($mLabel) ?></span></div>
      <div class="ed-cal"><?= renderAttendanceCalendar($codes, $month, $year) ?></div>
    </div>
  </div>
</div>

<script>
/* Prints the formal record (A4 portrait) via a hidden iframe, so the navbar/filters are left out. */
var ATT_PRINT_DOC = <?= json_encode($printDoc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
function printEmployeeRecord() {
  var f = document.createElement('iframe');
  f.setAttribute('aria-hidden', 'true');
  f.style.cssText = 'position:fixed;left:-9999px;top:0;width:900px;height:1200px;border:0;';
  document.body.appendChild(f);
  var w = f.contentWindow, d = w.document;
  d.open(); d.write(ATT_PRINT_DOC); d.close();
  var cleanup = function () { if (f.parentNode) f.parentNode.removeChild(f); };
  w.onafterprint = cleanup;
  setTimeout(function () { w.focus(); w.print(); }, 300);
  setTimeout(cleanup, 120000);   // safety net if afterprint never fires
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>