<main class="hero" style="min-height: calc(100vh - 350px);">
    <div class="feature-card auth-container">
        <div class="feature-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                <polyline points="10 17 15 12 10 7"></polyline>
                <line x1="15" y1="12" x2="3" y2="12"></line>
            </svg>
        </div>
        <h2 style="margin-bottom: 0.5rem;">Welcome Back</h2>
        <p class="auth-footer" style="margin-top: 0; margin-bottom: 2.5rem;">Sign in to your QA System account</p>

        <form action="../api/auth.php?action=login" method="POST" class="auth-form">
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" id="email" name="email" required class="form-control">
            </div>
            <div class="form-group" style="margin-bottom: 2rem;">
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password" required class="form-control">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.05rem;">Sign In</button>
        </form>

        <div class="auth-divider">
            <div class="divider-line"></div>
            <span class="divider-text">OR</span>
            <div class="divider-line"></div>
        </div>

        <div style="text-align: center; margin-bottom: 1.5rem;">
            <a href="../api/auth.php?action=google_login" class="btn google-btn" style="text-decoration: none; width: 100%; box-sizing: border-box; justify-content: center;">
                <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                        fill="#4285F4" />
                    <path
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                        fill="#34A853" />
                    <path
                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                        fill="#FBBC05" />
                    <path
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                        fill="#EA4335" />
                </svg>
                Sign in with Google
            </a>
        </div>
        <p class="auth-footer">
            Don't have an account? <a href="../views/feed.php?action=signup">Create Account</a>
        </p>
    </div>
</main>