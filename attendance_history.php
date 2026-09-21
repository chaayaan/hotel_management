<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';
require_once __DIR__ . '/payroll_attendance_calendar.php';

$page_title  = 'Attendance history';
$active_menu = 'attendance_history';

/* ---------- Filters (default: current month) ---------- */
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int) date('n');
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$dept         = trim((string) ($_GET['dept'] ?? ''));
$search       = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (($_GET['status'] ?? 'Active') === 'All') ? 'All' : 'Active';

/* ---------- Year dropdown: first attendance year -> current year (grows every year) ---------- */
$currentYear = (int) date('Y');
$firstYear   = min($currentYear, attFirstYear($conn) ?? $currentYear);
$yearOptions = range($firstYear, $currentYear);
if (!in_array($year, $yearOptions, true)) {          // keep a bookmarked year selectable
    $yearOptions[] = $year;
    sort($yearOptions);
}

/* ---------- Department list (from designations) ---------- */
$departments = [];
try {
    $dr = $conn->query("SELECT DISTINCT department FROM payroll_designations ORDER BY department");
    while ($dr && ($row = $dr->fetch_row())) $departments[] = $row[0];
} catch (Throwable $ex) { /* dropdown just stays empty */ }

/* ---------- Employees: everyone, whether or not payroll was generated ---------- */
$sql = "
    SELECT e.id, e.name, d.name AS designation, d.department
    FROM payroll_employees e
    LEFT JOIN payroll_designations d ON d.id = e.designation_id
    WHERE 1=1
";
$params = [];
$types  = '';
if ($statusFilter === 'Active') {
    $sql .= " AND e.status = 'Active'";
}
if ($dept !== '') {
    $sql     .= " AND d.department = ?";
    $params[] = $dept;
    $types   .= 's';
}
if ($search !== '') {
    $sql     .= " AND e.name LIKE ?";
    $params[] = '%' . $search . '%';
    $types   .= 's';
}
$sql .= " ORDER BY e.name ASC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$people = [];
foreach ($employees as $r) {
    $people[] = [
        'id'   => (int) $r['id'],
        'name' => $r['name'],
        'sub'  => (string) ($r['designation'] ?? ''),
        'url'  => 'employee_daily_history.php?' . http_build_query(['employee_id' => (int) $r['id'], 'month' => $month, 'year' => $year]),
    ];
}

/* ---------- Attendance for the month ---------- */
$att  = $people ? loadMonthAttendance($conn, $month, $year, array_column($people, 'id')) : [];
$grid = $people ? renderAttendanceGrid($people, $att, $month, $year) : '';

/* ---------- Print version: formal attendance sheet (A4 landscape, black & white) ---------- */
$printDoc = $people ? attendancePrintDocument([
    'org'    => attResortDetails($conn),
    'month'  => $month,
    'year'   => $year,
    'people' => $people,
    'att'    => $att,
    'dept'   => $dept,
    'status' => $statusFilter,
    'search' => $search,
]) : '';

/* ---------- Prev / next month links ---------- */
$baseQs = ['dept' => $dept, 'q' => $search, 'status' => $statusFilter];
$prevM  = $month === 1  ? 12 : $month - 1;  $prevY = $month === 1  ? $year - 1 : $year;
$nextM  = $month === 12 ? 1  : $month + 1;  $nextY = $month === 12 ? $year + 1 : $year;
$prevUrl = '?' . http_build_query($baseQs + ['month' => $prevM, 'year' => $prevY]);
$nextUrl = '?' . http_build_query($baseQs + ['month' => $nextM, 'year' => $nextY]);
$isDefault = ($month === (int) date('n') && $year === $currentYear && $dept === '' && $search === '' && $statusFilter === 'Active');

require_once __DIR__ . '/navbar.php';
echo attendanceCalendarCss();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <a href="<?= e($prevUrl) ?>" class="btn btn-outline-secondary" title="Previous month"><i class="bi bi-chevron-left"></i></a>
    <select name="month" class="form-select" style="width:auto;">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= monthName($m) ?></option>
      <?php endfor; ?>
    </select>
    <select name="year" class="form-select" style="width:auto;">
      <?php foreach ($yearOptions as $y): ?>
        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <a href="<?= e($nextUrl) ?>" class="btn btn-outline-secondary" title="Next month"><i class="bi bi-chevron-right"></i></a>

    <select name="dept" class="form-select" style="width:auto;">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= e($d) ?>" <?= $dept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:auto;">
      <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active staff</option>
      <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All staff</option>
    </select>
    <input type="text" name="q" class="form-control" style="width:170px;" placeholder="Search name" value="<?= e($search) ?>">
    <button class="btn btn-outline-brand">Filter</button>
    <?php if (!$isDefault): ?>
      <a href="attendance_history.php" class="btn btn-link text-muted">Current month</a>
    <?php endif; ?>
  </form>
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <?= attendanceLegend() ?>
    <button type="button" class="btn btn-outline-secondary" onclick="printAttendance()" title="Print formal attendance sheet" <?= $people ? '' : 'disabled' ?>><i class="bi bi-printer me-1"></i>Print</button>
  </div>
</div>

<?= attendanceDiagnostic(true) ?>

<?php if ($people): ?>
  <div class="card mb-3 overflow-hidden"><?= $grid ?></div>
<?php else: ?>
  <div class="card"><div class="card-body text-center text-muted py-5">No employees match this filter.</div></div>
<?php endif; ?>

<script>
/* Prints only the attendance grid (landscape) via a hidden iframe, so the navbar/filters are left out. */
var ATT_PRINT_DOC = <?= json_encode($printDoc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
function printAttendance() {
  if (!ATT_PRINT_DOC) return;
  var f = document.createElement('iframe');
  f.setAttribute('aria-hidden', 'true');
  f.style.cssText = 'position:fixed;left:-9999px;top:0;width:1200px;height:800px;border:0;';
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