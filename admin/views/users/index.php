<?php
$pageTitle = 'Registered App Users Directory';
$activeTab = 'users';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$users = $users ?? [];
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">Registered Mobile App Users</h1>
            <p class="text-xs text-brand-muted mt-1">Manage mobile app profiles, authentication methods, active devices, and user status</p>
        </div>
    </div>

    <!-- Users Table Card -->
    <div class="p-6 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted uppercase text-[10px]">
                        <th class="py-3 px-4">User / Avatar</th>
                        <th class="py-3 px-4">Email</th>
                        <th class="py-3 px-4">Auth Provider</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Joined Date</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-gray-300">
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="py-8 text-center text-brand-muted">No registered users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="py-3.5 px-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-full bg-brand-gradient flex items-center justify-center font-bold text-white text-xs">
                                            <?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?>
                                        </div>
                                        <span class="font-bold text-white"><?= htmlspecialchars($u['name'] ?? 'Anonymous') ?></span>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-[11px]"><?= htmlspecialchars($u['email']) ?></td>
                                <td class="py-3.5 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold <?= ($u['auth_provider'] === 'google') ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-brand-navy-light text-brand-info border border-brand-border' ?>">
                                        <?= strtoupper($u['auth_provider'] ?? 'EMAIL') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="flex items-center space-x-1.5 text-brand-success text-[11px] font-bold">
                                        <span class="w-2 h-2 rounded-full bg-brand-success"></span>
                                        <span>Active</span>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-brand-muted"><?= htmlspecialchars(date('M d, Y', strtotime($u['created_at']))) ?></td>
                                <td class="py-3.5 px-4 text-right">
                                    <form action="/admin/users/toggle-status" method="POST" class="inline">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="p-1.5 rounded-lg bg-brand-navy-light hover:bg-brand-red text-gray-300 hover:text-white transition" title="Toggle Status">
                                            <i data-lucide="power" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
