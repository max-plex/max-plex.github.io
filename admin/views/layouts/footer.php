        </div>
        <!-- END LAYOUT BODY -->

    </div>
    <!-- END MAIN WRAPPER -->

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="fixed bottom-6 right-6 z-50 flex flex-col space-y-3 pointer-events-none"></div>

    <?php
    $flashMsg = $_SESSION['flash_msg'] ?? null;
    $flashType = $_SESSION['flash_type'] ?? 'success';
    if ($flashMsg) {
        unset($_SESSION['flash_msg']);
        unset($_SESSION['flash_type']);
    }
    ?>

    <script>
        // Lucide icons initialization
        document.addEventListener("DOMContentLoaded", function() {
            if (window.lucide) {
                lucide.createIcons();
            }

            // Trigger session flash toast if present
            <?php if ($flashMsg): ?>
                showToast(<?= json_encode($flashMsg) ?>, <?= json_encode($flashType) ?>);
            <?php endif; ?>

            // Global Form Submit Auto-Loader & Disabler
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        const originalText = submitBtn.innerText.trim();
                        setButtonLoading(submitBtn, true, 'Processing...');
                    }
                });
            });
        });

        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('-translate-x-full');
            });
        }

        // Global Toast Notification Helper
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toast = document.createElement('div');
            const isSuccess = (type === 'success');
            const bgClass = isSuccess ? 'bg-[#131730] border-brand-success/50 text-brand-success' : 'bg-[#131730] border-brand-red/50 text-brand-red';
            const iconName = isSuccess ? 'check-circle' : 'alert-circle';
            
            toast.className = `pointer-events-auto flex items-center space-x-3 px-5 py-3.5 rounded-2xl border ${bgClass} shadow-2xl backdrop-blur-xl text-xs font-bold transition-all duration-300 transform translate-y-4 opacity-0`;
            toast.innerHTML = `
                <i data-lucide="${iconName}" class="w-4 h-4 flex-shrink-0"></i>
                <span class="text-white font-medium">${message}</span>
            `;
            
            container.appendChild(toast);
            if (window.lucide) lucide.createIcons();

            setTimeout(() => {
                toast.classList.remove('translate-y-4', 'opacity-0');
            }, 20);

            setTimeout(() => {
                toast.classList.add('translate-y-4', 'opacity-0');
                setTimeout(() => toast.remove(), 350);
            }, 4000);
        }

        // Global Button Loading & Disabling Helper
        function setButtonLoading(btn, isLoading, loadingText = 'Processing...') {
            if (!btn) return;
            if (isLoading) {
                btn.dataset.originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.classList.add('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
                btn.innerHTML = `
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>${loadingText}</span>
                `;
            } else {
                if (btn.dataset.originalHtml) {
                    btn.innerHTML = btn.dataset.originalHtml;
                }
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
                if (window.lucide) lucide.createIcons();
            }
        }
    </script>
</body>
</html>
