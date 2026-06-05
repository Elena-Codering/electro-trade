<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'redirect' => 'auth/login.php']);
    exit;
}

$user_id    = $_SESSION['user_id'];
$listing_id = (int)($_POST['listing_id'] ?? 0);
$action     = $_POST['action'] ?? 'toggle';

if (!$listing_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid listing.']);
    exit;
}

// Check if already in wishlist
$check = $pdo->prepare('
    SELECT wishlist_id FROM wishlist 
    WHERE user_id = :uid AND listing_id = :lid
');
$check->execute([':uid' => $user_id, ':lid' => $listing_id]);
$existing = $check->fetch();

if ($action === 'remove' || $existing) {
    // Remove from wishlist
    $pdo->prepare('
        DELETE FROM wishlist 
        WHERE user_id = :uid AND listing_id = :lid
    ')->execute([':uid' => $user_id, ':lid' => $listing_id]);

    echo json_encode([
        'success' => true,
        'action'  => 'removed',
        'message' => 'Removed from wishlist'
    ]);
} else {
    // Add to wishlist
    $pdo->prepare('
        INSERT IGNORE INTO wishlist (user_id, listing_id)
        VALUES (:uid, :lid)
    ')->execute([':uid' => $user_id, ':lid' => $listing_id]);

    echo json_encode([
        'success' => true,
        'action'  => 'added',
        'message' => 'Added to wishlist'
    ]);
}
?>