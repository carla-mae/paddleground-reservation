<?php
// includes/customer_sidebar.php
// Expects $activePage to be set by the including page: 'dashboard' | 'book' | 'payment' | 'bookings' | 'settings'
$activePage = $activePage ?? '';
?>
<style>
    /* ---------- Brand palette (unified green, sage background) ---------- */
    :root {
        --brand-green: #16A34A;
        --brand-green-dark: #128A3E;
        --brand-ink: #17301F;
        --page-bg: #EAF1EC;
        --card-bg: #FFFFFF;
        --border-soft: #DDE6E0;
        --muted: #6B7A70;
    }

    .sidebar-toggle {
        position: fixed;
        top: 18px;
        left: 18px;
        z-index: 410;
        width: 42px;
        height: 42px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        flex-direction: column;
        gap: 4px;
        box-shadow: 0 2px 8px rgba(23, 48, 31, 0.06);
    }
    .sidebar-toggle:hover { border-color: var(--brand-green); }
    .sidebar-toggle .bar {
        width: 18px;
        height: 2px;
        background: var(--brand-ink);
        border-radius: 2px;
    }
    /* Icon intentionally always stays as 3 bars — no morph into an X when toggled. */

    .sidebar {
        width: 290px;
        background: var(--card-bg);
        border-right: 1px solid var(--border-soft);
        padding: 26px 24px;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        overflow: hidden;
        transition: width 0.25s ease, padding 0.25s ease, opacity 0.2s ease;
        position: sticky;
        top: 0;
        height: 100vh;
        align-self: flex-start;
    }
    .sidebar.collapsed {
        width: 0;
        padding-left: 0;
        padding-right: 0;
        opacity: 0;
        pointer-events: none;
    }
    .sidebar-inner {
        width: 242px;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }
    .logo {
        font-size: 21px;
        font-weight: 800;
        line-height: 1.35;
        letter-spacing: 0.3px;
        margin-bottom: 28px;
        padding-left: 50px;
        white-space: nowrap;
    }
    .logo .accent {
        display: block;
        color: var(--brand-green);
    }
    .logo .rest {
        display: block;
        color: var(--brand-ink);
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 1.5px;
        margin-top: 2px;
    }

    .user-card {
        background: var(--page-bg);
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        padding: 14px 16px;
        margin-bottom: 24px;
    }
    .user-card .name { font-weight: 700; font-size: 16px; white-space: nowrap; color: var(--brand-ink); }
    .user-card .role {
        color: var(--muted);
        font-size: 12px;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    .nav-links {
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 6px;
        flex-grow: 1;
    }
    .nav-links li a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 8px;
        color: #3f4b45;
        text-decoration: none;
        font-size: 15px;
        white-space: nowrap;
        transition: background 0.15s ease, color 0.15s ease;
    }
    .nav-links li a:hover { background: var(--page-bg); color: var(--brand-ink); }
    .nav-links li a.active {
        background: rgba(22, 163, 74, 0.12);
        color: var(--brand-green);
        font-weight: 600;
    }
    .nav-icon {
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .nav-icon svg {
        width: 18px;
        height: 18px;
        stroke: currentColor;
        fill: none;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .signout {
        margin-top: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: var(--brand-green);
        color: #ffffff;
        font-weight: 700;
        text-decoration: none;
        padding: 14px;
        border-radius: 8px;
        white-space: nowrap;
        transition: filter 0.15s ease;
    }
    .signout:hover { filter: brightness(1.08); }

    /* ---------- Backdrop (mobile overlay dimmer) ---------- */
    .sidebar-backdrop {
        display: none;
    }

    /* ---------- MOBILE: sidebar becomes a slide-in overlay ---------- */
    /* Below this width, the sidebar no longer participates in the flex
       layout (so it can never push .content-col down or leave dead
       space behind when closed). Instead it's a fixed panel that slides
       over the page, with a dimmed backdrop behind it — same pattern as
       a typical mobile nav drawer. */
    @media (max-width: 900px) {
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: min(280px, 82vw);
            z-index: 400;
            opacity: 1;
            padding: 26px 20px;
            padding-top: 76px; /* clear the fixed toggle button */
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform 0.28s ease;
            pointer-events: auto;
        }
        .sidebar:not(.collapsed) {
            transform: translateX(0);
        }
        /* Collapsed no longer means width:0 on mobile — it means slid
           off-screen — so undo the desktop collapsed rules here. */
        .sidebar.collapsed {
            width: min(280px, 82vw);
            padding-left: 20px;
            padding-right: 20px;
            pointer-events: none;
        }
        .sidebar-inner { width: 100%; }

        .sidebar-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(23, 48, 31, 0.45);
            z-index: 390;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .sidebar-backdrop.visible {
            opacity: 1;
            pointer-events: auto;
        }
    }

    /* ---------- "Reserve Now first" notice toast ---------- */
    /* Shown when Book a Court is clicked directly from the sidebar,
       instead of navigating away. Independent of any toast-stack that
       may or may not exist on the current page. */
    .sidebar-notice-stack {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 600;
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: 320px;
        max-width: calc(100vw - 40px);
    }
    .sidebar-notice {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-left: 4px solid #eab308;
        border-radius: 12px;
        padding: 14px 16px;
        box-shadow: 0 10px 30px rgba(23, 48, 31, 0.16);
        display: flex;
        gap: 10px;
        align-items: flex-start;
        opacity: 0;
        transform: translateX(24px);
        animation: sidebarNoticeIn 0.3s ease forwards;
        font-size: 13.5px;
        color: var(--brand-ink);
        line-height: 1.45;
    }
    .sidebar-notice.leaving {
        animation: sidebarNoticeOut 0.25s ease forwards;
    }
    @keyframes sidebarNoticeIn {
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes sidebarNoticeOut {
        to { opacity: 0; transform: translateX(24px); }
    }
    .sidebar-notice .icon { flex-shrink: 0; font-size: 16px; }
    @media (max-width: 600px) {
        .sidebar-notice-stack {
            top: 12px;
            right: 12px;
            left: 12px;
            width: auto;
            max-width: none;
        }
    }
</style>

<button type="button" class="sidebar-toggle" id="sidebarToggleBtn" onclick="toggleCustomerSidebar()" aria-label="Toggle menu">
    <span class="bar"></span>
    <span class="bar"></span>
    <span class="bar"></span>
</button>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleCustomerSidebar()"></div>

<div class="sidebar-notice-stack" id="sidebarNoticeStack"></div>

<div class="sidebar" id="customerSidebar">
    <div class="sidebar-inner">
        <div class="logo">
            <span class="accent">PADDLE GROUND</span>
            <span class="rest">RESERVATION</span>
        </div>

        <div class="user-card">
            <div class="name"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
            <div class="role">CUSTOMER</div>
        </div>

        <ul class="nav-links">
            <li><a href="dashboard.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1H9.5a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-9"/></svg>
                </span> Home
            </a></li>
            <li><a href="#" id="bookCourtLink" class="<?= $activePage === 'book' ? 'active' : '' ?>">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17"/><path d="M8 3v4M16 3v4"/><path d="M7.5 13.2h2M7.5 16.5h2M11 13.2h2M11 16.5h2M14.5 13.2h2"/></svg>
                </span> Book a Court
            </a></li>
            <li><a href="payment.php" class="<?= $activePage === 'payment' ? 'active' : '' ?>">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 9.5h19"/><path d="M6 15h4"/></svg>
                </span> Payment
            </a></li>
            <li><a href="booking_status.php" class="<?= $activePage === 'bookings' ? 'active' : '' ?>">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><path d="M4.5 5.5h11a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2h-11a2 2 0 0 1-2-2V7.5a2 2 0 0 1 2-2Z"/><path d="M8 3v4M13.5 3v4"/><path d="M8 13.2l2 2 4.5-4.7"/></svg>
                </span> My Bookings
            </a></li>
            <li><a href="settings.php" class="<?= $activePage === 'settings' ? 'active' : '' ?>">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 13.5a1.7 1.7 0 0 0 .34 1.9l.06.06a2 2 0 1 1-2.9 2.9l-.06-.06a1.7 1.7 0 0 0-1.9-.34 1.7 1.7 0 0 0-1 1.55V20a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.55 1.7 1.7 0 0 0-1.9.34l-.06.06a2 2 0 1 1-2.9-2.9l.06-.06a1.7 1.7 0 0 0 .34-1.9 1.7 1.7 0 0 0-1.55-1H4a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.55-1.1 1.7 1.7 0 0 0-.34-1.9l-.06-.06a2 2 0 1 1 2.9-2.9l.06.06a1.7 1.7 0 0 0 1.9.34H10.4a1.7 1.7 0 0 0 1-1.55V4a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.9-.34l.06-.06a2 2 0 1 1 2.9 2.9l-.06.06a1.7 1.7 0 0 0-.34 1.9V10.4a1.7 1.7 0 0 0 1.55 1H20a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.55 1Z"/></svg>
                </span> Settings
            </a></li>
        </ul>

        <a href="../auth/logout.php" class="signout">
            <span class="nav-icon" style="color: inherit;">
                <svg viewBox="0 0 24 24"><path d="M9 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h3"/><path d="M16 16l4-4-4-4"/><path d="M20 12H9"/></svg>
            </span> Sign Out
        </a>
    </div>
</div>
<script>
    function toggleCustomerSidebar() {
        document.getElementById('customerSidebar').classList.toggle('collapsed');
        document.getElementById('sidebarToggleBtn').classList.toggle('active');
        document.getElementById('sidebarBackdrop').classList.toggle('visible');
    }

    // On mobile, the sidebar should start CLOSED (it's now an overlay drawer).
    // On desktop, it should start OPEN (inline, as before). This runs once on
    // load so each screen size gets the right default without a flash.
    (function () {
        if (window.matchMedia('(max-width: 900px)').matches) {
            document.getElementById('customerSidebar').classList.add('collapsed');
        }
    })();

    // "Book a Court" in the sidebar never navigates directly — customers
    // must start from the "Reserve Now" button on the Home dashboard.
    // Clicking it here just reminds them to do that instead.
    document.getElementById('bookCourtLink').addEventListener('click', function (e) {
        e.preventDefault();

        const stack = document.getElementById('sidebarNoticeStack');
        const notice = document.createElement('div');
        notice.className = 'sidebar-notice';
        notice.innerHTML = `
            <span class="icon">⚠️</span>
            <span>Please click the <b>Reserve Now</b> button in Home first before you can book a court.</span>
        `;
        stack.appendChild(notice);

        setTimeout(() => {
            notice.classList.add('leaving');
            setTimeout(() => notice.remove(), 250);
        }, 3500);
    });
</script>