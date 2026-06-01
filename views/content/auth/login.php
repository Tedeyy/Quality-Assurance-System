<link rel="stylesheet" href="../assets/css/login.css">

<div class="auth-page-wrapper">
    <div class="login-container">
        <!-- Left Pane -->
        <div class="login-brand-pane">
            <div class="brand-content">
                <h1 class="brand-title">Quality Assurance</h1>
                <div class="brand-subtitle">Centralized Management System</div>

                <p class="brand-description">
                    Welcome to the centralized portal for managing accreditations,
                    streamlining document control, and tracking institutional
                    procedures. Our goal is to maintain excellence and compliance.
                </p>

                <div class="quote-box">
                    <span class="quote-text">"Quality is not an act, it is a habit."</span>
                    <span class="quote-author">Aristotle</span>
                </div>

                <div class="contact-info">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </div>
                        qao@nbsc.edu.ph
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </div>
                        SWDC Second Floor, Room 210
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Pane -->
        <div class="login-form-pane">
            <img src="../assets/img/QAO_logo.png" alt="QAO Logo" class="login-logo">
            <form action="../api/auth.php?action=login" method="POST" class="auth-form" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" required class="form-control login-submit-field" placeholder="Enter your email">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" required class="form-control login-submit-field" placeholder="Enter your password">
                        <div class="password-toggle" onclick="togglePassword()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-signin" id="signInButton">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    Sign In
                </button>
                <p class="signin-notice">
                    By signing in, you agree to the <a href="../terms.php">Terms of Use and Conditions</a>.
                </p>

                <div style="margin: 1.5rem 0; display: flex; align-items: center; gap: 10px;">
                    <div style="flex: 1; height: 1px; background: #e2e8f0;"></div>
                    <span style="font-size: 0.75rem; color: #94a3b8; font-weight: 700;">OR</span>
                    <div style="flex: 1; height: 1px; background: #e2e8f0;"></div>
                </div>

                <a href="../api/auth.php?action=google_login" class="btn google-btn" style="text-decoration: none; width: 100%; box-sizing: border-box; justify-content: center; background: white; border: 1.5px solid #e2e8f0; border-radius: 10px; color: #1e293b; font-weight: 700; padding: 0.8rem; display: flex; align-items: center; gap: 10px; transition: all 0.2s;">
                    <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                    </svg>
                    Continue with Google
                </a>
            </form>

            <div class="auth-links">
                New here? <a href="../views/feed.php?action=signup">Create an Account</a>
                <a href="../" class="back-link">← Back to Landing Page</a>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const pwd = document.getElementById('password');
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
}

document.querySelectorAll('.login-submit-field').forEach((field) => {
    field.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            document.getElementById('signInButton').click();
        }
    });
});
</script>
