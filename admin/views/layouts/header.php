<?php
require_once __DIR__ . '/../../config/admin_auth.php';
$adminUser = AdminAuth::user();
$pageTitle = $pageTitle ?? 'Executive Dashboard';
$activeTab = $activeTab ?? 'dashboard';

// Fetch dynamic mirror for top status pill
$db = \App\Config\Database::getConnection();
$activeBaseUrl = 'https://new1.hdhub4u.af';
$liveHeartbeats = 0;
if ($db) {
    try {
        $stmt = $db->query("SELECT `key_value` FROM `system_config` WHERE `key_name` = 'hdhub4u_base_url' LIMIT 1");
        if ($stmt && $row = $stmt->fetch()) {
            $activeBaseUrl = $row['key_value'];
        }
        $hbStmt = $db->query("SELECT COUNT(*) FROM `app_heartbeats` WHERE `last_seen_at` >= (NOW() - INTERVAL 5 MINUTE)");
        if ($hbStmt) {
            $liveHeartbeats = (int)$hbStmt->fetchColumn();
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0B0D1B] antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — HDHub4u Engine Admin</title>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- HLS.js Video Engine -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>

    <!-- Typography (Plus Jakarta Sans) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
                        mono: ['SF Mono', 'ui-monospace', 'Menlo', 'Monaco', 'monospace']
                    },
                    colors: {
                        brand: {
                            red: '#E50914',
                            'red-dark': '#B80710',
                            'red-light': '#FF3B47',
                            navy: '#11142B',
                            'navy-dark': '#0B0D1B',
                            'navy-light': '#181C38',
                            card: '#131730',
                            border: '#23284C',
                            muted: '#7F87A7',
                            success: '#00C853',
                            warning: '#FFB300',
                            info: '#00B0FF'
                        }
                    },
                    backgroundImage: {
                        'brand-gradient': 'linear-gradient(135deg, #E50914 0%, #FF5252 100%)',
                        'card-gradient': 'linear-gradient(135deg, #131730 0%, #181C38 100%)',
                        'glow-gradient': 'radial-gradient(circle at 50% 0%, rgba(229, 9, 20, 0.15) 0%, transparent 75%)'
                    },
                    boxShadow: {
                        'brand-glow': '0 8px 24px -4px rgba(229, 9, 20, 0.45)',
                        'card-glow': '0 10px 30px -5px rgba(0, 0, 0, 0.5), 0 0 1px 1px rgba(255,255,255,0.05)',
                        'float-glow': '0 20px 40px -15px rgba(229, 9, 20, 0.2)'
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #0B0D1B;
            color: #FFFFFF;
            letter-spacing: -0.01em;
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #0B0D1B; }
        ::-webkit-scrollbar-thumb { background: #23284C; border-radius: 999px; }
        ::-webkit-scrollbar-thumb:hover { background: #E50914; }

        .glass-panel {
            background: rgba(19, 23, 48, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(35, 40, 76, 0.8);
        }

        .hover-lift {
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hover-lift:hover {
            transform: translateY(-2px);
        }

        .pulse-live {
            box-shadow: 0 0 0 0 rgba(0, 200, 83, 0.7);
            animation: pulse-green 2s infinite cubic-bezier(0.66, 0, 0, 1);
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 200, 83, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(0, 200, 83, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 200, 83, 0); }
        }
    </style>
</head>
<body class="h-full flex overflow-hidden">
    <!-- MAIN WRAPPER -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- TOP NAVIGATION BAR -->
        <header class="h-16 bg-[#0B0D1B]/90 backdrop-blur-xl border-b border-brand-border/60 flex items-center justify-between px-6 z-20 sticky top-0">
            <!-- Left Header: Breadcrumbs & Mirror Status -->
            <div class="flex items-center space-x-4">
                <button id="sidebarToggle" class="lg:hidden p-2 rounded-xl bg-brand-navy-light text-brand-muted hover:text-white border border-brand-border">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="flex items-center space-x-3">
                    <div class="hidden sm:flex items-center space-x-2 bg-brand-navy-light/80 px-3 py-1.5 rounded-full border border-brand-border text-xs font-semibold text-gray-300">
                        <span class="w-2 h-2 rounded-full bg-brand-success pulse-live"></span>
                        <span>Active Mirror:</span>
                        <span class="text-brand-red-light font-mono font-bold"><?= htmlspecialchars($activeBaseUrl) ?></span>
                    </div>

                    <div class="hidden md:flex items-center space-x-1.5 bg-brand-navy-light/50 px-2.5 py-1 rounded-full border border-brand-border/50 text-[11px] text-brand-muted">
                        <i data-lucide="activity" class="w-3.5 h-3.5 text-brand-info"></i>
                        <span>Live Watching:</span>
                        <span class="text-white font-bold"><?= $liveHeartbeats ?></span>
                    </div>
                </div>
            </div>

            <!-- Right Header: Actions & Admin Profile -->
            <div class="flex items-center space-x-3">
                <!-- Push Notification Quick Dispatch -->
                <a href="/admin/notifications" class="bg-brand-navy-light hover:bg-brand-border text-gray-300 hover:text-white px-3 py-1.5 rounded-xl border border-brand-border text-xs font-bold transition flex items-center space-x-2">
                    <i data-lucide="send" class="w-3.5 h-3.5 text-brand-red"></i>
                    <span class="hidden sm:inline">Push Dispatch</span>
                </a>

                <!-- Live Stream Sandbox -->
                <a href="/admin/streaming" class="bg-brand-gradient hover:opacity-90 text-white px-3.5 py-1.5 rounded-xl shadow-brand-glow text-xs font-bold transition flex items-center space-x-2">
                    <i data-lucide="play" class="w-3.5 h-3.5"></i>
                    <span>Player Sandbox</span>
                </a>

                <!-- Admin Profile Dropdown -->
                <div class="relative ml-2 flex items-center space-x-3 pl-3 border-l border-brand-border/60">
                    <div class="w-8 h-8 rounded-xl bg-brand-gradient flex items-center justify-center font-bold text-white text-xs shadow-md">
                        <?= strtoupper(substr($adminUser['username'] ?? 'A', 0, 1)) ?>
                    </div>
                    <div class="hidden lg:block text-left">
                        <div class="text-xs font-bold text-white"><?= htmlspecialchars($adminUser['username'] ?? 'Administrator') ?></div>
                        <div class="text-[10px] text-brand-muted uppercase font-bold tracking-wider"><?= htmlspecialchars($adminUser['role'] ?? 'Superadmin') ?></div>
                    </div>
                    <a href="/admin/logout" class="p-1.5 rounded-lg text-brand-muted hover:text-brand-red transition" title="Logout">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                    </a>
                </div>
            </div>
        </header>

        <!-- LAYOUT BODY -->
        <div class="flex-1 flex overflow-hidden">
