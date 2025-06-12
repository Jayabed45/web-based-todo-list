<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle profile image upload
$profileMsg = '';
if (isset($_POST['upload_profile'])) {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['profile_image']['tmp_name'];
        $fileName = basename($_FILES['profile_image']['name']);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($fileExt, $allowed)) {
            $newName = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $fileExt;
            $dest = 'uploads/' . $newName;
            if (!is_dir('uploads')) mkdir('uploads');
            if (move_uploaded_file($fileTmp, $dest)) {
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$newName, $_SESSION['user_id']]);
                $_SESSION['profile_image'] = $newName;
                $profileMsg = 'Profile image updated!';
            } else {
                $profileMsg = 'Failed to upload image.';
            }
        } else {
            $profileMsg = 'Invalid file type.';
        }
    } else {
        $profileMsg = 'No file selected.';
    }
}

// Get user info (including profile image)
$stmtUser = $pdo->prepare("SELECT full_name, profile_image FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user = $stmtUser->fetch();
$profileImg = $user && $user['profile_image'] ? 'uploads/' . $user['profile_image'] : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? 'User') . '&background=1e40af&color=fff&size=128';

// Handle task creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $scheduled_date = $_POST['scheduled_date'];
        $notify_type = $_POST['notify_type'];

        if (!empty($title) && !empty($scheduled_date)) {
            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, title, description, scheduled_date) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $title, $description, $scheduled_date])) {
                $task_id = $pdo->lastInsertId();
                
                // Create notification
                $notify_date = date('Y-m-d', strtotime($scheduled_date . ' -1 day'));
                if ($notify_type == 'same_day') {
                    $notify_date = $scheduled_date;
                }
                
                $stmt = $pdo->prepare("INSERT INTO notifications (task_id, notify_date, type) VALUES (?, ?, ?)");
                $stmt->execute([$task_id, $notify_date, $notify_type]);
            }
        }
    } elseif ($_POST['action'] == 'update_status') {
        $task_id = $_POST['task_id'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$status, $task_id, $_SESSION['user_id']]);
    }
}

// Get tasks based on view type
$view_type = isset($_GET['view']) ? $_GET['view'] : 'daily';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Fix the view logic with proper date handling
switch ($view_type) {
    case 'weekly':
        $start_date = date('Y-m-d', strtotime($date . ' -' . date('w', strtotime($date)) . ' days'));
        $end_date = date('Y-m-d', strtotime($start_date . ' +6 days'));
        $where_clause = "DATE(scheduled_date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
        break;
    case 'monthly':
        $start_date = date('Y-m-01', strtotime($date));
        $end_date = date('Y-m-t', strtotime($date));
        $where_clause = "DATE(scheduled_date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
        break;
    default: // daily
        $where_clause = "DATE(scheduled_date) = ?";
        $params = [$date];
}

// Debug information
$debug_info = [
    'view_type' => $view_type,
    'date' => $date,
    'where_clause' => $where_clause,
    'params' => $params,
    'sql' => "SELECT * FROM tasks WHERE user_id = ? AND $where_clause ORDER BY scheduled_date ASC"
];

// Modify the query to be more specific with DATE() function
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND $where_clause ORDER BY scheduled_date ASC");
$stmt->execute(array_merge([$_SESSION['user_id']], $params));
$tasks = $stmt->fetchAll();

// Get total tasks for the user
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ?");
$stmtTotal->execute([$_SESSION['user_id']]);
$totalTasks = $stmtTotal->fetchColumn();

// Get completed tasks for the user
$stmtCompleted = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'completed'");
$stmtCompleted->execute([$_SESSION['user_id']]);
$completedTasks = $stmtCompleted->fetchColumn();

// Get pending tasks for the user
$stmtPending = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'pending'");
$stmtPending->execute([$_SESSION['user_id']]);
$pendingTasks = $stmtPending->fetchColumn();

