<?php
session_start();
require_once '../config/db.php';

$allowedRoles = ['admin', 'finance'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$type     = $_GET['type'] ?? 'orders';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="electrotrade_' . $type . '_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

if ($type === 'orders') {
    fputcsv($out, ['Order ID','Item','Buyer','Seller','Amount','Status','Gateway','Date']);
    $rows = $pdo->prepare('
        SELECT o.order_id, l.title, 
               CONCAT(b.first_name," ",b.last_name) AS buyer,
               CONCAT(s.first_name," ",s.last_name) AS seller,
               o.total_amount, o.status, p.gateway, o.created_at
        FROM orders o
        JOIN listings l ON o.listing_id = l.listing_id
        JOIN users b ON o.buyer_id = b.user_id
        JOIN users s ON l.seller_id = s.user_id
        LEFT JOIN payments p ON p.order_id = o.order_id
        WHERE DATE(o.created_at) BETWEEN :from AND :to
        ORDER BY o.created_at DESC
    ');
    $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);

} elseif ($type === 'users') {
    fputcsv($out, ['User ID','First Name','Last Name','Email','Role','Status','Joined']);
    $rows = $pdo->prepare('
        SELECT user_id, first_name, last_name, email, role,
               IF(is_active,"Active","Suspended") AS status, created_at
        FROM users
        WHERE DATE(created_at) BETWEEN :from AND :to
        ORDER BY created_at DESC
    ');
    $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);

} elseif ($type === 'revenue') {
    fputcsv($out, ['Date','Total Orders','Completed Orders','Total Revenue']);
    $rows = $pdo->prepare('
        SELECT DATE(created_at) AS day,
               COUNT(*) AS total_orders,
               COUNT(CASE WHEN status="completed" THEN 1 END) AS completed,
               COALESCE(SUM(CASE WHEN status="completed" THEN total_amount END),0) AS revenue
        FROM orders
        WHERE DATE(created_at) BETWEEN :from AND :to
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ');
    $rows->execute([':from' => $dateFrom, ':to' => $dateTo]);
}

foreach ($rows->fetchAll() as $row) {
    fputcsv($out, $row);
}

fclose($out);
exit;
?>