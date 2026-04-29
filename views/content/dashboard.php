<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->prepare("SELECT birthdate, gender, province, city, barangay, address, contact_number, office, position FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$needsProfileCompletion = false;
if (empty($userProfile['birthdate']) || empty($userProfile['gender']) || empty($userProfile['province']) || empty($userProfile['city']) || empty($userProfile['barangay']) || empty($userProfile['address']) || empty($userProfile['contact_number']) || empty($userProfile['office']) || empty($userProfile['position'])) {
    $needsProfileCompletion = true;
}
?>
<main class="hero" style="min-height: calc(100vh - 200px); padding: 4rem 5%;">
    <div style="max-width: 1000px; margin: 0 auto;">

        <div class="dashboard-header">
            <h1>
                Welcome, <?= htmlspecialchars($_SESSION['user_fname'] ?? 'User') ?>!
            </h1>
            <span class="user-badge">
                <?= htmlspecialchars(ucfirst($_SESSION['user_position'] ?? 'User')) ?>
            </span>
        </div>

        <div class="dashboard-grid">
            <!-- Activity Module -->
            <div class="feature-card">
                <div class="feature-icon" style="margin-bottom: 1.5rem; display: inline-flex;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                    </svg>
                </div>
                <h3 style="color: var(--accent-blue); margin-bottom: 0.5rem; font-size: 1.25rem;">Activity Evaluation
                </h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Review and process participant
                    evaluations from recent activities.</p>
                <a href="feed.php?action=activity"
                    style="color: var(--gold-accent); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                    View Activities
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            </div>

            <!-- Accreditation Tracker -->
            <div class="feature-card">
                <div class="feature-icon" style="margin-bottom: 1.5rem; display: inline-flex;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <h3 style="color: var(--accent-blue); margin-bottom: 0.5rem; font-size: 1.25rem;">Accreditation Tracker
                </h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Monitor program compliance and document
                    submission statuses.</p>
                <a href="feed.php?action=accreditation"
                    style="color: var(--gold-accent); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                    Track Progress
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            </div>

            <!-- Document Mapping -->
            <div class="feature-card">
                <div class="feature-icon" style="margin-bottom: 1.5rem; display: inline-flex;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <h3 style="color: var(--accent-blue); margin-bottom: 0.5rem; font-size: 1.25rem;">Document Mapping
                </h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Manage and map institutional documents
                    to standards.</p>
                <a href="feed.php?action=document"
                    style="color: var(--gold-accent); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                    View Documents
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</main>

<?php if ($needsProfileCompletion): ?>
    <!-- Profile Completion Modal -->
    <div id="profileModal" class="modal-overlay">
        <div class="modal-content">
            <h2 style="color: var(--accent-blue); margin-bottom: 0.5rem; text-align: center;">Complete Your Profile</h2>
            <p style="color: var(--text-secondary); margin-bottom: 2rem; text-align: center;">Please provide your
                demographic and geographic information.</p>

            <form action="../api/auth.php?action=complete_profile" method="POST" style="text-align: left;">
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="birthdate"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Birthdate
                            *</label>
                        <input type="date" id="birthdate" name="birthdate" required
                            class="form-control">
                    </div>
                    <div style="flex: 1;">
                        <label for="gender"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Gender
                            *</label>
                        <select id="gender" name="gender" required
                            class="form-control">
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                </div>

                <!-- Philippine Address Selectors -->
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="province"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Province
                            *</label>
                        <select id="province" name="province" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Province...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="city"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">City
                            / Municipality *</label>
                        <select id="city" name="city" required disabled
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select City...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="barangay"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Barangay
                            *</label>
                        <select id="barangay" name="barangay" required disabled
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Barangay...</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="address"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Street
                        / Building Name *</label>
                    <textarea id="address" name="address" rows="2" required placeholder="123 Example Street, Apt 4B..."
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; resize: vertical;"
                        onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                        onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';"></textarea>
                </div>

                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="contact_number"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Contact
                            Number *</label>
                        <input type="tel" id="contact_number" maxlength="11" name="contact_number" value="09" required
                            class="form-control">
                    </div>
                    <div style="flex: 1;">
                        <label for="office"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Office
                            *</label>
                        <input type="text" id="office" name="office" required
                            class="form-control">
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <label for="position"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Position
                        *</label>
                    <input type="text" id="position" name="position" required
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s;"
                        onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                        onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.05rem;">Save
                    Profile</button>
            </form>
        </div>
    </div>


<?php endif; ?>