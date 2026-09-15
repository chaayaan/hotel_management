<?php
/**
 * Hotel Booking Helpers
 * Shared functions for reservation, check-in, checkout, extend, cancel logic.
 * Include after auth.php.
 */

/**
 * Check if a room is free for a given date range.
 * Excludes cancelled/checked_out bookings. Optionally excludes a specific booking id (for edits/extends).
 * Date range overlap rule: NOT (new_end <= existing_start OR new_start >= existing_end)
 */
function is_room_available($conn, $room_id, $from, $until, $exclude_booking_id = 0) {
    $room_id = (int)$room_id;
    $exclude_booking_id = (int)$exclude_booking_id;
    $from = mysqli_real_escape_string($conn, $from);
    $until = mysqli_real_escape_string($conn, $until);

    $sql = "SELECT id, reservation_no, reserved_from, reserved_until, status
            FROM hotel_bookings
            WHERE room_id = {$room_id}
              AND status NOT IN ('cancelled','checked_out','no_show')
              AND NOT (reserved_until <= '{$from}' OR reserved_from >= '{$until}')";
    if ($exclude_booking_id > 0) {
        $sql .= " AND id != {$exclude_booking_id}";
    }
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result); // conflicting booking
    }
    return false; // available
}

/**
 * Generate a unique reservation number, e.g. RES-20260916-0007
 */
function generate_reservation_no($conn) {
    $prefix = 'RES-' . date('Ymd') . '-';
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM hotel_bookings WHERE reservation_no LIKE ?");
    $like = $prefix . '%';
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    return $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

/**
 * Get full booking details with guest + room + room type joined.
 */
function get_booking_full($conn, $booking_id) {
    $booking_id = (int)$booking_id;
    $sql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone, g.email AS guest_email,
                   g.id_proof_type, g.id_proof_number, g.address AS guest_address,
                   r.room_number, r.floor, r.price_per_day AS room_current_price,
                   rt.name AS room_type_name
            FROM hotel_bookings b
            JOIN guests g ON g.id = b.guest_id
            JOIN rooms r ON r.id = b.room_id
            JOIN room_types rt ON rt.id = r.room_type_id
            WHERE b.id = {$booking_id}
            LIMIT 1";
    $result = mysqli_query($conn, $sql);
    return $result ? mysqli_fetch_assoc($result) : null;
}

/**
 * Sum of payments made for a booking (optionally filtered by payment_type).
 */
function get_booking_payments_total($conn, $booking_id, $payment_type = null) {
    $booking_id = (int)$booking_id;
    $sql = "SELECT COALESCE(SUM(amount),0) t FROM hotel_payments WHERE booking_id = {$booking_id}";
    if ($payment_type) {
        $payment_type = mysqli_real_escape_string($conn, $payment_type);
        $sql .= " AND payment_type = '{$payment_type}'";
    }
    $result = mysqli_query($conn, $sql);
    return (float)mysqli_fetch_assoc($result)['t'];
}

/**
 * Sum of all service charges (net of discount) for a booking.
 */
function get_booking_services_total($conn, $booking_id) {
    $booking_id = (int)$booking_id;
    $sql = "SELECT COALESCE(SUM(amount - discount),0) t FROM hotel_booking_services WHERE booking_id = {$booking_id}";
    $result = mysqli_query($conn, $sql);
    return (float)mysqli_fetch_assoc($result)['t'];
}

/**
 * Recalculate and persist a booking's total_amount based on room + extension + service charges, minus discount, plus tax.
 */
function recalc_booking_total($conn, $booking_id) {
    $booking_id = (int)$booking_id;
    $b = get_booking_full($conn, $booking_id);
    if (!$b) return false;

    $service_total = get_booking_services_total($conn, $booking_id);
    $subtotal = (float)$b['room_charge_total'] + (float)$b['extension_charge_total'] + $service_total;
    $total = $subtotal - (float)$b['discount'] + (float)$b['tax'];
    if ($total < 0) $total = 0;

    $stmt = mysqli_prepare($conn, "UPDATE hotel_bookings SET service_charge_total = ?, total_amount = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ddi', $service_total, $total, $booking_id);
    return mysqli_stmt_execute($stmt);
}

/**
 * Nights between two Y-m-d date strings.
 */
function calc_nights($from, $until) {
    $d1 = new DateTime($from);
    $d2 = new DateTime($until);
    $diff = $d1->diff($d2)->days;
    return max(0, (int)$diff);
}

/**
 * Human readable status badge class map (for consistent styling).
 */
function booking_status_badge($status) {
    $map = [
        'reserved'    => 'bg-warning-subtle text-warning-emphasis',
        'checked_in'  => 'bg-danger-subtle text-danger',
        'checked_out' => 'bg-secondary-subtle text-secondary',
        'cancelled'   => 'bg-dark-subtle text-dark',
        'no_show'     => 'bg-dark-subtle text-dark',
    ];
    return $map[$status] ?? 'bg-light text-dark';
}