<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user info for sidebar
$stmtUser = $pdo->prepare("SELECT full_name, profile_image FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user = $stmtUser->fetch();
$profileImg = $user && $user['profile_image'] ? 'uploads/' . $user['profile_image'] : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? 'User') . '&background=1e40af&color=fff&size=128';

// Get current month and year for calendar
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$monthStart = date('Y-m-01', strtotime($date));
$monthEnd = date('Y-m-t', strtotime($date));

// Fetch completed tasks for the selected month
$completedTasksByDay = [];
$stmtMonth = $pdo->prepare("SELECT scheduled_date, title FROM tasks WHERE user_id = ? AND status = 'completed' AND scheduled_date BETWEEN ? AND ?");
$stmtMonth->execute([$_SESSION['user_id'], $monthStart, $monthEnd]);
foreach ($stmtMonth->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $completedTasksByDay[$row['scheduled_date']][] = $row['title'];
}

// Prepare calendar data
$firstDayOfMonth = date('w', strtotime($monthStart));
$daysInMonth = date('t', strtotime($monthStart));
$cells = [];
for ($i = 0; $i < $firstDayOfMonth; $i++) $cells[] = '';
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dayDate = date('Y-m-d', strtotime($monthStart . "+" . ($d-1) . " days"));
    $cells[] = [
        'day' => $d,
        'date' => $dayDate,
        'completed' => isset($completedTasksByDay[$dayDate]),
        'tasks' => $completedTasksByDay[$dayDate] ?? []
    ];
}
while (count($cells) % 7 !== 0) $cells[] = '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar - Web-based To-do list</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        /* Calendar specific styles */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
        }
        @media (max-width: 640px) {
            .calendar-grid {
                gap: 0.25rem;
            }
            .calendar-day {
                min-height: 2.5rem;
                font-size: 0.875rem;
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
                <div class="font-semibold text-base text-center mb-1"><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></div>
            </div>
            <nav class="flex-1 space-y-2 w-full">
                <a href="dashboard.php" class="nav-link flex items-center px-4 py-3 rounded-lg font-medium">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Dashboard
                </a>
                <a href="calendar.php" class="nav-link active flex items-center px-4 py-3 rounded-lg font-medium">
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
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <!-- Topbar -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                    <h1 class="text-2xl sm:text-3xl font-bold text-blue-900 tracking-tight">Calendar View</h1>
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

                <!-- Monthly Calendar UI -->
                <div class="bg-white/90 border border-blue-200 rounded-2xl shadow-xl p-4 sm:p-6 mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 gap-3">
                        <h3 class="text-lg sm:text-xl font-semibold text-gray-800">Monthly Overview</h3>
                        <div class="flex items-center gap-2">
                            <button onclick="changeMonth(-1)" class="p-2 rounded-full bg-blue-100 text-blue-700 hover:bg-blue-200 transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-blue-300">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <span class="text-lg sm:text-xl font-bold text-blue-900"><?php echo date('F Y', strtotime($date)); ?></span>
                            <button onclick="changeMonth(1)" class="p-2 rounded-full bg-blue-100 text-blue-700 hover:bg-blue-200 transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-blue-300">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Calendar Grid -->
                    <div class="overflow-x-auto">
                        <div class="min-w-[420px]">
                            <!-- Weekday Headers -->
                            <div class="grid grid-cols-7 gap-1 text-center text-xs sm:text-sm font-bold text-gray-600 mb-2">
                                <div>SUN</div><div>MON</div><div>TUE</div><div>WED</div><div>THU</div><div>FRI</div><div>SAT</div>
                            </div>
                            <!-- Calendar Days -->
                            <div class="grid grid-cols-7 gap-1">
                                <?php foreach ($cells as $cell): ?>
                                    <?php if (is_array($cell)): ?>
                                        <div class="relative min-h-[60px] sm:min-h-[80px] flex flex-col items-center justify-center p-1 cursor-default group <?php echo $cell['completed'] ? 'bg-green-50 border border-green-400 text-green-800' : 'bg-gray-50 text-gray-700'; ?> rounded-lg transition-all duration-150 hover:shadow-lg">
                                            <span class="text-sm sm:text-base font-semibold"><?php echo $cell['day']; ?></span>
                                            <?php if ($cell['completed']): ?>
                                                <span class="absolute bottom-1 text-[10px] sm:text-xs font-medium text-green-600">Completed</span>
                                                <div class="absolute z-10 left-1/2 -translate-x-1/2 top-full mt-1 w-36 sm:w-48 bg-white border border-green-300 rounded-lg shadow-xl p-2 text-xs text-left text-green-900 opacity-0 invisible group-hover:opacity-100 group-hover:visible pointer-events-none group-hover:pointer-events-auto transition-opacity duration-200 transform scale-95 group-hover:scale-100 origin-top">
                                                    <div class="font-bold mb-1 text-xs">Tasks Completed on <?php echo date('F j, Y', strtotime($cell['date'])); ?>:</div>
                                                    <ul class="list-disc pl-4 space-y-0.5">
                                                        <?php foreach ($cell['tasks'] as $taskTitle): ?>
                                                            <li><?php echo htmlspecialchars($taskTitle); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="min-h-[60px] sm:min-h-[80px]"></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="mt-4 flex items-center gap-2 text-xs sm:text-sm">
                        <span class="inline-block w-5 h-5 bg-green-100 border border-green-400 rounded-full flex items-center justify-center text-green-600">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        <span class="text-gray-700">Days with Completed Task(s)</span>
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

    function changeMonth(offset) {
        const current = new Date('<?php echo date('Y-m-01', strtotime($date)); ?>');
        current.setMonth(current.getMonth() + offset);
        const newDate = current.toISOString().slice(0, 10);
        window.location.href = `?date=${newDate}`;
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
                                <button onclick="markAsRead(${notif.id})" class="ml-2 text-xs text-blue-600 hover:text-blue-800">
                                    Mark as read
                                </button>
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

    function markAsRead(notificationId) {
        const formData = new FormData();
        if (notificationId) {
            formData.append('notification_id', notificationId);
        }
        
        fetch('mark_notification_read.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadNotifications(); // Reload notifications
                updateNotificationCount(); // Update badge count
            }
        });
    }

    document.getElementById('markAllReadBtn').addEventListener('click', function() {
        markAsRead(); // No notification ID means mark all as read
    });

    document.getElementById('notifBtn').addEventListener('click', function() {
        const dropdown = document.getElementById('notifDropdown');
        dropdown.classList.toggle('hidden');
        if (!dropdown.classList.contains('hidden')) {
            loadNotifications(); // Load notifications when dropdown is opened
        }
    });

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

    setInterval(updateNotificationCount, 30000);
    updateNotificationCount(); // Initial update

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