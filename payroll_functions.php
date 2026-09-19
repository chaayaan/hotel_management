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
