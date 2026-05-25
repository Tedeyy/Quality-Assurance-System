<div class="signup-page-wrapper">
    <div class="signup-container">
        <aside class="signup-brand-pane">
            <a href="../index.php" class="signup-brand">
                <img src="../assets/img/NBSC_logo.png" alt="NBSC Logo">
                <img src="../assets/img/QAO_logo.png" alt="QAO Logo">
                <span>
                    <strong>QA System</strong>
                    <small>Quality Assurance</small>
                </span>
            </a>

            <div class="signup-brand-copy">
                <span class="signup-kicker">Northern Bukidnon State College</span>
                <h1>Start with a secure QA account.</h1>
                <p>
                    Create your account to access accreditation tracking, activity evaluation, document control, and
                    quality assurance updates in one institutional workspace.
                </p>
            </div>

            <div class="signup-highlights" aria-label="Quality assurance account benefits">
                <div>
                    <strong>Accreditation</strong>
                    <span>Track requirements and evidence.</span>
                </div>
                <div>
                    <strong>Documents</strong>
                    <span>Manage QA records and mapping.</span>
                </div>
                <div>
                    <strong>Feedback</strong>
                    <span>Support evidence-based improvement.</span>
                </div>
            </div>
        </aside>

        <section class="signup-form-pane">
            <div class="signup-form-header">
                <div class="signup-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="20" y1="8" x2="20" y2="14"></line>
                        <line x1="23" y1="11" x2="17" y2="11"></line>
                    </svg>
                </div>
                <h2>Create Account</h2>
                <p>Use your institutional details to register.</p>
            </div>

            <form action="../api/auth.php?action=signup" method="POST" class="auth-form signup-form">
                <div class="form-group-row">
                    <div class="form-group">
                        <label for="fname" class="form-label">First Name *</label>
                        <input type="text" id="fname" name="fname" required class="form-control" autocomplete="given-name">
                    </div>
                    <div class="form-group">
                        <label for="lname" class="form-label">Last Name *</label>
                        <input type="text" id="lname" name="lname" required class="form-control" autocomplete="family-name">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email Address *</label>
                    <input type="email" id="email" name="email" required class="form-control" autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password *</label>
                    <input type="password" id="password" name="password" required class="form-control" autocomplete="new-password">
                </div>

                <label class="terms-consent">
                    <input type="checkbox" name="terms_accepted" value="1" required>
                    <span>
                        I have read and agree to the
                        <a href="../terms.php" target="_blank" rel="noopener">Terms and Conditions and Policy Statement</a>.
                    </span>
                </label>

                <button type="submit" class="btn btn-primary signup-submit">Create Account</button>
            </form>

            <p class="auth-footer">
                Already have an account? <a href="feed.php?action=login">Sign In</a>
            </p>
        </section>
    </div>
</div>
