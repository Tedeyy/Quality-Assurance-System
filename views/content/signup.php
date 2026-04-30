    <main class="hero" style="min-height: calc(100vh - 350px);">
        <div class="feature-card auth-container">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="8.5" cy="7" r="4"></circle>
                    <line x1="20" y1="8" x2="20" y2="14"></line>
                    <line x1="23" y1="11" x2="17" y2="11"></line>
                </svg>
            </div>
            <h2 style="margin-bottom: 0.5rem;">Create Account</h2>
            <p class="auth-footer" style="margin-top: 0; margin-bottom: 2.5rem;">Join the QA System platform</p>
            
            <div style="text-align: center; margin-top: 1.5rem; margin-bottom: 1.5rem;">
                <div id="g_id_onload"
                     data-client_id="<?= htmlspecialchars($_ENV['GOOGLE_CLIENT_ID'] ?? '') ?>"
                     data-login_uri="YOUR_LOGIN_ENDPOINT"
                     data-auto_prompt="false">
                </div>
                <button type="button" class="btn google-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Sign up with Google
                </button>
            </div>
            
            <div class="auth-divider">
                <div class="divider-line"></div>
                <span class="divider-text">OR</span>
                <div class="divider-line"></div>
            </div>

            <form action="../api/auth.php?action=signup" method="POST" class="auth-form">
                <div class="form-group-row">
                    <div>
                        <label for="fname" class="form-label">First Name *</label>
                        <input type="text" id="fname" name="fname" required class="form-control">
                    </div>
                    <div>
                        <label for="lname" class="form-label">Last Name *</label>
                        <input type="text" id="lname" name="lname" required class="form-control">
                    </div>
                </div>

                <div class="form-group-row">
                    <div>
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" id="email" name="email" required class="form-control">
                    </div>
                    <div>
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" id="password" name="password" required class="form-control">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.05rem;">Create Account</button>
            </form>

            <p class="auth-footer">
                Already have an account? <a href="feed.php?action=login">Sign In</a>
            </p>
        </div>
    </main>
