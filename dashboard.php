<?php
// Set PHP timezone to Asia/Manila to avoid date issues
ini_set('date.timezone', 'Asia/Manila');
date_default_timezone_set('Asia/Manila');

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
        error_log('Scheduled date: ' . $scheduled_date);
        error_log('PHP timezone: ' . date_default_timezone_get());
        $notify_type = 'same_day'; // Default value since dropdown is removed

        if (!empty($title) && !empty($scheduled_date)) {
            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, title, description, scheduled_date) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $title, $description, $scheduled_date])) {
                $task_id = $pdo->lastInsertId();
                // Always set notification for the due date
                $notify_date = $scheduled_date;
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
      AND DATE(n.notify_date) = ?
      AND n.sent = 0
      AND t.status = 'pending'
");
$stmtNotif->execute([
    $_SESSION['user_id'], 
    $date
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

// Fetch completed tasks for the selected month if monthly view
$completedTasksByDay = [];
if ($view_type === 'monthly') {
    $monthStart = date('Y-m-01', strtotime($date));
    $monthEnd = date('Y-m-t', strtotime($date));
    $stmtMonth = $pdo->prepare("SELECT scheduled_date, title FROM tasks WHERE user_id = ? AND status = 'completed' AND scheduled_date BETWEEN ? AND ?");
    $stmtMonth->execute([$_SESSION['user_id'], $monthStart, $monthEnd]);
    foreach ($stmtMonth->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $completedTasksByDay[$row['scheduled_date']][] = $row['title'];
    }
}
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
        .sidebar { 
            width: 220px; 
            background: linear-gradient(160deg, #2563eb 0%, #1e40af 100%); 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
            box-shadow: 2px 0 16px #0001; 
            position: fixed; 
            top: 0; 
            left: 0; 
            z-index: 40;
            transition: transform 0.3s ease-in-out;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .main-content {
                padding-left: 0 !important;
            }
        }
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
        @media (max-width: 640px) {
            .notif-dropdown {
                min-width: 280px;
                right: -10px;
            }
        }
    </style>
</head>
<body>
    <div>
        <!-- Mobile Menu Button -->
        <button id="mobileMenuBtn" class="md:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-blue-600 text-white">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>

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
                <a href="calendar.php" class="nav-link flex items-center px-4 py-3 rounded-lg font-medium">
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
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <!-- Topbar -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-10 relative">
                    <h1 class="text-2xl sm:text-3xl font-bold text-blue-900 tracking-tight">Dashboard</h1>
                    <div class="relative w-full sm:w-auto">
                        <button id="notifBtn" class="p-2 text-gray-600 hover:text-blue-600 focus:outline-none">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            <span id="notifBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">0</span>
                        </button>
                        <div id="notifDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <div class="p-4">
                                <div class="flex justify-between items-center mb-2">
                                    <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                                    <button id="markAllReadBtn" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Mark all as read</button>
                                </div>
                                <div id="notifList" class="space-y-2 max-h-96 overflow-y-auto">
                                    <!-- Notifications will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Stats -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-8 mb-10">
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
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mb-8">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                        <h2 class="text-xl font-semibold text-gray-800">Task Management</h2>
                        <button onclick="toggleTaskForm()" class="w-full sm:w-auto bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center">
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
                            <div class="flex justify-end">
                                <button type="submit"
                                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                    Create Task
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- View Controls -->
                    <div class="flex flex-col sm:flex-row flex-wrap items-start sm:items-center justify-between gap-4 mb-6">
                        <div class="flex flex-wrap gap-2">
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
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                            <input type="date" id="date_picker" value="<?php echo $date; ?>"
                                   class="w-full sm:w-auto px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
                    <div class="bg-gray-100 p-4 rounded-lg mb-4">
                        <h4 class="font-bold mb-2">Debug: Pending Tasks Array</h4>
                        <pre><?php print_r($pendingTasks); ?></pre>
                    </div>
                    <?php endif; ?>

                    <!-- Tasks Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
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
    // Add mobile menu functionality
    document.getElementById('mobileMenuBtn').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('open');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.querySelector('.sidebar');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 768 && 
            !sidebar.contains(event.target) && 
            !mobileMenuBtn.contains(event.target) && 
            sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });

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

    function showNotification(message, date = null, currentTaskNumber = null, totalTasks = null) {
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

        let taskCountText = '';
        if (currentTaskNumber !== null && totalTasks !== null && totalTasks > 1) {
            taskCountText = ` (${currentTaskNumber} of ${totalTasks})`;
        }
        
        notif.innerHTML = `
            <div class="flex items-center">
                <svg class="w-6 h-6 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <div>
                    <p class="font-semibold text-gray-900">Task Reminder${taskCountText}</p>
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
        fetch('get_notifications.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notifBadge');
                badge.textContent = data.count;
                if (data.count > 0) {
                    badge.classList.remove('hidden');
                    // Adjust size for larger numbers
                    if (data.count >= 10) {
                        badge.style.width = 'auto';
                        badge.style.minWidth = '24px';
                        badge.style.padding = '0 6px';
                    } else {
                        badge.style.width = '24px';
                        badge.style.minWidth = '24px';
                        badge.style.padding = '';
                    }
                } else {
                    badge.classList.add('hidden');
                }
            });
    }

    // Update notification count every 30 seconds
    setInterval(updateNotificationCount, 30000);
    updateNotificationCount(); // Initial update

    function markAsRead(notificationId = null) {
        let formData = new FormData();
        formData.append('user_id', <?php echo $_SESSION['user_id']; ?>);
        if (notificationId) {
            formData.append('notification_id', notificationId);
        } else {
            formData.append('mark_all', 'true');
        }

        fetch('mark_notification_read.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateNotificationCount();
                loadNotifications(); // Reload notifications after marking as read
            } else {
                console.error('Failed to mark notification(s) as read:', data.message);
            }
        })
        .catch(error => {
            console.error('Error marking notification(s) as read:', error);
        });
    }

    function loadNotifications() {
        fetch('get_notifications.php')
            .then(response => response.json())
            .then(data => {
                const notifList = document.getElementById('notifList');
                notifList.innerHTML = ''; // Clear existing notifications
                
                if (data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(notif => {
                        const notifElement = document.createElement('div');
                        notifElement.className = 'p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors';
                        notifElement.innerHTML = `
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">${notif.title}</p>
                                    <p class="text-xs text-gray-500 mt-1">Due: ${new Date(notif.scheduled_date).toLocaleDateString('en-US', {
                                        weekday: 'long',
                                        year: 'numeric',
                                        month: 'long',
                                        day: 'numeric'
                                    })}</p>
                                </div>
                                <button onclick="markAsRead(${notif.id})" class="text-xs text-blue-600 hover:text-blue-800 font-medium ml-2">Mark as read</button>
                            </div>
                        `;
                        notifList.appendChild(notifElement);
                    });
                } else {
                    notifList.innerHTML = `
                        <div class="text-center py-4 text-gray-500">
                            <p>No notifications</p>
                        </div>
                    `;
                }
            });
    }

    document.getElementById('notifBtn').addEventListener('click', function() {
        const dropdown = document.getElementById('notifDropdown');
        dropdown.classList.toggle('hidden');
        if (!dropdown.classList.contains('hidden')) {
            loadNotifications(); // Load notifications when dropdown is opened
        }
    });

    document.getElementById('markAllReadBtn').addEventListener('click', function() {
        markAsRead(); // Call markAsRead without an ID to mark all
    });

    // Show pending tasks on page load
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($pendingTasks)): ?>
            // Show a summary notification
            <?php if (count($pendingTasks) > 1): ?>
                showNotification('You have <?php echo count($pendingTasks); ?> pending tasks that need your attention!');
            <?php elseif (count($pendingTasks) === 1): ?>
                showNotification('You have 1 pending task that needs your attention!');
            <?php endif; ?>
            
            // Show individual task notifications with a delay
            <?php foreach ($pendingTasks as $index => $task): ?>
                setTimeout(() => {
                    showNotification(
                        '<?php echo htmlspecialchars($task['title']); ?>',
                        '<?php echo htmlspecialchars($task['scheduled_date']); ?>',
                        <?php echo $index + 1; ?>, // current task number (1-indexed)
                        <?php echo count($pendingTasks); ?> // total tasks
                    );
                }, <?php echo ($index + 1) * 6000; ?>);
            <?php endforeach; ?>
        <?php endif; ?>
        updateNotificationCount(); // Initial call to display badge on load
    });
    </script>
</body>
</html> 