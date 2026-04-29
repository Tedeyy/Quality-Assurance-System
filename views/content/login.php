    <main class="hero" style="min-height: calc(100vh - 350px); padding: 4rem 5%;">
        <div class="feature-card" style="max-width: 450px; width: 100%; margin: 0 auto; text-align: center;">
            <div class="feature-icon" style="margin-bottom: 1rem;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                    <polyline points="10 17 15 12 10 7"></polyline>
                    <line x1="15" y1="12" x2="3" y2="12"></line>
                </svg>
            </div>
            <h2 style="margin-bottom: 0.5rem; color: var(--accent-blue);">Welcome Back</h2>
            <p style="color: var(--text-secondary); margin-bottom: 2.5rem;">Sign in to your QA System account</p>
            
            <form action="#" method="POST" style="text-align: left;">
                <div style="margin-bottom: 1.5rem;">
                    <label for="email" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Email Address</label>
                    <input type="email" id="email" name="email" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s;" onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                </div>
                <div style="margin-bottom: 2rem;">
                    <label for="password" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Password</label>
                    <input type="password" id="password" name="password" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s;" onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.05rem;">Sign In</button>
            </form>
            <p style="margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-secondary);">
                Don't have an account? <a href="#" style="color: var(--accent-blue); font-weight: 600;">Contact Administrator</a>
            </p>
        </div>
    </main>