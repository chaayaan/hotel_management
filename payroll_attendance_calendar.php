<?php
/**
 * Payroll > Attendance calendar helpers
 * Shared by payroll_list.php (table grid: employees x days)
 * and payroll_salary_slip.php (month calendar for one employee).
 *
 * Codes: P = Present, A = Absent, L = Leave
 */

/*
 * Attendance table settings (matches hotel_pms_v2.payroll_attendance).
 * If you rename the table/columns, change them here. Set all four to ''
 * only if you want the code to auto-detect them instead.
 */
const ATT_TABLE    = 'payroll_attendance';   // attendance history table
const ATT_COL_EMP  = 'employee_id';          // employee id column
const ATT_COL_DATE = 'attendance_date';      // date column
const ATT_COL_STAT = 'status';               // status column (Present/Absent/Leave or P/A/L)
const ATT_COL_IN   = 'check_in';             // check-in time column  (optional, '' to disable)
const ATT_COL_OUT  = 'check_out';            // check-out time column (optional, '' to disable)

/* Weekly-off days, 0 = Sunday ... 6 = Saturday. Currently Friday + Saturday. */
const ATT_WEEKEND_DOW = [5, 6];

/** Last diagnostic message (shown above the grid when the calendar can't load). */
$GLOBALS['ATT_DIAG'] = '';

/** Find the attendance table + columns. Returns ['table','emp','date','stat'] or null. */
function attDetectSchema(mysqli $conn): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;

    // 1) manual override
    if (ATT_TABLE !== '' && ATT_COL_EMP !== '' && ATT_COL_DATE !== '' && ATT_COL_STAT !== '') {
        return $cache = ['table' => ATT_TABLE, 'emp' => ATT_COL_EMP, 'date' => ATT_COL_DATE, 'stat' => ATT_COL_STAT, 'in' => ATT_COL_IN, 'out' => ATT_COL_OUT];
    }

    try {
        // 2) candidate tables: name contains "attend" (prefer payroll_ ones)
        $tables = [];
        $res = $conn->query("SHOW TABLES");
        while ($r = $res->fetch_row()) {
            if (stripos($r[0], 'attend') !== false) $tables[] = $r[0];
        }
        if (!$tables) {
            $GLOBALS['ATT_DIAG'] = 'No table with "attend" in its name was found. Set ATT_TABLE (and the 3 column constants) at the top of payroll_attendance_calendar.php.';
            return $cache = null;
        }
        usort($tables, fn($x, $y) => (stripos($y, 'payroll') !== false) <=> (stripos($x, 'payroll') !== false));

        foreach ($tables as $t) {
            $cols = [];
            $cr = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $t) . "`");
            while ($c = $cr->fetch_assoc()) $cols[strtolower($c['Field'])] = $c;

            $emp = $date = $stat = null;
            foreach (['employee_id', 'emp_id', 'staff_id', 'employee'] as $n) if (isset($cols[$n])) { $emp = $cols[$n]['Field']; break; }
            foreach (['attendance_date', 'date', 'att_date', 'day', 'work_date', 'attendance_day'] as $n) if (isset($cols[$n])) { $date = $cols[$n]['Field']; break; }
            foreach (['status', 'attendance_status', 'attendance', 'att_status', 'type', 'state'] as $n) if (isset($cols[$n])) { $stat = $cols[$n]['Field']; break; }
            // fallback: any DATE/DATETIME column
            if (!$date) foreach ($cols as $c) if (preg_match('/^(date|datetime|timestamp)/i', $c['Type'])) { $date = $c['Field']; break; }

            $tin = $tout = '';
            foreach (['check_in', 'checkin', 'check_in_time', 'in_time', 'time_in', 'clock_in'] as $n) if (isset($cols[$n])) { $tin = $cols[$n]['Field']; break; }
            foreach (['check_out', 'checkout', 'check_out_time', 'out_time', 'time_out', 'clock_out'] as $n) if (isset($cols[$n])) { $tout = $cols[$n]['Field']; break; }

            if ($emp && $date && $stat) {
                return $cache = ['table' => $t, 'emp' => $emp, 'date' => $date, 'stat' => $stat, 'in' => $tin, 'out' => $tout];
            }
            $GLOBALS['ATT_DIAG'] = 'Found table "' . $t . '" but could not identify its columns. Columns are: ' . implode(', ', array_column($cols, 'Field')) . '. Set the four ATT_* constants at the top of payroll_attendance_calendar.php.';
        }
    } catch (Throwable $ex) {
        $GLOBALS['ATT_DIAG'] = 'Schema detection failed: ' . $ex->getMessage();
    }
    return $cache = null;
}

/** Normalise whatever is stored in the status column to P / A / L (or '' if unknown). */
function attCode($status)
{
    $s = strtolower(trim((string) $status));
    if ($s === '') return '';
    if ($s === '1' || $s === 'yes') return 'P';
    if ($s === '0' || $s === 'no')  return 'A';
    if ($s[0] === 'p') return 'P';           // present, p
    if ($s[0] === 'a') return 'A';           // absent, a
    if ($s[0] === 'l') return 'L';           // leave, l
    return '';
}

/**
 * Returns [employee_id => [dayNumber => 'P'|'A'|'L']] for a month.
 * Pass $employeeIds = null for everyone, or an array of ids to limit.
 */
