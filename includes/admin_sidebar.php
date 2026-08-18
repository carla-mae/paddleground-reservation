<?php
// $activePage should be set by the including page: 'dashboard' | 'bookings' | 'payments' | 'reports' | 'settings'
$activePage = $activePage ?? '';
$navItems = [
    'dashboard'  => ['label' => 'Home',              'href' => 'dashboard.php',      'icon' => 'home'],
    'bookings'   => ['label' => 'View Bookings',     'href' => 'view_bookings.php',  'icon' => 'calendar'],
    'payments'   => ['label' => 'Verify Payments',   'href' => 'verify_payment.php', 'icon' => 'card'],
    'reports'    => ['label' => 'Generate Report',   'href' => 'reports.php',        'icon' => 'chart'],
    'settings'   => ['label' => 'Settings',          'href' => 'settings.php',       'icon' => 'gear'],
];
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
        width: 220px;
        background: var(--card-bg);
        border-right: 1px solid var(--border-soft);
        padding: 28px 18px;
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
    .sidebar-inner { width: 184px; }
    .sidebar .brand {
        font-weight: 800;
        font-size: 16px;
        color: var(--brand-ink);
        margin-bottom: 30px;
        padding-left: 44px;
    }
    .sidebar .brand span { color: var(--brand-green); }
    .sidebar nav { display: flex; flex-direction: column; gap: 4px; }
    .sidebar nav a {
        color: #3f4b45;
        text-decoration: none;
        font-size: 14px;
        padding: 10px 12px;
        border-radius: 8px;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 9px;
    }
    .sidebar nav a:hover { background: var(--page-bg); color: var(--brand-ink); }
    .sidebar nav a.active {
        background: rgba(22,163,74,0.10);
        color: var(--brand-green);
        font-weight: 700;
    }
    .sidebar nav a .nav-icon {
        display: inline-flex;
        flex-shrink: 0;
        width: 16px;
        height: 16px;
    }
    .sidebar nav a .nav-icon svg {
        width: 16px;
        height: 16px;
        stroke: currentColor;
    }
    .sidebar .logout {
        margin-top: 28px;
        padding-top: 18px;
        border-top: 1px solid var(--border-soft);
    }

    /* ---------- Backdrop (mobile overlay dimmer) ---------- */
    .sidebar-backdrop {
        display: none;
    }

    /* ---------- FIX: keep page content clear of the fixed hamburger ----------
       The toggle button is `position: fixed; top:18px; left:18px;` and is
       42x42px, so it occupies roughly x:18-60, y:18-60 on screen — always,
       regardless of sidebar state. Every page's own `.main { padding: 40px }`
       is slightly SMALLER than that (40 < 60), so once the sidebar collapses
       to width:0 and `.main` shifts left to fill the gap, its heading lands
       directly under the fixed button and gets visually overlapped.

       This rule lives here (not in each page) so it applies automatically
       to every page that includes this sidebar, using a sibling selector:
       `.sidebar.collapsed` is a sibling of `.main` in every page's markup
       (both are direct children of <body>, since the sidebar include runs
       right before <div class="main">). No changes needed in dashboard.php,
       view_bookings.php, verify_payment.php, reports.php, or settings.php. */
    @media (min-width: 901px) {
        .sidebar.collapsed ~ .main {
            padding-top: 76px;
            padding-left: 76px;
        }
        /* Also stop the content block from centering itself in the newly
           widened .main — without this, `.main-inner { margin: 0 auto }`
           (set by each page) keeps the content visually stuck near the
           middle of the screen instead of using the space the sidebar
           just gave up. Left-aligning it here makes every page's content
           shift naturally toward the freed space on the left. */
        .sidebar.collapsed ~ .main .main-inner {
            margin-left: 0;
        }
    }

    /* ---------- MOBILE: sidebar becomes a slide-in overlay ---------- */
    /* Below this width, the sidebar no longer participates in the flex
       layout (so it can never push the main content down or leave dead
       space behind when closed). Instead it's a fixed panel that slides
       over the page, with a dimmed backdrop behind it. */
    @media (max-width: 900px) {
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: min(260px, 82vw);
            z-index: 400;
            opacity: 1;
            padding: 28px 18px;
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
            width: min(260px, 82vw);
            padding-left: 18px;
            padding-right: 18px;
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
</style>
<button type="button" class="sidebar-toggle" id="sidebarToggleBtn" onclick="toggleAdminSidebar()" aria-label="Toggle menu">
    <span class="bar"></span>
    <span class="bar"></span>
    <span class="bar"></span>
</button>
<div class="sidebar-backdrop" id="adminSidebarBackdrop" onclick="toggleAdminSidebar()"></div>
<div class="sidebar" id="adminSidebar">
    <div class="sidebar-inner">
        <div class="brand">Paddle<span>Admin</span></div>
        <nav>
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= $item['href'] ?>" class="<?= $activePage === $key ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <?php switch ($item['icon'] ?? ''):
                            case 'home': ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 11.5 12 4l9 7.5"></path>
                                    <path d="M5.5 10v9a1 1 0 0 0 1 1H9.5a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-9"></path>
                                </svg>
                                <?php break;
                            case 'calendar': ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3.5" y="5" width="17" height="15.5" rx="2"></rect>
                                    <path d="M3.5 9.5h17"></path>
                                    <path d="M8 3v4M16 3v4"></path>
                                    <path d="M7.5 13.2h2M7.5 16.5h2M11 13.2h2M11 16.5h2M14.5 13.2h2"></path>
                                </svg>
                                <?php break;
                            case 'card': ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2.5" y="5.5" width="19" height="13" rx="2"></rect>
                                    <path d="M2.5 9.5h19"></path>
                                    <path d="M6 15h4"></path>
                                </svg>
                                <?php break;
                            case 'chart': ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 19.5h16"></path>
                                    <rect x="6" y="12" width="3" height="6" rx="0.5"></rect>
                                    <rect x="11" y="8" width="3" height="10" rx="0.5"></rect>
                                    <rect x="16" y="4.5" width="3" height="13.5" rx="0.5"></rect>
                                </svg>
                                <?php break;
                            case 'gear': ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="3"></circle>
                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                </svg>
                                <?php break;
                        endswitch; ?>
                    </span>
                    <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>
            <div class="logout">
                <a href="../auth/logout.php">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h3"></path>
                            <path d="M16 16l4-4-4-4"></path>
                            <path d="M20 12H9"></path>
                        </svg>
                    </span>
                    Logout
                </a>
            </div>
        </nav>
    </div>
</div>
<script>
    function toggleAdminSidebar() {
        document.getElementById('adminSidebar').classList.toggle('collapsed');
        document.getElementById('sidebarToggleBtn').classList.toggle('active');
        document.getElementById('adminSidebarBackdrop').classList.toggle('visible');
    }

    // On mobile, the sidebar should start CLOSED (it's now an overlay drawer).
    // On desktop, it should start OPEN (inline, as before). This runs once on
    // load so each screen size gets the right default without a flash.
    (function () {
        if (window.matchMedia('(max-width: 900px)').matches) {
            document.getElementById('adminSidebar').classList.add('collapsed');
        }
    })();
</script>