// Get notifications for the user
$stmtNotif = $pdo->prepare("
    SELECT n.*, t.title, t.scheduled_date, t.status
    FROM notifications n
    JOIN tasks t ON n.task_id = t.id
    WHERE t.user_id = ? 
      AND (
          (DATE(n.notify_date) = ? AND n.type = 'same_day') OR
          (DATE(n.notify_date) = ? AND n.type = '1_day_before')
      )
      AND n.sent = 0
      AND t.status = 'pending'
");
$stmtNotif->execute([
    $_SESSION['user_id'], 
    $date,  // For same day notifications
    date('Y-m-d', strtotime($date . ' +1 day'))  // For 1 day before notifications
]);
$notifications = $stmtNotif->fetchAll();
$notifCount = count($notifications);

// Check for pending tasks on login
$stmtPendingTasks = $pdo->prepare("
    SELECT title, scheduled_date 
    FROM tasks 
    WHERE user_id = ? 
    AND status = 'pending' 
    AND scheduled_date >= CURDATE()
    ORDER BY scheduled_date ASC
    LIMIT 5
");
$stmtPendingTasks->execute([$_SESSION['user_id']]);
$pendingTasks = $stmtPendingTasks->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Web-based To-do list</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sockjs-client/1.5.0/sockjs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/stomp.js/2.3.3/stomp.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f8fb; }
        .sidebar { width: 220px; background: linear-gradient(160deg, #2563eb 0%, #1e40af 100%); min-height: 100vh; display: flex; flex-direction: column; box-shadow: 2px 0 16px #0001; position: fixed; top: 0; left: 0; z-index: 40; }
        .sidebar .nav-link { color: #fff; opacity: 0.85; transition: 0.2s; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { background: #2563eb; opacity: 1; }
        .sidebar .profile-img { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 2px 12px #0002; transition: 0.2s; }
        .sidebar .profile-img:hover { box-shadow: 0 4px 24px #2563eb44; }
        .sidebar .logout { margin-top: auto; }
        .main-content { padding-left: 220px; min-height: 100vh; background: #f8fafc; }
        .card { background: #fff; border-radius: 1.25rem; box-shadow: 0 4px 24px #2563eb11; transition: 0.2s; }
        .card:hover { box-shadow: 0 8px 32px #2563eb22; }
        .stat-icon { width: 2.5rem; height: 2.5rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; }
        .scrollbar::-webkit-scrollbar { width: 8px; }
        .scrollbar::-webkit-scrollbar-thumb { background: #e0e7ef; border-radius: 8px; }
        .notif-dropdown { min-width: 320px; }
    </style>
</head>
<body>
    <div>
        <!-- Sidebar -->
        <aside class="sidebar p-5 text-white">
            <div class="flex flex-col items-center mb-8">
                <img src="<?php echo $profileImg; ?>" alt="Profile" class="profile-img mb-2 shadow-lg">
                <div class="font-semibold text-base text-center mb-1"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <form method="POST" enctype="multipart/form-data" class="mt-1 flex flex-col items-center">
                    <label class="text-xs text-blue-100 cursor-pointer hover:underline">
                        Change Photo
                        <input type="file" name="profile_image" accept="image/*" class="hidden" onchange="this.form.submit()">
                    </label>
                    <input type="hidden" name="upload_profile" value="1">
                </form>
                <?php if ($profileMsg): ?><div class="text-xs text-yellow-200 mt-1"><?php echo $profileMsg; ?></div><?php endif; ?>
            </div>
            <nav class="flex-1 space-y-2 w-full">
                <a href="#" class="nav-link active flex items-center px-4 py-3 rounded-lg font-medium">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Dashboard
                </a>
                <a href="#" class="nav-link flex items-center px-4 py-3 rounded-lg font-medium">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Calendar
                </a>
            </nav>
            <a href="logout.php" class="logout nav-link flex items-center px-4 py-3 rounded-lg font-medium mt-10">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H7a2 2 0 01-2-2V7a2 2 0 012-2h4a2 2 0 012 2v1"></path></svg>
                Logout
            </a>
        </aside>
        <!-- Main Content -->
        <main class="main-content min-h-screen bg-[#f8fafc] scrollbar">
            <div class="max-w-4xl mx-auto px-4 sm:px-8">
                <!-- Topbar with Notification Icon -->
                <div class="flex justify-between items-center mb-10 relative">
                    <h1 class="text-3xl font-bold text-blue-900 tracking-tight">Dashboard</h1>
                    <div class="relative">
                        <button id="notifBtn" class="relative focus:outline-none">
                            <!-- Bell Icon (Heroicons) -->
                            <svg class="w-7 h-7 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            <?php if ($notifCount > 0): ?>
                                <span class="absolute top-0 right-0 block w-3 h-3 rounded-full ring-2 ring-white bg-red-500"></span>
                            <?php endif; ?>
                        </button>
                        <!-- Dropdown -->
                        <div id="notifDropdown" class="notif-dropdown absolute right-0 mt-2 bg-white border border-blue-100 rounded-lg shadow-lg z-50 hidden">
                            <div class="p-4 border-b text-blue-900 font-semibold flex items-center">
                                <svg class="w-5 h-5 mr-2 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                                Notifications
                            </div>
                            <?php if ($notifCount > 0): ?>
                                <ul class="max-h-60 overflow-y-auto divide-y divide-blue-50">
                                    <?php foreach ($notifications as $notif): ?>
                                        <li class="px-4 py-3 text-sm text-blue-900">
                                            <span class="font-semibold"><?php echo htmlspecialchars($notif['title']); ?></span>
                                            is due <?php echo ($notif['type'] === '1_day_before') ? 'tomorrow' : 'today'; ?> (<?php echo date('F j, Y', strtotime($notif['scheduled_date'])); ?>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="px-4 py-6 text-center text-gray-400">No new notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <script>
                // Notification dropdown toggle
                document.addEventListener('DOMContentLoaded', function() {
                    const btn = document.getElementById('notifBtn');
                    const dropdown = document.getElementById('notifDropdown');
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        dropdown.classList.toggle('hidden');
                    });
                    document.addEventListener('click', function(e) {
                        if (!dropdown.classList.contains('hidden')) {
                            dropdown.classList.add('hidden');
                        }
                    });
                });
                </script>
                <?php 
                // Mark notifications as sent
                if ($notifCount > 0) {
                    $notifIds = array_column($notifications, 'id');
                    $in  = str_repeat('?,', count($notifIds) - 1) . '?';
                    $pdo->prepare("UPDATE notifications SET sent = 1 WHERE id IN ($in)")->execute($notifIds);
                }
                ?>
                <!-- Stats -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-10">
                    <div class="card p-7 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Total Tasks</p>
                            <div class="text-3xl font-bold text-blue-900"><?php echo $totalTasks; ?></div>
                        </div>
                        <div class="stat-icon bg-blue-100">
                            <svg class="w-7 h-7 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        </div>
                    </div>
                    <div class="card p-7 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Completed Tasks</p>
                            <div class="text-3xl font-bold text-blue-900"><?php echo $completedTasks; ?></div>
                        </div>
                        <div class="stat-icon bg-green-100">
                            <svg class="w-7 h-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                    </div>
                    <div class="card p-7 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Pending Tasks</p>
                            <div class="text-3xl font-bold text-blue-900"><?php echo count($pendingTasks); ?></div>
                        </div>
                        <div class="stat-icon bg-yellow-100">
                            <svg class="w-7 h-7 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    </div>
                </div>
                <!-- Task Management Section -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-8">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-semibold text-gray-800">Task Management</h2>
                        <button onclick="toggleTaskForm()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Add New Task
                        </button>
                    </div>

                    <!-- Task Creation Form -->
                    <div id="taskForm" class="hidden border-t pt-6 mt-6">
                        <form method="POST" action="" class="space-y-6">
                            <input type="hidden" name="action" value="create">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="title">Task Title</label>
                                    <input type="text" name="title" id="title" required
                                           class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="scheduled_date">Due Date</label>
                                    <input type="date" name="scheduled_date" id="scheduled_date" required
                                           class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2" for="description">Description</label>
                                <textarea name="description" id="description" rows="3"
                                          class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2" for="notify_type">Notification</label>
                                <select name="notify_type" id="notify_type"
                                        class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="1_day_before">1 Day Before</option>
                                    <option value="same_day">Same Day</option>
                                </select>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit"
                                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                    Create Task
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- View Controls -->
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <div class="flex space-x-3">
                            <button onclick="changeView('daily')" 
                                    class="px-4 py-2 rounded-lg font-medium transition-colors <?php echo $view_type === 'daily' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                                Daily
                            </button>
                            <button onclick="changeView('weekly')"
                                    class="px-4 py-2 rounded-lg font-medium transition-colors <?php echo $view_type === 'weekly' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                                Weekly
                            </button>
                            <button onclick="changeView('monthly')"
                                    class="px-4 py-2 rounded-lg font-medium transition-colors <?php echo $view_type === 'monthly' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                                Monthly
                            </button>
                        </div>
                        <div class="flex items-center space-x-2">
                            <input type="date" id="date_picker" value="<?php echo $date; ?>"
                                   class="px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   onchange="updateDate(this.value)">
                            <span class="text-sm text-gray-500">
                                <?php
                                if ($view_type === 'daily') {
                                    echo date('F j, Y', strtotime($date));
                                } elseif ($view_type === 'weekly') {
                                    echo date('F j', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date));
                                } else {
                                    echo date('F Y', strtotime($date));
                                }
                                ?>
                            </span>
                        </div>
                    </div>

                    <!-- Add debug information display (you can remove this in production) -->
                    <?php if (isset($_GET['debug'])): ?>
                    <div class="bg-gray-100 p-4 rounded-lg mb-4">
                        <pre><?php print_r($debug_info); ?></pre>
                    </div>
                    <?php endif; ?>

                    <!-- Tasks Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php if (empty($tasks)): ?>
                            <div class="col-span-full text-center py-12">
                                <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">No tasks found</h3>
                                <p class="text-gray-500 mb-4">Get started by creating your first task</p>
                                <button onclick="toggleTaskForm()" class="text-blue-600 hover:text-blue-700 font-medium">
                                    Create a task →
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($tasks as $task): ?>
                                <div class="task-card bg-white border border-gray-200 rounded-lg p-6 <?php echo $task['status'] == 'completed' ? 'opacity-75' : ''; ?>">
                                    <div class="flex justify-between items-start mb-4">
                                        <h3 class="text-lg font-semibold text-gray-900 <?php echo $task['status'] == 'completed' ? 'line-through' : ''; ?>">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                        </h3>
                                        <select class="status-select text-sm border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                                data-task-id="<?php echo $task['id']; ?>"
                                                onchange="updateTaskStatus(this)">
                                            <option value="pending" <?php echo $task['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="completed" <?php echo $task['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                    </div>
                                    <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($task['description']); ?></p>
                                    <div class="flex items-center text-sm text-gray-500">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <?php echo date('F j, Y', strtotime($task['scheduled_date'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
    function toggleTaskForm() {
        const form = document.getElementById('taskForm');
        form.classList.toggle('hidden');
    }
    function changeView(view) {
        const currentDate = document.getElementById('date_picker').value;
        window.location.href = `?view=${view}&date=${currentDate}`;
    }
    function updateDate(date) {
        const currentView = '<?php echo $view_type; ?>';
        window.location.href = `?view=${currentView}&date=${date}`;
    }
    function updateTaskStatus(select) {
        const taskId = select.dataset.taskId;
        const status = select.value;
        $.post('dashboard.php', {
            action: 'update_status',
            task_id: taskId,
            status: status
        }, function(response) {
            window.location.reload();
        });
    }

    // WebSocket connection
    const ws = new WebSocket('ws://localhost:8080');
    
    ws.onopen = function() {
        console.log('Connected to WebSocket server');
        // Authenticate the connection
        ws.send(JSON.stringify({
            type: 'auth',
            user_id: <?php echo $_SESSION['user_id']; ?>
        }));
    };

    ws.onmessage = function(e) {
        const data = JSON.parse(e.data);
        if (data.type === 'notification') {
            // Show notification
            showNotification(data.message);
            // Update notification count
            updateNotificationCount();
        }
    };

    ws.onclose = function() {
        console.log('Disconnected from WebSocket server');
        // Try to reconnect after 5 seconds
        setTimeout(() => {
            window.location.reload();
        }, 5000);
    };

    function showNotification(message, date = null) {
        // Create notification element
        const notif = document.createElement('div');
        notif.className = 'fixed top-4 right-4 bg-white p-4 rounded-lg shadow-lg z-50 transform transition-transform duration-300 translate-x-full';
        
        let dateStr = '';
        if (date) {
            try {
                const dateObj = new Date(date);
                if (!isNaN(dateObj.getTime())) {
                    dateStr = dateObj.toLocaleDateString('en-US', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                }
            } catch (e) {
                console.error('Date parsing error:', e);
            }
        }
        
        notif.innerHTML = `
            <div class="flex items-center">
                <svg class="w-6 h-6 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <div>
                    <p class="font-semibold text-gray-900">Task Reminder</p>
                    <p class="text-sm text-gray-600">${message}</p>
                    ${dateStr ? `<p class="text-xs text-blue-600 mt-1">Due: ${dateStr}</p>` : ''}
                </div>
            </div>
        `;
        document.body.appendChild(notif);

        // Animate in
        setTimeout(() => {
            notif.style.transform = 'translateX(0)';
        }, 100);

        // Remove after 5 seconds
        setTimeout(() => {
            notif.style.transform = 'translateX(full)';
            setTimeout(() => {
                notif.remove();
            }, 300);
        }, 5000);
    }

    function updateNotificationCount() {
        // Fetch latest notification count
        fetch('get_notifications.php')
            .then(response => response.json())
            .then(data => {
                const notifCount = data.count;
                const notifBadge = document.querySelector('#notifBtn .rounded-full');
                
                if (notifCount > 0) {
                    if (!notifBadge) {
                        const badge = document.createElement('span');
                        badge.className = 'absolute top-0 right-0 block w-3 h-3 rounded-full ring-2 ring-white bg-red-500';
                        document.querySelector('#notifBtn').appendChild(badge);
                    }
                } else {
                    notifBadge?.remove();
                }
            });
    }

    // Show pending tasks on page load
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($pendingTasks)): ?>
            // Show a summary notification
            showNotification('You have <?php echo count($pendingTasks); ?> pending task(s) that need your attention!');
            
            // Show individual task notifications with a delay
            <?php foreach ($pendingTasks as $index => $task): ?>
                setTimeout(() => {
                    showNotification(
                        '<?php echo htmlspecialchars($task['title']); ?>',
                        '<?php echo htmlspecialchars($task['scheduled_date']); ?>'
                    );
                }, <?php echo ($index + 1) * 6000; ?>);
            <?php endforeach; ?>
        <?php endif; ?>
    });
    </script>
</body>
</html> 