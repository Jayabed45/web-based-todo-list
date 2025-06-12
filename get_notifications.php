<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit();
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications n
    JOIN tasks t ON n.task_id = t.id
    WHERE t.user_id = ? 
      AND (
          (DATE(n.notify_date) = CURDATE() AND n.type = 'same_day') OR
          (DATE(n.notify_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND n.type = '1_day_before')
      )
      AND n.sent = 0
");
$stmt->execute([$_SESSION['user_id']]);
$count = $stmt->fetchColumn();

echo json_encode(['count' => $count]); 