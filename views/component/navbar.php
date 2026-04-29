    <nav class="navbar">
        <div class="logo">QA System</div>
        <div class="nav-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="feed.php?action=dashboard">Dashboard</a>
                <a href="../api/auth.php?action=logout" class="nav-logout">Logout</a>
            <?php else: ?>
                <a href="../index.html">Home</a>
                <a href="../index.html#features">Features</a>
            <?php endif; ?>
        </div>
    </nav>