<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header('Location: ../auth/login.php');
    exit;
}

$listing_id = (int)($_GET['id'] ?? 0);

if ($listing_id) {
    // Make sure this listing belongs to this seller
    $stmt = $pdo->prepare('
        UPDATE listings SET status = "deleted" 
        WHERE listing_id = :id AND seller_id = :sid
    ');
    $stmt->execute([':id' => $listing_id, ':sid' => $_SESSION['user_id']]);
}

header('Location: dashboard.php');
exit;
?>