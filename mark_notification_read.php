<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notification_id = $_POST['notification_id'] ?? null;
    
    if ($notification_id) {
        // Mark specific notification as read
        $stmt = $pdo->prepare("UPDATE notifications SET sent = 1 WHERE id = ? AND task_id IN (SELECT id FROM tasks WHERE user_id = ?)");
        $success = $stmt->execute([$notification_id, $_SESSION['user_id']]);
    } else {
        // Mark all notifications as read
        $stmt = $pdo->prepare("UPDATE notifications SET sent = 1 WHERE task_id IN (SELECT id FROM tasks WHERE user_id = ?)");
        $success = $stmt->execute([$_SESSION['user_id']]);
    }
    
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
} 