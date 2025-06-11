<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit();
}

// Get notifications for today
$stmt = $pdo->prepare("
    SELECT n.*, t.title, t.scheduled_date, t.status
    FROM notifications n
    JOIN tasks t ON n.task_id = t.id
    WHERE t.user_id = ? 
    AND DATE(n.notify_date) = CURDATE()
    AND n.sent = 0
    AND t.status = 'pending'
    ORDER BY t.scheduled_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Return both count and notifications
echo json_encode([
    'count' => count($notifications),
    'notifications' => $notifications
]); 