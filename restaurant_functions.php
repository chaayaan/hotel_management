<?php
/**
 * Restaurant Module Helpers
 * Shared functions for orders, payments, and table status.
 * Include after auth.php.
 */

/**
 * Generate a unique order number, e.g. ORD-20260916-0007
 */
function generate_order_no($conn) {
    $prefix = 'ORD-' . date('Ymd') . '-';
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM restaurant_food_orders WHERE order_no LIKE ?");
    $like = $prefix . '%';
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    return $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

/**
 * Get a full order with table info joined.
 */
function get_order_full($conn, $order_id) {
    $order_id = (int)$order_id;
    $sql = "SELECT o.*, t.table_no, t.floor, t.table_type
            FROM restaurant_food_orders o
            JOIN restaurant_tables t ON t.id = o.table_id
            WHERE o.id = {$order_id}
            LIMIT 1";
    $result = mysqli_query($conn, $sql);
    return $result ? mysqli_fetch_assoc($result) : null;
}

/**
 * Get order items for a given order.
 */
function get_order_items($conn, $order_id) {
    $order_id = (int)$order_id;
    $items = [];
    $res = mysqli_query($conn, "SELECT * FROM restaurant_food_order_items WHERE order_id = {$order_id} ORDER BY id ASC");
    while ($row = mysqli_fetch_assoc($res)) $items[] = $row;
    return $items;
}

/**
 * Sum of payments made for an order.
 */
function get_order_payments_total($conn, $order_id) {
    $order_id = (int)$order_id;
    $sql = "SELECT COALESCE(SUM(amount),0) t FROM restaurant_payments WHERE order_id = {$order_id}";
    $result = mysqli_query($conn, $sql);
    return (float)mysqli_fetch_assoc($result)['t'];
}

/**
 * Recalculate and persist an order's totals based on its current items, tax, and discount.
 * Also updates total_paid / due_amount from the payments table.
 */
function recalc_order_total($conn, $order_id) {
    $order_id = (int)$order_id;
    $order = get_order_full($conn, $order_id);
    if (!$order) return false;

    $subtotal_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total),0) t FROM restaurant_food_order_items WHERE order_id = {$order_id}"));
    $subtotal = (float)$subtotal_row['t'];

    $tax = (float)$order['tax'];
    $discount = (float)$order['discount'];
    $total_amount = max(0, $subtotal - $discount + $tax);

    $total_paid = get_order_payments_total($conn, $order_id);
    $due_amount = max(0, $total_amount - $total_paid);

    $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_orders SET subtotal = ?, total_amount = ?, total_paid = ?, due_amount = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ddddi', $subtotal, $total_amount, $total_paid, $due_amount, $order_id);
    return mysqli_stmt_execute($stmt);
}

/**
 * Determine a table's live status based on its most relevant active order.
 * Returns: ['status' => 'available'|'hold'|'occupied', 'order' => array|null]
 */
function get_table_live_status($conn, $table_id) {
    $table_id = (int)$table_id;
    $sql = "SELECT * FROM restaurant_food_orders
            WHERE table_id = {$table_id} AND status IN ('hold','ordered')
            ORDER BY created_at DESC LIMIT 1";
    $order = mysqli_fetch_assoc(mysqli_query($conn, $sql));

    if (!$order) {
        return ['status' => 'available', 'order' => null];
    }
    if ($order['status'] === 'hold') {
        return ['status' => 'hold', 'order' => $order];
    }
    return ['status' => 'occupied', 'order' => $order];
}

/**
 * Human readable status badge class map for restaurant orders.
 */
function order_status_badge($status) {
    $map = [
        'hold'      => 'bg-warning-subtle text-warning-emphasis',
        'ordered'   => 'bg-danger-subtle text-danger',
        'paid'      => 'bg-success-subtle text-success',
        'cancelled' => 'bg-secondary-subtle text-secondary',
    ];
    return $map[$status] ?? 'bg-light text-dark';
}
