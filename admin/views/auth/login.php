<?php
$error = $error ?? null;
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0B0D1B] antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — HDHub4u Engine</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: {
                        brand: {
                            red: '#E50914',
                            navy: '#11142B',
                            'navy-dark': '#0B0D1B',
                            'navy-light': '#181C38',
                            border: '#23284C'
                        }
                    },
                    backgroundImage: {
                        'brand-gradient': 'linear-gradient(135deg, #E50914 0%, #FF5252 100%)',
                        'glow-radial': 'radial-gradient(circle at 50% 30%, rgba(229, 9, 20, 0.25) 0%, transparent 70%)'
                    }
                }
            }
        }
    </script>
</head>
<body class="h-full flex items-center justify-center bg-[#0B0D1B] bg-glow-radial p-4 relative overflow-hidden">
    <!-- Decorative Blurs -->
    <div class="absolute -top-40 -left-40 w-96 h-96 bg-red-600/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-purple-600/15 rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-full max-w-md relative z-10 space-y-6">
        <!-- Brand Header -->
        <div class="text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-brand-gradient text-white font-black text-2xl shadow-xl shadow-red-900/40 mb-3">
                HD
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">HDHub4u <span class="text-brand-red">OTT Admin</span></h1>
            <p class="text-xs text-gray-400 mt-1">Executive Intelligence & Operations Console</p>
        </div>

        <!-- Login Card -->
        <div class="p-8 rounded-3xl bg-[#131730]/90 border border-brand-border/80 shadow-2xl backdrop-blur-2xl space-y-6">
            <?php if (!empty($error)): ?>
                <div class="p-4 rounded-2xl bg-red-500/15 border border-red-500/30 flex items-center space-x-3 text-red-400 text-xs font-bold">
                    <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="/admin/login" method="POST" onsubmit="handleLoginSubmit(this)" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-300 uppercase tracking-wider mb-2">Username / Email</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-500">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </span>
                        <input type="text" name="username" required placeholder="admin or admin@hdhub4u.com" 
                               class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl pl-10 pr-4 py-3 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-brand-red focus:ring-1 focus:ring-brand-red transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-300 uppercase tracking-wider mb-2">Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-500">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                        </span>
                        <input type="password" id="passwordInput" name="password" required placeholder="••••••••••••" 
                               class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl pl-10 pr-10 py-3 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-brand-red focus:ring-1 focus:ring-brand-red transition">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-500 hover:text-gray-300">
                            <i data-lucide="eye" id="passwordEyeIcon" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" id="loginSubmitBtn"
                            class="w-full bg-brand-gradient hover:opacity-95 text-white font-bold py-3.5 px-4 rounded-xl shadow-lg shadow-red-900/30 text-sm transition transform active:scale-[0.98] flex items-center justify-center space-x-2">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                        <span>Authenticate Session</span>
                    </button>
                </div>
            </form>

            <div class="pt-4 border-t border-brand-border/60 text-center">
                <div class="inline-flex items-center space-x-2 text-[11px] text-gray-400 font-medium">
                    <span class="w-2 h-2 rounded-full bg-green-400"></span>
                    <span>256-Bit Encrypted Session</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            if (window.lucide) lucide.createIcons();
        });

        function togglePasswordVisibility() {
            const pwd = document.getElementById('passwordInput');
            const eye = document.getElementById('passwordEyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                eye.setAttribute('data-lucide', 'eye-off');
            } else {
                pwd.type = 'password';
                eye.setAttribute('data-lucide', 'eye');
            }
            if (window.lucide) lucide.createIcons();
        }

        function handleLoginSubmit(form) {
            const btn = document.getElementById('loginSubmitBtn');
            if (!btn) return;
            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
            btn.innerHTML = `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Authenticating Session...</span>
            `;
        }
    </script>
</body>
</html>
