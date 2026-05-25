<?php
$current_action = $_GET['action'] ?? '';
$is_dashboard_active = ($current_action === 'dashboard' || $current_action === '');
$is_document_active = ($current_action === 'document' || $current_action === 'docmasterlist');
$is_accreditation_active = ($current_action === 'accreditation' || $current_action === 'accmasterlist' || $current_action === 'accmapping');
$is_activity_active = ($current_action === 'activity' || $current_action === 'actmasterlist' || $current_action === 'view_activity' || $current_action === 'respondents');
?>

<!-- Premium Navbar & Dropdown Styles -->
<style>
    /* Scope styling to prevent leaks */
    .qa-navbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.8rem 5%;
        background: rgba(255, 255, 255, 0.96);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 4px 20px -2px rgba(0, 28, 87, 0.05);
        font-family: 'Inter', sans-serif;
    }

    .qa-logo-container {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        transition: transform 0.2s ease;
    }

    .qa-logo-container:hover {
        transform: scale(1.01);
    }

    .qa-logo-container img {
        height: 38px;
        width: auto;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.05));
    }

    .qa-logo-text {
        font-weight: 800;
        font-size: 1.25rem;
        color: #001C57;
        letter-spacing: -0.5px;
        display: flex;
        flex-direction: column;
    }

    .qa-logo-sub {
        font-size: 0.7rem;
        font-weight: 500;
        color: #DFB641;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin-top: -2px;
    }

    .qa-nav-links {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    /* Core Link Styles */
    .qa-nav-link {
        color: #475569;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 0.5rem 0.9rem;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        border: 1px solid transparent;
    }

    .qa-nav-link:hover, .qa-nav-link.active {
        color: #001C57;
        background-color: rgba(0, 28, 87, 0.04);
    }

    .qa-nav-link.active {
        border-color: rgba(0, 28, 87, 0.08);
        background-color: rgba(0, 28, 87, 0.05);
    }

    /* Dropdown Mechanism */
    .qa-dropdown {
        position: relative;
    }

    .qa-dropdown:hover .qa-dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(4px);
    }

    .qa-dropdown:hover .qa-chevron {
        transform: rotate(180deg);
    }

    .qa-dropdown-menu {
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%) translateY(10px);
        opacity: 0;
        visibility: hidden;
        min-width: 230px;
        background: #ffffff;
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: 12px;
        box-shadow: 0 10px 25px -5px rgba(0, 28, 87, 0.1), 0 8px 16px -6px rgba(0, 28, 87, 0.05);
        padding: 0.6rem;
        display: flex;
        flex-direction: column;
        gap: 4px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1010;
    }

    .qa-dropdown-item {
        color: #475569;
        font-weight: 550;
        font-size: 0.85rem;
        padding: 0.6rem 0.9rem;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .qa-dropdown-item:hover {
        color: #001C57;
        background-color: rgba(0, 28, 87, 0.04);
        transform: translateX(4px);
    }

    .qa-dropdown-item .qa-item-detail {
        font-size: 0.75rem;
        color: #94a3b8;
        font-weight: 400;
    }

    /* Logout Button Styling */
    .qa-logout-btn {
        background-color: transparent;
        color: #ef4444 !important;
        border: 1.5px solid rgba(239, 68, 68, 0.2);
        font-weight: 700 !important;
        padding: 0.5rem 1.1rem !important;
        border-radius: 8px;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    .qa-logout-btn:hover {
        color: #ffffff !important;
        background-color: #ef4444 !important;
        border-color: #ef4444 !important;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        transform: translateY(-1px);
    }

    /* Mobile Hamburger Menu */
    .qa-mobile-toggle {
        display: none;
        background: transparent;
        border: none;
        color: #001C57;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        transition: background 0.2s;
    }

    .qa-mobile-toggle:hover {
        background: rgba(0, 28, 87, 0.05);
    }

    /* Responsive adjustments */
    @media (max-width: 992px) {
        .qa-nav-links {
            display: none; /* Hidden, handled by mobile drawer */
        }
        
        .qa-mobile-toggle {
            display: block;
        }
    }

    /* Mobile Drawer */
    .qa-drawer {
        position: fixed;
        top: 0;
        right: -300px;
        width: 280px;
        height: 100vh;
        background: #ffffff;
        box-shadow: -5px 0 25px rgba(0, 0, 0, 0.1);
        z-index: 10000;
        padding: 2rem 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: 'Inter', sans-serif;
    }

    .qa-drawer.active {
        right: 0;
    }

    .qa-drawer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 1rem;
    }

    .qa-drawer-close {
        background: transparent;
        border: none;
        font-size: 1.8rem;
        color: #64748b;
        cursor: pointer;
        line-height: 1;
    }

    .qa-drawer-links {
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
    }

    .qa-drawer-heading {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #94a3b8;
        letter-spacing: 1px;
        margin-top: 0.8rem;
        margin-bottom: 0.2rem;
    }

    .qa-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 28, 87, 0.3);
        backdrop-filter: blur(4px);
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .qa-overlay.active {
        opacity: 1;
        visibility: visible;
    }