function loadMonthAttendance(mysqli $conn, int $month, int $year, ?array $employeeIds = null): array
{
    $out = [];
    $sc = attDetectSchema($conn);
    if (!$sc) return [];

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));
    $q = fn($n) => '`' . str_replace('`', '', $n) . '`';

    $sql = "SELECT {$q($sc['emp'])} AS emp, {$q($sc['date'])} AS d, {$q($sc['stat'])} AS st
            FROM {$q($sc['table'])}
            WHERE DATE({$q($sc['date'])}) BETWEEN ? AND ?";
    $types  = 'ss';
    $params = [$start, $end];

    if ($employeeIds !== null) {
        if (!$employeeIds) return [];
        $sql   .= " AND {$q($sc['emp'])} IN (" . implode(',', array_fill(0, count($employeeIds), '?')) . ")";
        $types .= str_repeat('i', count($employeeIds));
        $params = array_merge($params, array_map('intval', $employeeIds));
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rowsSeen = 0; $unknown = [];
        while ($r = $res->fetch_assoc()) {
            $rowsSeen++;
            $code = attCode($r['st']);
            if ($code !== '') {
                $out[(int) $r['emp']][(int) date('j', strtotime($r['d']))] = $code;
            } else {
                $unknown[(string) $r['st']] = true;
            }
        }
        if ($rowsSeen === 0) {
            $GLOBALS['ATT_DIAG'] = 'Using table "' . $sc['table'] . '" (' . $sc['emp'] . ', ' . $sc['date'] . ', ' . $sc['stat'] . '): no attendance rows for ' . date('F Y', strtotime($start)) . ' for these employees.';
        } elseif (!$out && $unknown) {
            $GLOBALS['ATT_DIAG'] = 'Rows found but status values are not recognised: ' . implode(', ', array_map(fn($v) => '"' . $v . '"', array_keys($unknown))) . '. Expected values starting with P / A / L (Present, Absent, Leave).';
        }
    } catch (Throwable $ex) {
        $GLOBALS['ATT_DIAG'] = 'Attendance query failed: ' . $ex->getMessage();
    }
    return $out;
}

/**
 * One employee's attendance for a month WITH times.
 * Returns [dayNumber => ['code' => 'P'|'A'|'L'|'', 'in' => 'HH:MM:SS'|null, 'out' => 'HH:MM:SS'|null]]
 */
function loadEmployeeMonthDetails(mysqli $conn, int $employeeId, int $month, int $year): array
{
    $out = [];
    $sc = attDetectSchema($conn);
    if (!$sc) return [];
    $q = fn($n) => '`' . str_replace('`', '', $n) . '`';

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));
    $cols  = "{$q($sc['date'])} AS d, {$q($sc['stat'])} AS st, "
           . (!empty($sc['in'])  ? "{$q($sc['in'])} AS tin, "  : "NULL AS tin, ")
           . (!empty($sc['out']) ? "{$q($sc['out'])} AS tout"  : "NULL AS tout");
    try {
        $stmt = $conn->prepare("SELECT $cols FROM {$q($sc['table'])} WHERE {$q($sc['emp'])} = ? AND DATE({$q($sc['date'])}) BETWEEN ? AND ?");
        $stmt->bind_param('iss', $employeeId, $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[(int) date('j', strtotime($r['d']))] = ['code' => attCode($r['st']), 'in' => $r['tin'] ?: null, 'out' => $r['tout'] ?: null];
        }
    } catch (Throwable $ex) {
        $GLOBALS['ATT_DIAG'] = 'Attendance query failed: ' . $ex->getMessage();
    }
    return $out;
}

/** Minutes between check-in and check-out; a check-out earlier than check-in is treated as an overnight shift. */
function attMinutes(?string $in, ?string $out): ?int
{
    if (!$in || !$out) return null;
    $a = strtotime('1970-01-01 ' . substr($in, 0, 8));
    $b = strtotime('1970-01-01 ' . substr($out, 0, 8));
    if ($a === false || $b === false) return null;
    if ($b < $a) $b += 86400;
    return (int) round(($b - $a) / 60);
}

