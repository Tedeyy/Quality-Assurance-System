<nav class="navbar">
    <div class="logo" style="display: flex; align-items: center; gap: 10px;">
        <img src="../assets/img/NBSC_logo.png" alt="NBSC Logo" style="height: 35px; width: auto;">
        <img src="../assets/img/QAO_logo.png" alt="QAO Logo" style="height: 35px; width: auto;">
        <span style="font-weight: 700; color: var(--accent-blue); margin-left: 5px;">Quality Assurance System</span>
    </div>
    <div class="nav-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="feed.php?action=dashboard">Dashboard</a>
            <a href="../api/auth.php?action=logout" class="nav-logout">Logout</a>
        <?php else: ?>
            <a href="../index.php">Home</a>
        <?php endif; ?>
    </div>
</nav>