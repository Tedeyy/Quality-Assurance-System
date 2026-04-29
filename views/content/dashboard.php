<main class="hero" style="min-height: calc(100vh - 200px); padding: 4rem 5%;">
    <div style="max-width: 1000px; margin: 0 auto;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h1 style="color: var(--accent-blue); font-size: 2.5rem;">
                Welcome, <?= htmlspecialchars($_SESSION['user_fname'] ?? 'User') ?>!
            </h1>
            <span style="background-color: var(--gold-accent); color: #fff; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem;">
                <?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'User')) ?>
            </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
            <!-- Activity Module -->
            <div class="feature-card" style="text-align: left; transition: transform 0.3s; cursor: pointer;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='none'">
                <div class="feature-icon" style="margin-bottom: 1.5rem; display: inline-flex;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                    </svg>
                </div>
                <h3 style="color: var(--accent-blue); margin-bottom: 0.5rem; font-size: 1.25rem;">Activity Evaluation</h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Review and process participant evaluations from recent activities.</p>
                <a href="#" style="color: var(--gold-accent); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                    View Activities 
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>

            <!-- Accreditation Tracker -->
            <div class="feature-card" style="text-align: left; transition: transform 0.3s; cursor: pointer;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='none'">
                <div class="feature-icon" style="margin-bottom: 1.5rem; display: inline-flex;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <h3 style="color: var(--accent-blue); margin-bottom: 0.5rem; font-size: 1.25rem;">Accreditation Tracker</h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Monitor program compliance and document submission statuses.</p>
                <a href="#" style="color: var(--gold-accent); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                    Track Progress 
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            
            <!-- Quick Actions -->
            <div class="feature-card" style="text-align: left; background: linear-gradient(135deg, rgba(0,28,87,0.02) 0%, rgba(223,182,65,0.05) 100%);">
                <h3 style="color: var(--accent-blue); margin-bottom: 1rem; font-size: 1.25rem;">Quick Actions</h3>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 0.8rem;">
                        <a href="#" style="color: var(--text-primary); text-decoration: none; display: flex; align-items: center; gap: 10px; font-weight: 500;">
                            <div style="background: rgba(0,28,87,0.1); padding: 5px; border-radius: 5px; color: var(--accent-blue);">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </div>
                            Submit Feedback
                        </a>
                    </li>
                    <li style="margin-bottom: 0.8rem;">
                        <a href="#" style="color: var(--text-primary); text-decoration: none; display: flex; align-items: center; gap: 10px; font-weight: 500;">
                            <div style="background: rgba(0,28,87,0.1); padding: 5px; border-radius: 5px; color: var(--accent-blue);">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </div>
                            Update Profile
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        
    </div>
</main>