function attFmtMinutes(?int $m): string
{
    return $m === null ? '—' : intdiv($m, 60) . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

function attFmtTime(?string $t): string
{
    if (!$t) return '—';
    $ts = strtotime('1970-01-01 ' . substr($t, 0, 8));
    return $ts ? date('h:i A', $ts) : '—';
}

/** Earliest year that has attendance rows, or null. Used by the Attendance History year dropdown. */
function attFirstYear(mysqli $conn): ?int
{
    $sc = attDetectSchema($conn);
    if (!$sc) return null;
    $q = fn($n) => '`' . str_replace('`', '', $n) . '`';
    try {
        $r = $conn->query("SELECT MIN(YEAR({$q($sc['date'])})) AS y FROM {$q($sc['table'])}");
        if ($r && ($row = $r->fetch_assoc()) && $row['y']) return (int) $row['y'];
    } catch (Throwable $ex) { /* ignore */ }
    return null;
}

/** Shared CSS (screen + print). Output once per page.
 *  Palette matches the salary slip / payroll pages:
 *  brand #0f5132 / dark #1c3d2e, surface #f4f6f5, border #e8ebe9, muted #8a938e
 *  Present = green, Absent = pink, Leave = orange (solid blocks, high contrast). */
function attendanceCalendarCss(): string
{
    return <<<'CSS'
<style>
  :root {
    --att-brand:#0f5132; --att-dark:#1c3d2e; --att-surface:#f4f6f5; --att-border:#e8ebe9;
    --att-muted:#8a938e; --att-text:#1e2b23;
    --att-p-bg:#508c64; --att-p-fg:#ffffff;   /* Present: green  */
    --att-a-bg:#f8c0bc; --att-a-fg:#8f1d16;   /* Absent : pink   */
    --att-l-bg:#f0b868; --att-l-fg:#5c3b00;   /* Leave  : orange */
  }

  /* ---------- Shared chips ---------- */
  .att-chip { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; font-weight:800; font-size:.88rem; line-height:1; }
  .att-chip.sm { width:22px; height:22px; border-radius:5px; font-size:.7rem; }
  .att-chip.att-P { background:var(--att-p-bg); color:var(--att-p-fg); }
  .att-chip.att-A { background:var(--att-a-bg); color:var(--att-a-fg); }
  .att-chip.att-L { background:var(--att-l-bg); color:var(--att-l-fg); }

  .att-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:.75rem; align-items:center; color:#5b675f; }
  .att-legend .att-key { display:inline-flex; align-items:center; gap:6px; }

  /* ---------- Table grid (payroll list): employees x days ---------- */
  .att-toolbar { display:flex; justify-content:space-between; align-items:baseline; gap:10px; padding:14px 18px 10px; }
  .att-toolbar .att-title { font-weight:700; color:var(--att-dark); font-size:1rem; }
  .att-toolbar .att-sub   { color:var(--att-muted); font-size:.78rem; margin-left:8px; }
  .att-grid-wrap { overflow-x:auto; border-top:1px solid var(--att-border); }
  table.att-grid { border-collapse:separate; border-spacing:0; width:100%; min-width:1000px; font-size:.78rem; color:var(--att-text); }
  table.att-grid th, table.att-grid td { text-align:center; padding:0; border-bottom:1px solid var(--att-border); }

  table.att-grid thead th { background:var(--att-surface); color:#6c776f; font-weight:600; height:46px; min-width:32px; position:sticky; top:0; }
  table.att-grid thead th .att-dn { display:block; font-size:.78rem; color:var(--att-dark); font-weight:700; line-height:1.2; }
  table.att-grid thead th .att-dw { display:block; font-size:.6rem; text-transform:uppercase; letter-spacing:.04em; color:var(--att-muted); font-weight:600; }
  table.att-grid thead th.att-wkend { background:#eceff0; }
  table.att-grid thead th.att-today { box-shadow:inset 0 -3px 0 var(--att-brand); }
  table.att-grid thead th.att-today .att-dn { color:var(--att-brand); }
  table.att-grid thead th.att-emp { text-align:left; padding:0 18px; min-width:200px; text-transform:uppercase; letter-spacing:.05em; font-size:.68rem; color:#6c776f; }
  table.att-grid thead th.att-totcol { min-width:56px; }

  table.att-grid tbody td { height:44px; }
  table.att-grid tbody td.att-wkend { background:#fafbfa; }
  table.att-grid tbody td.att-today { background:#f1f8f4; }
  table.att-grid tbody tr:hover td { background:#f7faf8; }
  table.att-grid tbody tr:last-child td { border-bottom:none; }
  table.att-grid td.att-name { text-align:left; padding:0 18px; font-weight:600; white-space:nowrap; background:#fff; position:sticky; left:0; z-index:1; }
  table.att-grid thead th.att-emp { position:sticky; left:0; z-index:3; }
  table.att-grid td.att-name .att-who { display:flex; align-items:center; }
  table.att-grid td.att-name .att-nm { display:flex; flex-direction:column; line-height:1.25; }
  table.att-grid td.att-name .att-role { font-size:.68rem; color:var(--att-muted); font-weight:500; }
  table.att-grid td.att-name a.att-link { color:inherit; text-decoration:none; }
  table.att-grid td.att-name a.att-link:hover .n { color:var(--att-brand); text-decoration:underline; }
  table.att-grid td.att-name a.att-link:hover .att-av { background:var(--att-p-bg); color:#fff; }
  table.att-grid td.att-name .att-av { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:#eef2f0; color:var(--att-dark); font-size:.72rem; font-weight:700; margin-right:10px; flex-shrink:0; }
  table.att-grid tbody tr:hover td.att-name { background:#f7faf8; }

  /* totals: solid coloured blocks (header letter + row number) */
  table.att-grid thead th.tP, table.att-grid td.att-tot.tP { background:var(--att-p-bg); color:var(--att-p-fg); }
  table.att-grid thead th.tA, table.att-grid td.att-tot.tA { background:var(--att-a-bg); color:var(--att-a-fg); }
  table.att-grid thead th.tL, table.att-grid td.att-tot.tL { background:var(--att-l-bg); color:var(--att-l-fg); }
  table.att-grid thead th.att-totcol { font-weight:800; font-size:.92rem; border-left:none; }
  table.att-grid td.att-tot { font-weight:800; font-size:1rem; min-width:56px; border-bottom:1px solid rgba(255,255,255,.55); }
  table.att-grid td.att-tot.zero { opacity:.5; }

  /* ---------- Month calendar (salary slip) ---------- */
  .att-cal-wrap { border:1px solid var(--att-border); border-radius:10px; overflow:hidden; }
  table.att-cal { width:100%; border-collapse:collapse; table-layout:fixed; }
  table.att-cal th { background:var(--att-surface); color:#6c776f; font-size:.58rem; font-weight:700; padding:6px 0; text-align:center; letter-spacing:.05em; border-bottom:1px solid var(--att-border); }
  table.att-cal th.att-wk-end { color:var(--att-muted); }
  table.att-cal td { border-right:1px solid #f0f2f1; border-bottom:1px solid #f0f2f1; height:42px; vertical-align:top; padding:3px 4px; background:#fff; }
  table.att-cal td:last-child { border-right:none; }
  table.att-cal tr:last-child td { border-bottom:none; }
  table.att-cal td.att-empty { background:#fafbfa; }
  table.att-cal td .att-daynum { font-size:.58rem; color:var(--att-muted); display:block; line-height:1; }
  table.att-cal td .att-chip { display:flex; margin:2px auto 0; width:24px; height:24px; border-radius:5px; font-size:.78rem; }
  table.att-cal td.att-today-cell { box-shadow:inset 0 0 0 2px var(--att-brand); }

  .att-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; text-align:center; margin-top:8px; }
  .att-summary div { border-radius:8px; padding:6px 2px; }
  .att-summary .n { display:block; font-weight:800; font-size:.95rem; }
  .att-summary .l { display:block; font-size:.58rem; text-transform:uppercase; letter-spacing:.04em; opacity:.9; font-weight:600; }
  .att-summary.lg { gap:10px; }
  .att-summary.lg div { padding:12px 6px; border-radius:10px; }
  .att-summary.lg .n { font-size:1.45rem; }
  .att-summary.lg .l { font-size:.66rem; margin-top:2px; }
  .att-summary .s-T { background:#eef2f0; color:var(--att-dark); }
  .att-summary .s-P { background:var(--att-p-bg); color:var(--att-p-fg); }
  .att-summary .s-A { background:var(--att-a-bg); color:var(--att-a-fg); }
  .att-summary .s-L { background:var(--att-l-bg); color:var(--att-l-fg); }

  @media print {
    .att-chip, table.att-grid th, table.att-grid td, table.att-cal th, table.att-cal td, .att-summary div, .att-cal-wrap {
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
  }
</style>
CSS;
}

function attendanceLegend(): string
{
    return '<div class="att-legend">'
         . '<span class="att-key"><span class="att-chip sm att-P">P</span>Present</span>'
         . '<span class="att-key"><span class="att-chip sm att-A">A</span>Absent</span>'
         . '<span class="att-key"><span class="att-chip sm att-L">L</span>Leave</span>'
         . '</div>';
}

/**
 * Count P/A/L from a [day => code] map.
 */
function attendanceCounts(array $days): array
{
    $c = ['P' => 0, 'A' => 0, 'L' => 0];
    foreach ($days as $code) {
        if (isset($c[$code])) $c[$code]++;
    }
    return $c;
}

/**
 * TABLE GRID for the payroll list page.
 * $people = [ ['id'=>..,'name'=>..], ... ]   $att = loadMonthAttendance() result
 */
function renderAttendanceGrid(array $people, array $att, int $month, int $year): string
{
    $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $isCurrent   = ($month === (int) date('n') && $year === (int) date('Y'));
    $today       = (int) date('j');
    $dw          = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    $h  = '<div class="att-toolbar"><div><span class="att-title">' . e(monthName($month) . ' ' . $year) . '</span>'
        . '<span class="att-sub">Daily attendance &middot; ' . count($people) . ' employee' . (count($people) === 1 ? '' : 's') . '</span></div></div>';
    $h .= '<div class="att-grid-wrap"><table class="att-grid"><thead><tr><th class="att-emp">Employee</th>';

    $weekend = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $w = (int) date('w', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $d)));
        $weekend[$d] = in_array($w, ATT_WEEKEND_DOW, true);
        $cls = trim(($weekend[$d] ? 'att-wkend ' : '') . (($isCurrent && $d === $today) ? 'att-today' : ''));
        $h .= '<th' . ($cls ? ' class="' . $cls . '"' : '') . '><span class="att-dn">' . $d . '</span><span class="att-dw">' . $dw[$w] . '</span></th>';
    }
    $h .= '<th class="att-totcol tP">P</th><th class="att-totcol tA">A</th><th class="att-totcol tL">L</th></tr></thead><tbody>';

    foreach ($people as $p) {
        $days = $att[(int) $p['id']] ?? [];
        $c = attendanceCounts($days);
        $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr(trim((string) $p['name']), 0, 1)) : strtoupper(substr(trim((string) $p['name']), 0, 1));
        $who = '<span class="att-av">' . e($initial) . '</span><span class="att-nm"><span class="n">' . e($p['name']) . '</span>'
             . (!empty($p['sub']) ? '<span class="att-role">' . e($p['sub']) . '</span>' : '') . '</span>';
        $h .= '<tr><td class="att-name">'
            . (!empty($p['url']) ? '<a class="att-who att-link" href="' . e($p['url']) . '" title="View daily check-in / check-out history">' . $who . '</a>'
                                 : '<div class="att-who">' . $who . '</div>')
            . '</td>';
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $code = $days[$d] ?? '';
            $cls = trim(($weekend[$d] ? 'att-wkend ' : '') . (($isCurrent && $d === $today) ? 'att-today' : ''));
            $h .= '<td' . ($cls ? ' class="' . $cls . '"' : '') . '>' . ($code ? '<span class="att-chip att-' . $code . '">' . $code . '</span>' : '') . '</td>';
        }
        foreach (['P', 'A', 'L'] as $k) {
            $h .= '<td class="att-tot t' . $k . ($c[$k] === 0 ? ' zero' : '') . '">' . $c[$k] . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/**
 * MONTH CALENDAR for one employee (salary slip).
 * $days = [dayNumber => 'P'|'A'|'L']
 * Weeks run Sun..Sat.
 */
function renderAttendanceCalendar(array $days, int $month, int $year): string
{
    $first       = strtotime(sprintf('%04d-%02d-01', $year, $month));
    $daysInMonth = (int) date('t', $first);
    $startDow    = (int) date('w', $first);   // 0 = Sunday
    $names       = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
    $isCurrent   = ($month === (int) date('n') && $year === (int) date('Y'));
    $today       = (int) date('j');

    $h = '<div class="att-cal-wrap"><table class="att-cal"><thead><tr>';
    foreach ($names as $i => $n) {
        $h .= '<th class="' . (in_array($i, ATT_WEEKEND_DOW, true) ? 'att-wk-end' : '') . '">' . $n . '</th>';
    }
    $h .= '</tr></thead><tbody><tr>';

    for ($i = 0; $i < $startDow; $i++) $h .= '<td class="att-empty"></td>';

    $col = $startDow;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $code = $days[$d] ?? '';
        $h .= '<td' . (($isCurrent && $d === $today) ? ' class="att-today-cell"' : '') . '>'
            . '<span class="att-daynum">' . $d . '</span>'
            . ($code ? '<span class="att-chip att-' . $code . '">' . $code . '</span>' : '') . '</td>';
        $col++;
        if ($col === 7 && $d < $daysInMonth) { $h .= '</tr><tr>'; $col = 0; }
    }
    while ($col > 0 && $col < 7) { $h .= '<td class="att-empty"></td>'; $col++; }

    return $h . '</tr></tbody></table></div>';
}

/** Total / P / A / L summary tiles. */
function renderAttendanceSummary(int $total, int $p, int $a, int $l): string
{
    return '<div class="att-summary">'
         . '<div class="s-T"><span class="n">' . $total . '</span><span class="l">Total</span></div>'
         . '<div class="s-P"><span class="n">' . $p . '</span><span class="l">Present</span></div>'
         . '<div class="s-A"><span class="n">' . $a . '</span><span class="l">Absent</span></div>'
         . '<div class="s-L"><span class="n">' . $l . '</span><span class="l">Leave</span></div>'
         . '</div>';
}

/** Resort name / contact for printed headers (same `settings` table the salary slip reads). */
function attResortDetails(mysqli $conn): array
{
    $st = [];
    try {
        $res = $conn->query("SELECT setting_key, setting_value FROM settings");
        while ($res && ($row = $res->fetch_assoc())) $st[$row['setting_key']] = $row['setting_value'];
    } catch (Throwable $ex) { /* settings table missing: use defaults */ }
    return [
        'name'    => $st['resort_name'] ?? 'Resort',
        'address' => $st['resort_address'] ?? '',
        'phone'   => $st['resort_phone'] ?? '',
        'website' => $st['resort_website'] ?? '',
    ];
}

/** "Friday / Saturday" text for the weekend note. */
function attWeekendNames(): string
{
    $n = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return implode(' / ', array_map(fn($d) => $n[$d], ATT_WEEKEND_DOW));
}

/**
 * FORMAL attendance sheet table (black & white, bordered) used for printing.
 * Columns: S/N | Name | Designation | 1..N | Total P / A / L, plus daily totals at the bottom.
 */
function renderAttendanceSheet(array $people, array $att, int $month, int $year): string
{
    $days = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $dw   = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    $wk = []; $lbl = [];
    for ($d = 1; $d <= $days; $d++) {
        $w = (int) date('w', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $d)));
        $wk[$d]  = in_array($w, ATT_WEEKEND_DOW, true);
        $lbl[$d] = $dw[$w];
    }

    $h  = '<table class="sheet"><colgroup><col class="k-sn"><col class="k-name"><col class="k-des">'
        . str_repeat('<col>', $days) . '<col class="k-tot"><col class="k-tot"><col class="k-tot"></colgroup>';
    $h .= '<thead><tr><th rowspan="2">S/N</th><th rowspan="2" class="l">Name of Employee</th><th rowspan="2" class="l">Designation</th>'
        . '<th colspan="' . $days . '">Date</th><th colspan="3">Total</th></tr><tr>';
    for ($d = 1; $d <= $days; $d++) {
        $h .= '<th class="dy' . ($wk[$d] ? ' wk' : '') . '"><span class="dn">' . $d . '</span><span class="dwn">' . $lbl[$d] . '</span></th>';
    }
    $h .= '<th class="tt">P</th><th class="tt">A</th><th class="tt">L</th></tr></thead><tbody>';

    $grand  = ['P' => 0, 'A' => 0, 'L' => 0];
    $perDay = [];
    for ($d = 1; $d <= $days; $d++) $perDay[$d] = ['P' => 0, 'A' => 0, 'L' => 0];

    $n = 0;
    foreach ($people as $p) {
        $n++;
        $rec = $att[(int) $p['id']] ?? [];
        $c   = attendanceCounts($rec);
        foreach ($c as $k => $v) $grand[$k] += $v;
        $h .= '<tr><td>' . $n . '</td><td class="l">' . e($p['name']) . '</td><td class="l">' . e($p['sub'] ?? '') . '</td>';
        for ($d = 1; $d <= $days; $d++) {
            $code = $rec[$d] ?? '';
            if ($code !== '') $perDay[$d][$code]++;
            $h .= '<td class="dy' . ($wk[$d] ? ' wk' : '') . ($code ? ' s' . $code : '') . '">' . $code . '</td>';
        }
        $h .= '<td class="tt">' . $c['P'] . '</td><td class="tt">' . $c['A'] . '</td><td class="tt">' . $c['L'] . '</td></tr>';
    }

    $h .= '</tbody><tfoot>';
    foreach (['P' => 'Total Present', 'A' => 'Total Absent', 'L' => 'Total Leave'] as $k => $label) {
        $h .= '<tr class="sum"><td colspan="3" class="r">' . $label . '</td>';
        for ($d = 1; $d <= $days; $d++) {
            $cnt = $perDay[$d][$k];
            $has = array_sum($perDay[$d]) > 0;
            $h .= '<td class="dy' . ($wk[$d] ? ' wk' : '') . '">' . ($cnt > 0 ? $cnt : ($has ? '0' : '')) . '</td>';
        }
        $h .= '<td colspan="3">' . $grand[$k] . '</td></tr>';
    }
    return $h . '</tfoot></table>';
}

/* ------------------------------------------------------------------
 * Shared building blocks for the FORMAL printouts (black & white, bordered)
 * ------------------------------------------------------------------ */

/** CSS shared by every formal printout (header, info block, key, signatures). */
function attPrintBaseCss(): string
{
    return <<<'CSS'
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  html, body { margin:0; padding:0; background:#fff; }
  body { font-family: Arial, Helvetica, sans-serif; color:#000; }

  .hd { text-align:center; }
  .org { font-family: Georgia, "Times New Roman", serif; font-size:21px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; line-height:1.15; }
  .org-sub { font-size:9.5px; color:#222; margin-top:3px; }
  .ttl { margin:8px 0 0; padding:4px 0; border-top:1.5px solid #000; border-bottom:1.5px solid #000;
         font-family: Georgia, "Times New Roman", serif; font-size:14.5px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; }
  .per { font-size:11px; font-weight:700; margin:5px 0 8px; }

  table.info { width:100%; border-collapse:collapse; margin-bottom:8px; font-size:9.5px; }
  table.info td { border:.6pt solid #000; padding:3px 7px; }
  table.info td.k { background:#ececec; font-weight:700; text-transform:uppercase; font-size:8px; letter-spacing:.05em; width:9%; white-space:nowrap; }
  table.info td.v { width:24.3%; font-weight:600; }
  table.info.c2 td.k { width:14%; }
  table.info.c2 td.v { width:36%; }

  .l { text-align:left !important; padding-left:5px !important; }
  .r { text-align:right !important; padding-right:6px !important; }

  .key { margin-top:8px; font-size:9px; }
  .key b { letter-spacing:.04em; }
  .sigs { display:flex; justify-content:space-between; gap:44px; margin-top:38px; break-inside:avoid; page-break-inside:avoid; }
  .sig { flex:1; text-align:center; font-size:9.5px; }
  .sig .ln  { border-top:.8pt solid #000; padding-top:4px; font-weight:700; }
  .sig .cap { font-size:8.5px; color:#333; margin-top:2px; }
  .note { margin-top:16px; font-size:8px; color:#444; text-align:center; }
CSS;
}

/** Centered letterhead: resort name/contact, form title and period line. */
function attPrintHeaderHtml(array $org, string $title, string $periodLine): string
{
    $sub = [];
    if (!empty($org['address'])) $sub[] = e($org['address']);
    $contact = trim(($org['phone'] ?? '') . ((!empty($org['phone']) && !empty($org['website'])) ? '  |  ' : '') . ($org['website'] ?? ''));
    if ($contact !== '') $sub[] = e($contact);

    return '<div class="hd"><div class="org">' . e($org['name']) . '</div>'
         . ($sub ? '<div class="org-sub">' . implode('<br>', $sub) . '</div>' : '')
         . '<div class="ttl">' . e($title) . '</div>'
         . '<div class="per">' . e($periodLine) . '</div></div>';
}

/** Label/value info block, $perRow pairs per row. */
function attPrintInfoHtml(array $info, int $perRow = 3): string
{
    $h = '<table class="info' . ($perRow === 2 ? ' c2' : '') . '">';
    foreach (array_chunk($info, $perRow, true) as $row) {
        $h .= '<tr>';
        $i = 0;
        foreach ($row as $k => $v) { $h .= '<td class="k">' . e($k) . '</td><td class="v">' . e($v) . '</td>'; $i++; }
        for (; $i < $perRow; $i++) $h .= '<td class="k"></td><td class="v"></td>';
        $h .= '</tr>';
    }
    return $h . '</table>';
}

/** Prepared by / Checked by / Approved by lines + computer-generated note. */
function attPrintSignaturesHtml(string $noteText): string
{
    return '<div class="sigs">'
         . '<div class="sig"><div class="ln">Prepared by</div><div class="cap">Name / Signature / Date</div></div>'
         . '<div class="sig"><div class="ln">Checked by</div><div class="cap">Name / Signature / Date</div></div>'
         . '<div class="sig"><div class="ln">Approved by</div><div class="cap">Name / Signature / Date</div></div>'
         . '</div>'
         . '<div class="note">' . e($noteText) . '</div>';
}

/**
 * Standalone HTML document for printing a FORMAL monthly attendance sheet (A4 landscape, black & white).
 * Used by attendance_history.php inside a hidden iframe, so the site navbar/filters never print.
 * $o keys: org[name,address,phone,website], month, year, people, att, dept, status ('Active'|'All'), search
 */
function attendancePrintDocument(array $o): string
{
    $month = (int) $o['month'];
    $year  = (int) $o['year'];
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', strtotime($first));
    $mName = monthName($month) . ' ' . $year;

    $info = [
        'Period'        => date('d M Y', strtotime($first)) . ' to ' . date('d M Y', strtotime($last)),
        'Department'    => ($o['dept'] ?? '') !== '' ? $o['dept'] : 'All departments',
        'Staff'         => ($o['status'] ?? 'Active') === 'All' ? 'All staff' : 'Active staff',
        'Employees'     => (string) count($o['people']),
        'Reference No.' => 'ATT-' . sprintf('%04d%02d', $year, $month),
        'Printed on'    => date('d M Y, H:i'),
    ];
    if (($o['search'] ?? '') !== '') $info['Search'] = '"' . $o['search'] . '"';

    $css = <<<'CSS'
<style>
  @page { size: A4 landscape; margin: 10mm 10mm 14mm 10mm;
          @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 8px Arial, sans-serif; color: #444; } }
CSS;
    $css .= attPrintBaseCss() . <<<'CSS'

  table.sheet { width:100%; border-collapse:collapse; table-layout:fixed; font-size:9px; }
  table.sheet col.k-sn   { width:26px; }
  table.sheet col.k-name { width:150px; }
  table.sheet col.k-des  { width:105px; }
  table.sheet col.k-tot  { width:27px; }
  table.sheet th, table.sheet td { border:.6pt solid #000; padding:0 2px; text-align:center; height:21px; overflow:hidden; }
  table.sheet thead { display:table-header-group; }
  table.sheet tfoot { display:table-row-group; }
  table.sheet tr { break-inside:avoid; page-break-inside:avoid; }
  table.sheet thead th { background:#d9d9d9; font-weight:700; font-size:8.5px; text-transform:uppercase; letter-spacing:.03em; height:auto; padding:3px 2px; }
  table.sheet thead th.dy { padding:2px 0; }
  table.sheet thead th.wk { background:#c4c4c4; }
  table.sheet thead th.tt { background:#cfcfcf; }
  .dn  { display:block; font-size:9px; letter-spacing:0; }
  .dwn { display:block; font-size:6px; font-weight:600; color:#333; letter-spacing:0; }
  td.wk { background:#e9e9e9; }
  td.sP { font-weight:400; }
  td.sA, td.sL { font-weight:700; }
  td.tt { font-weight:700; background:#f4f4f4; }
  tr.sum td { background:#f4f4f4; font-weight:700; font-size:8.5px; }
  tr.sum td.wk { background:#e2e2e2; }
  tr.sum:first-child td { border-top:1.5px solid #000; }
</style>
CSS;

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e('Attendance Sheet - ' . $mName) . '</title>' . $css . '</head><body>'
         . attPrintHeaderHtml($o['org'], 'Monthly Attendance Sheet', 'For the month of ' . $mName)
         . attPrintInfoHtml($info, 3)
         . renderAttendanceSheet($o['people'], $o['att'], $month, $year)
         . '<div class="key"><b>KEY:</b> &nbsp;P = Present &nbsp;&nbsp;|&nbsp;&nbsp; A = Absent &nbsp;&nbsp;|&nbsp;&nbsp; L = Leave &nbsp;&nbsp;|&nbsp;&nbsp; '
         . 'Shaded columns = weekly off (' . e(attWeekendNames()) . ') &nbsp;&nbsp;|&nbsp;&nbsp; Blank = no record</div>'
         . attPrintSignaturesHtml('This is a computer-generated attendance sheet.')
         . '</body></html>';
}

/**
 * FORMAL single-employee daily attendance record (A4 portrait, black & white):
 * date-wise status, check-in, check-out, worked hours, remarks + summary + signatures.
 * $o keys: org, emp[id,name,designation,department,status], month, year,
 *          rows (one per day: d, ts, code, r[in,out], isWk, isFut, isNow, mins),
 *          counts[P,A,L], notRecorded, weeklyOff, totalMin, workedDays, avgMin
 */
function employeeDailyPrintDocument(array $o): string
{
    $month = (int) $o['month'];
    $year  = (int) $o['year'];
    $emp   = $o['emp'];
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', strtotime($first));
    $mName = monthName($month) . ' ' . $year;
    $words = ['P' => 'Present', 'A' => 'Absent', 'L' => 'Leave'];
    $dash  = '&ndash;';

    $info = [
        'Employee'      => $emp['name'],
        'Employee ID'   => (string) $emp['id'],
        'Designation'   => $emp['designation'] ?: '-',
        'Department'    => $emp['department'] ?: '-',
        'Period'        => date('d M Y', strtotime($first)) . ' to ' . date('d M Y', strtotime($last)),
        'Status'        => $emp['status'],
        'Reference No.' => 'ATT-' . sprintf('%04d%02d', $year, $month) . '-' . str_pad((string) $emp['id'], 4, '0', STR_PAD_LEFT),
        'Printed on'    => date('d M Y, H:i'),
    ];

    $body = '';
    foreach ($o['rows'] as $r) {
        $code = $r['code'];
        $in   = $r['r']['in']  ?? null;
        $out  = $r['r']['out'] ?? null;
        $remark = '';
        if ($code === 'P') {
            if ($in && !$out && !$r['isNow'] && !$r['isFut']) $remark = 'Check-out missing';
            elseif ($in && $out && substr($out, 0, 8) < substr($in, 0, 8)) $remark = 'Overnight shift';
        } elseif (!$code && $r['isWk']) {
            $remark = 'Weekly off';
        } elseif (!$code && !$r['isFut']) {
            $remark = 'No record';
        }
        $body .= '<tr' . ($r['isWk'] ? ' class="wk"' : '') . '>'
            . '<td>' . e(date('d M Y', $r['ts'])) . '</td><td>' . e(date('D', $r['ts'])) . '</td>'
            . '<td class="' . ($code ? 's' . $code : '') . '">' . ($code ? $words[$code] : $dash) . '</td>'
            . '<td>' . ($code === 'P' && $in ? e(attFmtTime($in)) : $dash) . '</td>'
            . '<td>' . ($code === 'P' && $out ? e(attFmtTime($out)) : $dash) . '</td>'
            . '<td>' . ($r['mins'] !== null ? e(attFmtMinutes($r['mins'])) : $dash) . '</td>'
            . '<td class="rm">' . e($remark) . '</td></tr>';
    }

    $c = $o['counts'];
    $summary = '<div class="sh">Summary</div><table class="sum"><tr>'
        . '<th>Present</th><th>Absent</th><th>Leave</th><th>Weekly off</th><th>Not recorded</th><th>Total hours</th><th>Avg hours / day</th></tr><tr>'
        . '<td>' . $c['P'] . '</td><td>' . $c['A'] . '</td><td>' . $c['L'] . '</td><td>' . (int) $o['weeklyOff'] . '</td><td>' . (int) $o['notRecorded'] . '</td>'
        . '<td>' . ($o['workedDays'] ? e(attFmtMinutes($o['totalMin'])) : $dash) . '</td>'
        . '<td>' . ($o['avgMin'] !== null ? e(attFmtMinutes($o['avgMin'])) : $dash) . '</td></tr></table>';

    $css = <<<'CSS'
<style>
  @page { size: A4 portrait; margin: 10mm 12mm 14mm 12mm;
          @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 8px Arial, sans-serif; color: #444; } }
CSS;
    $css .= attPrintBaseCss() . <<<'CSS'

  .org { font-size:19px; }
  .sigs { gap:30px; margin-top:30px; }

  table.day { width:100%; border-collapse:collapse; table-layout:fixed; font-size:9.5px; }
  table.day col.c-date { width:74px; } table.day col.c-day { width:38px; } table.day col.c-st { width:66px; }
  table.day col.c-in { width:72px; } table.day col.c-out { width:78px; } table.day col.c-hr { width:64px; }
  table.day th, table.day td { border:.6pt solid #000; padding:0 6px; height:19px; text-align:center; overflow:hidden; }
  table.day thead { display:table-header-group; }
  table.day tfoot { display:table-row-group; }
  table.day tr { break-inside:avoid; page-break-inside:avoid; }
  table.day thead th { background:#d9d9d9; font-weight:700; font-size:8.5px; text-transform:uppercase; letter-spacing:.04em; height:auto; padding:4px 3px; white-space:nowrap; }
  table.day tr.wk td { background:#e9e9e9; }
  table.day td.sA, table.day td.sL { font-weight:700; }
  table.day td.rm { text-align:left; font-size:8.5px; color:#222; padding-left:7px; }
  table.day tfoot td { background:#f4f4f4; font-weight:700; }

  .sh { margin:10px 0 4px; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
  table.sum { width:100%; border-collapse:collapse; table-layout:fixed; font-size:10px; }
  table.sum th, table.sum td { border:.6pt solid #000; text-align:center; padding:3px 4px; }
  table.sum th { background:#d9d9d9; font-size:7.5px; text-transform:uppercase; letter-spacing:.04em; }
  table.sum td { font-weight:700; font-size:11px; height:22px; }
</style>
CSS;

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e('Attendance Record - ' . $emp['name'] . ' - ' . $mName) . '</title>' . $css . '</head><body>'
         . attPrintHeaderHtml($o['org'], 'Employee Attendance Record', 'For the month of ' . $mName)
         . attPrintInfoHtml($info, 2)
         . '<table class="day"><colgroup><col class="c-date"><col class="c-day"><col class="c-st"><col class="c-in"><col class="c-out"><col class="c-hr"><col></colgroup>'
         . '<thead><tr><th>Date</th><th>Day</th><th>Status</th><th>Check-in</th><th>Check-out</th><th>Worked</th><th class="l">Remarks</th></tr></thead>'
         . '<tbody>' . $body . '</tbody>'
         . '<tfoot><tr><td colspan="5" class="r">Total hours worked</td><td>' . ($o['workedDays'] ? e(attFmtMinutes($o['totalMin'])) : $dash) . '</td><td></td></tr></tfoot></table>'
         . $summary
         . '<div class="key"><b>KEY:</b> &nbsp;Shaded rows = weekly off (' . e(attWeekendNames()) . ') &nbsp;&nbsp;|&nbsp;&nbsp; '
         . 'Worked = check-out minus check-in; a check-out earlier than check-in is counted as an overnight shift</div>'
         . attPrintSignaturesHtml('This is a computer-generated attendance record.')
         . '</body></html>';
}

/** CSS for employee_daily_history.php (same palette as the attendance grid). Output after attendanceCalendarCss(). */
function attendanceDailyCss(): string
{
    return <<<'CSS'
<style>
  .ed-card { background:#fff; border:1px solid var(--att-border); border-radius:14px; overflow:hidden; }
  .ed-card-h { display:flex; justify-content:space-between; align-items:baseline; gap:10px; padding:14px 18px 10px; }
  .ed-card-h .t { font-weight:700; color:var(--att-dark); }
  .ed-card-h .s { color:var(--att-muted); font-size:.78rem; }
  .ed-scroll { overflow-x:auto; border-top:1px solid var(--att-border); }

  table.ed-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.86rem; color:var(--att-text); min-width:560px; }
  table.ed-table th { background:var(--att-surface); color:#6c776f; font-size:.66rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; padding:10px 14px; text-align:left; border-bottom:1px solid var(--att-border); white-space:nowrap; }
  table.ed-table td { padding:9px 14px; border-bottom:1px solid #f0f2f1; vertical-align:middle; white-space:nowrap; }
  table.ed-table tbody tr:hover td { background:#f7faf8; }
  table.ed-table tr.wk td { background:#fafbfa; }
  table.ed-table tr.today td { background:#f1f8f4; }
  table.ed-table tr.today td:first-child { box-shadow:inset 3px 0 0 var(--att-brand); }
  table.ed-table tr.fut td { color:#b5bcb8; }
  table.ed-table tfoot td { background:var(--att-surface); font-weight:700; border-bottom:none; border-top:1px solid var(--att-border); }
  .ed-date { font-weight:700; color:var(--att-dark); }
  .ed-dow  { color:var(--att-muted); }
  .ed-time { font-weight:600; font-variant-numeric:tabular-nums; }
  .ed-hrs  { font-weight:700; font-variant-numeric:tabular-nums; }
  .ed-mute { color:#b0b8b3; }
  .ed-off  { color:var(--att-muted); font-style:italic; font-size:.8rem; }
  .ed-warn { display:inline-block; color:#b3261e; background:#fdeceb; font-size:.68rem; font-weight:700; padding:2px 7px; border-radius:5px; }
  .att-badge { display:inline-block; min-width:74px; text-align:center; padding:3px 10px; border-radius:6px; font-weight:700; font-size:.74rem; }
  .att-badge.att-P { background:var(--att-p-bg); color:var(--att-p-fg); }
  .att-badge.att-A { background:var(--att-a-bg); color:var(--att-a-fg); }
  .att-badge.att-L { background:var(--att-l-bg); color:var(--att-l-fg); }

  .ed-profile { display:flex; align-items:center; gap:14px; padding:18px; }
  .ed-profile .av { width:54px; height:54px; border-radius:50%; background:#eef2f0; color:var(--att-dark); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.3rem; flex-shrink:0; }
  .ed-profile .nm { font-weight:800; color:var(--att-dark); font-size:1.05rem; line-height:1.2; }
  .ed-profile .rl { color:var(--att-muted); font-size:.8rem; margin-top:2px; }
  .ed-pill { display:inline-block; margin-top:6px; padding:2px 10px; border-radius:999px; font-size:.68rem; font-weight:700; }
  .ed-pill.on  { background:var(--att-p-bg); color:#fff; }
  .ed-pill.off { background:#e5e8e6; color:#5b675f; }

  .ed-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; padding:0 18px 18px; }
  .ed-stat { border-radius:10px; padding:11px 6px; text-align:center; background:#eef2f0; color:var(--att-dark); }
  .ed-stat .n { display:block; font-weight:800; font-size:1.3rem; line-height:1.1; }
  .ed-stat .l { display:block; font-size:.6rem; text-transform:uppercase; letter-spacing:.05em; font-weight:700; margin-top:3px; opacity:.85; }
  .ed-stat.sP { background:var(--att-p-bg); color:var(--att-p-fg); }
  .ed-stat.sA { background:var(--att-a-bg); color:var(--att-a-fg); }
  .ed-stat.sL { background:var(--att-l-bg); color:var(--att-l-fg); }
  .ed-note { padding:10px 18px 14px; color:var(--att-muted); font-size:.74rem; border-top:1px solid var(--att-border); }
  .ed-cal { padding:0 18px 16px; }
</style>
CSS;
}

/** Yellow notice explaining why the calendar is empty (returns '' when all is well). */
function attendanceDiagnostic(bool $hideEmptyMonth = false): string
{
    $m = $GLOBALS['ATT_DIAG'] ?? '';
    if ($hideEmptyMonth && strpos($m, 'no attendance rows') !== false) return '';
    return $m === '' ? '' : '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><strong>Attendance not loaded:</strong> ' . e($m) . '</div>';
}