</style>

<nav class="qa-navbar">
    <a href="feed.php?action=dashboard" class="qa-logo-container">
        <img src="../assets/img/NBSC_logo.png" alt="NBSC Logo">
        <img src="../assets/img/QAO_logo.png" alt="QAO Logo">
        <div class="qa-logo-text">
            <span>QA System</span>
            <span class="qa-logo-sub">Quality Assurance</span>
        </div>
    </a>

    <!-- Desktop Navigation Links -->
    <div class="qa-nav-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <!-- Dashboard Link -->
            <a href="feed.php?action=dashboard" class="qa-nav-link <?= $is_dashboard_active ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Dashboard
            </a>

            <!-- Document Dropdown -->
            <div class="qa-dropdown">
                <button class="qa-nav-link <?= $is_document_active ? 'active' : '' ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Document
                    <svg class="qa-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.25s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
                <div class="qa-dropdown-menu">
                    <a href="feed.php?action=docmasterlist" class="qa-dropdown-item">
                        <span>Masterlist</span>
                    </a>
                    <a href="feed.php?action=document" class="qa-dropdown-item">
                        <span>Mapping</span>
                    </a>
                </div>
            </div>

            <!-- Accreditation Dropdown -->
            <div class="qa-dropdown">
                <button class="qa-nav-link <?= $is_accreditation_active ? 'active' : '' ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    Accreditation
                    <svg class="qa-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.25s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
                <div class="qa-dropdown-menu">
                    <a href="feed.php?action=accmasterlist" class="qa-dropdown-item">
                        <span>Masterlist</span>
                    </a>
                    <a href="feed.php?action=accmapping" class="qa-dropdown-item">
                        <span>Mapping</span>
                    </a>
                    <a href="feed.php?action=accreditation" class="qa-dropdown-item">
                        <span>Tracking</span>
                    </a>
                </div>
            </div>

            <!-- Activity Dropdown -->
            <div class="qa-dropdown">
                <button class="qa-nav-link <?= $is_activity_active ? 'active' : '' ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    Activity
                    <svg class="qa-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.25s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
                <div class="qa-dropdown-menu">
                    <a href="feed.php?action=activity" class="qa-dropdown-item">
                        <span>Activity Evaluation</span>
                    </a>
                    <a href="feed.php?action=actmasterlist" class="qa-dropdown-item">
                        <span>Masterlist</span>
                    </a>
                </div>
            </div>

            <!-- Logout Link -->
            <a href="../api/auth.php?action=logout" class="qa-logout-btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Logout
            </a>
        <?php else: ?>
            <a href="../index.php" class="qa-nav-link active">Home</a>
        <?php endif; ?>
    </div>

    <!-- Mobile Menu Button -->
    <button class="qa-mobile-toggle" onclick="toggleQADrawer()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
</nav>

<!-- Mobile Overlay & Drawer -->
<div class="qa-overlay" id="qaOverlay" onclick="toggleQADrawer()"></div>
<div class="qa-drawer" id="qaDrawer">
    <div class="qa-drawer-header">
        <span style="font-weight: 800; color: #001C57; font-size: 1.15rem;">Navigation Menu</span>
        <button class="qa-drawer-close" onclick="toggleQADrawer()">&times;</button>
    </div>

    <div class="qa-drawer-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="feed.php?action=dashboard" class="qa-nav-link <?= $is_dashboard_active ? 'active' : '' ?>">Dashboard</a>
            
            <div class="qa-drawer-heading">Document</div>
            <a href="feed.php?action=docmasterlist" class="qa-nav-link" style="padding-left: 1.5rem;">Masterlist</a>
            <a href="feed.php?action=document" class="qa-nav-link" style="padding-left: 1.5rem;">Mapping</a>
            
            <div class="qa-drawer-heading">Accreditation</div>
            <a href="feed.php?action=accmasterlist" class="qa-nav-link" style="padding-left: 1.5rem;">Masterlist</a>
            <a href="feed.php?action=accmapping" class="qa-nav-link" style="padding-left: 1.5rem;">Mapping</a>
            <a href="feed.php?action=accreditation" class="qa-nav-link" style="padding-left: 1.5rem;">Tracking</a>
            
            <div class="qa-drawer-heading">Activity</div>
            <a href="feed.php?action=activity" class="qa-nav-link" style="padding-left: 1.5rem;">Activity Evaluation</a>
            <a href="feed.php?action=actmasterlist" class="qa-nav-link" style="padding-left: 1.5rem;">Masterlist</a>
            
            <div style="margin-top: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1.5rem;">
                <a href="../api/auth.php?action=logout" class="qa-logout-btn" style="justify-content: center;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    Logout
                </a>
            </div>
        <?php else: ?>
            <a href="../index.php" class="qa-nav-link active">Home</a>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleQADrawer() {
        const drawer = document.getElementById('qaDrawer');
        const overlay = document.getElementById('qaOverlay');
        drawer.classList.toggle('active');
        overlay.classList.toggle('active');
    }
</script>
