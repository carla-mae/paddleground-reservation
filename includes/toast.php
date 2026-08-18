<?php
// partials/toast.php
// Include this near the top of any page (after session_start / session_check)
// to show a one-time "flash" toast notification, e.g. after login.
// Usage:  include '../partials/toast.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<?php if ($flash_success || $flash_error): ?>
<style>
    #toast {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 260px;
        max-width: 360px;
        padding: 14px 18px;
        border-radius: 10px;
        font-size: 0.9rem;
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        display: flex;
        align-items: center;
        gap: 10px;
        opacity: 0;
        transform: translateY(-12px);
        transition: opacity 0.3s ease, transform 0.3s ease;
    }
    #toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    #toast.success {
        background: rgba(34, 197, 94, 0.12);
        color: #22c55e;
        border: 1px solid rgba(34, 197, 94, 0.35);
    }
    #toast.error {
        background: rgba(239, 68, 68, 0.12);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.35);
    }
    @media (max-width: 480px) {
        #toast {
            right: 12px;
            left: 12px;
            max-width: none;
        }
    }
</style>

<div id="toast" class="<?= $flash_success ? 'success' : 'error' ?>">
    <?php if ($flash_success): ?>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <?php else: ?>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?php endif; ?>
    <span><?= htmlspecialchars($flash_success ?? $flash_error) ?></span>
</div>

<script>
    (function () {
        const toast = document.getElementById('toast');
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    })();
</script>
<?php endif; ?>