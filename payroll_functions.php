<?php
/**
 * Payroll helpers.
 *
 * Every function is wrapped in function_exists() so this file can never
 * collide with helpers defined elsewhere in the resort system.
 *
 * NOTE: e() (HTML escape) is NOT defined here any more - it already lives
 * in auth.php, and declaring it twice causes a fatal "cannot redeclare" error.
 */

if (!function_exists('currencySymbol')) {
    /** Currency symbol used across the payroll module. */
    function currencySymbol() {
        return '৳';
    }
}

if (!function_exists('formatCurrency')) {
    /** 2-decimal amount, no symbol. */
    function formatCurrency($amount) {
        return number_format((float) $amount, 2);
    }
}

if (!function_exists('money')) {
    /** Symbol + formatted amount, e.g. ৳12,500.00 */
    function money($amount) {
        return currencySymbol() . formatCurrency($amount);
    }
}

if (!function_exists('daysInMonth')) {
    function daysInMonth($month, $year) {
        return (int) date('t', strtotime(sprintf('%04d-%02d-01', (int) $year, (int) $month)));
    }
}

if (!function_exists('monthName')) {
    function monthName($month) {
        return date('F', mktime(0, 0, 0, (int) $month, 1));
    }
}

if (!function_exists('toTimeInput')) {
    /** HH:MM:SS -> HH:MM for <input type="time"> */
    function toTimeInput($value) {
        return $value ? substr($value, 0, 5) : '';
    }
}

if (!function_exists('isValidDate')) {
    /** True only for a real calendar date in Y-m-d format. */
    function isValidDate($date) {
        $dt = DateTime::createFromFormat('Y-m-d', (string) $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }
}

if (!function_exists('paymentBadge')) {
    /** Bootstrap badge for a payments.status value (null = no payment row). */
    function paymentBadge($status) {
        switch ($status) {
            case 'Paid':    return '<span class="badge bg-success">Paid</span>';
            case 'Partial': return '<span class="badge bg-warning text-dark">Partial</span>';
            case 'Pending': return '<span class="badge bg-info text-dark">Pending</span>';
            default:        return '<span class="badge bg-secondary">Not Paid</span>';
        }
    }
}

if (!function_exists('payrollDepartments')) {
    /** Fixed departments a designation can belong to. */
    function payrollDepartments() {
        return ['Hotel', 'Restaurant', 'Resort'];
    }
}

if (!function_exists('departmentBadge')) {
    function departmentBadge($department) {
        switch ($department) {
            case 'Hotel':      $cls = 'bg-primary-subtle text-primary-emphasis'; break;
            case 'Restaurant': $cls = 'bg-warning-subtle text-warning-emphasis'; break;
            case 'Resort':     $cls = 'bg-success-subtle text-success-emphasis'; break;
            default:           return '<span class="text-muted">—</span>';
        }
        return '<span class="badge ' . $cls . '">' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('recalcPayrollTotals')) {
    /**
     * Recompute payroll_payroll.amount_paid / amount_due from the full
     * payroll_payments transaction log for one payroll row, and persist it.
     *
     * payroll_payments is an append-only log (one row per transaction), so
     * "how much has been paid so far" is always SUM(amount_paid) across every
     * row for that payroll_id - this keeps payroll_payroll's running totals
     * in sync after every insert.
     *
     * Returns ['paid' => float, 'due' => float, 'status' => string].
     */
    function recalcPayrollTotals(mysqli $conn, int $payrollId): array {
        $stmt = $conn->prepare("SELECT calculated_salary FROM payroll_payroll WHERE id = ?");
        $stmt->bind_param('i', $payrollId);
        $stmt->execute();
        $payroll = $stmt->get_result()->fetch_assoc();
        $salary  = (float) ($payroll['calculated_salary'] ?? 0);

        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payroll_payments WHERE payroll_id = ?");
        $stmt->bind_param('i', $payrollId);
        $stmt->execute();
        $paid = (float) $stmt->get_result()->fetch_assoc()['total_paid'];

        $due = max(0, round($salary - $paid, 2));

        $stmt = $conn->prepare("UPDATE payroll_payroll SET amount_paid = ?, amount_due = ? WHERE id = ?");
        $stmt->bind_param('ddi', $paid, $due, $payrollId);
        $stmt->execute();

        if ($due <= 0.009) {
            $status = 'Paid';
        } elseif ($paid > 0.009) {
            $status = 'Partial';
        } else {
            $status = 'Pending';
        }

        return ['paid' => $paid, 'due' => $due, 'status' => $status];
    }
}

if (!function_exists('paymentStatusFromTotals')) {
    /** Derive a Paid/Partial/Pending/null label from running totals (null = no payment yet, row is untouched). */
    function paymentStatusFromTotals(float $paid, float $due): ?string {
        if ($paid <= 0.009) {
            return null; // no payment recorded at all yet
        }
        return $due <= 0.009 ? 'Paid' : 'Partial';
    }
}