<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->prepare("SELECT birthdate, gender, province, city, barangay, address, contact_number, division_id, office_id, position FROM users WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$needsProfileCompletion = false;
if (empty($userProfile['birthdate']) || empty($userProfile['gender']) || empty($userProfile['province']) || empty($userProfile['city']) || empty($userProfile['barangay']) || empty($userProfile['address']) || empty($userProfile['contact_number']) || empty($userProfile['division_id']) || empty($userProfile['office_id']) || empty($userProfile['position'])) {
    $needsProfileCompletion = true;
}

// Fetch recent user activities
$recent_activities_stmt = $db->query("
    SELECT ua.*, u.fname, u.lname 
    FROM user_activity ua
    JOIN users u ON ua.user_id = u.user_id
    ORDER BY ua.activity_time DESC
    LIMIT 5
");
$recent_activities = $recent_activities_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="hero" style="min-height: calc(100vh - 200px); padding: 0; background: #f8fafc;">
    <div style="display: flex; min-height: calc(100vh - 200px);">
        
        <!-- Unified Left Sidebar -->
        <aside style="width: 320px; background: white; border-right: 1px solid var(--border-color); padding: 2rem 1.5rem; display: flex; flex-direction: column; gap: 2rem; flex-shrink: 0; overflow-y: auto;">
            
            <!-- Office Staff -->
            <div>
                <h3 style="color: var(--accent-blue); margin-top: 0; margin-bottom: 1.2rem; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Office Staff
                </h3>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php
                    $staff = [
                        ['name' => 'Daniel S. Lerongan, Phd', 'role' => 'Unit Head', 'init' => 'DSL', 'bg' => '#dcfce7', 'text' => '#166534'],
                        ['name' => 'May Rose e. Madrid', 'role' => 'Administrative Office II', 'init' => 'MRM', 'bg' => '#eff6ff', 'text' => '#1e40af'],
                        ['name' => 'Mia Marisol M. Magpulong, LPT', 'role' => 'Administrative Aide VI', 'init' => 'MMM', 'bg' => '#fff7ed', 'text' => '#9a3412'],
                        ['name' => 'Michelle Darlyne B. Ricarte', 'role' => 'Administrative Aide VI', 'init' => 'MBR', 'bg' => '#fdf2f8', 'text' => '#9d174d'],
                        ['name' => 'Fretzel Vann L. Ayo-on, LPT', 'role' => 'Administrative Assistant', 'init' => 'FVA', 'bg' => '#f5f3ff', 'text' => '#5b21b6'],
                        ['name' => 'Teddy Justin C. Bermudo', 'role' => 'Gwapo na Intern', 'init' => 'TJB', 'bg' => '#f8fafc', 'text' => '#334155']
                    ];
                    foreach ($staff as $s): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 32px; height: 32px; background: <?= $s['bg'] ?>; color: <?= $s['text'] ?>; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; flex-shrink: 0;"><?= $s['init'] ?></div>
                        <div>
                            <div style="font-size: 0.8rem; font-weight: 700; color: #1e293b;"><?= $s['name'] ?></div>
                            <div style="font-size: 0.65rem; color: var(--text-secondary);"><?= $s['role'] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quality Policy -->
            <div style="padding-top: 1.5rem; border-top: 1px solid #f1f5f9;">
                <h3 style="color: var(--accent-blue); margin-top: 0; margin-bottom: 1rem; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Quality Policy
                </h3>
                <div style="font-size: 0.75rem; color: var(--text-secondary); line-height: 1.6; font-style: italic; background: #f8fafc; padding: 1.2rem; border-radius: 8px; display: flex; flex-direction: column; gap: 10px;">
                    <p style="margin: 0;">"At Northern Bukidnon State College, we commit to provide gender-responsive and high-quality standards of educational programs and service delivery that fosters diversity, equity, and inclusivity and cultivates the holistic development of lifelong learners to meet the evolving needs of the 21st century workforce."</p>
                    <p style="margin: 0;">"We commit to full adherence to applicable statutory and regulatory requirements and continuous improvement in all our administrative and academic operational processes to satisfy our clients, stakeholders, and the community."</p>
                    <p style="margin: 0;">"We further commit that this policy is communicated, understood, implemented, maintained, and reviewed periodically to ensure clients satisfaction and quality services are at its best!"</p>
                </div>
            </div>

            <!-- Recent Activity -->
            <div style="padding-top: 1.5rem; border-top: 1px solid #f1f5f9;">
                <h3 style="color: var(--accent-blue); margin-top: 0; margin-bottom: 1.2rem; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    Log Feed
                </h3>
                <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <?php foreach ($recent_activities as $ua): ?>
                        <div style="font-size: 0.75rem; line-height: 1.4;">
                            <span style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($ua['fname']) ?></span>
                            <span style="color: #64748b;"><?= htmlspecialchars(mb_strimwidth($ua['activity_description'], 0, 40, "...")) ?></span>
                            <div style="font-size: 0.65rem; color: #94a3b8; margin-top: 2px;"><?= date('M d, h:i A', strtotime($ua['activity_time'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div style="flex: 1; padding: 3rem 5%; overflow-y: auto;">
            
            <header style="margin-bottom: 3rem;">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                    <h1 style="font-size: 2.5rem; margin: 0; color: #0f172a; font-weight: 800;">Welcome back, <?= htmlspecialchars($_SESSION['user_fname'] ?? 'User') ?>!</h1>
                    <span style="background: #eff6ff; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; border: 1px solid #dbeafe;"><?= htmlspecialchars(ucfirst($_SESSION['user_position'] ?? 'User')) ?></span>
                </div>
                <p style="color: #64748b; font-size: 1.1rem; margin: 0;">Institutional Quality Assurance Management System</p>
            </header>

            <!-- Institutional VMGO Section -->
            <section style="margin-bottom: 4rem; display: flex; flex-direction: column; gap: 2.5rem; max-width: 900px;">
                <div>
                    <h4 style="font-size: 0.85rem; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 0.8rem; letter-spacing: 1.5px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 2px; background: var(--accent-gold);"></span>
                        Vision
                    </h4>
                    <p style="font-size: 1.1rem; color: #334155; line-height: 1.7; margin: 0; font-weight: 500;">A recognized institution for inclusive, culturally responsive, and sustainable higher education.</p>
                </div>

                <div>
                    <h4 style="font-size: 0.85rem; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 0.8rem; letter-spacing: 1.5px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 2px; background: var(--accent-gold);"></span>
                        Mission
                    </h4>
                    <p style="font-size: 1.1rem; color: #334155; line-height: 1.7; margin: 0; font-weight: 500;">Northern Bukidnon State College advances excellence in teaching, innovative research, and impactful community service through strategic partnerships, and inclusive and equitable education, empowering transformative development in Bukidnon and Region X.</p>
                </div>

                <div>
                    <h4 style="font-size: 0.85rem; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 0.8rem; letter-spacing: 1.5px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 2px; background: var(--accent-gold);"></span>
                        Institutional Goals
                    </h4>
                    <ul style="padding-left: 0; list-style: none; margin: 0; font-size: 1.05rem; color: #334155; display: flex; flex-direction: column; gap: 12px; font-weight: 500;">
                        <li style="display: flex; gap: 10px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="3" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg> Effective Governance and Efficient Resource Management</li>
                        <li style="display: flex; gap: 10px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="3" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg> Transformative Excellence in Student-Centered Innovation</li>
                        <li style="display: flex; gap: 10px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="3" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg> Vibrant Research Culture and Inclusive Extension Programs</li>
                    </ul>
                </div>

                <div>
                    <h4 style="font-size: 0.85rem; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 1.2rem; letter-spacing: 1.5px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 2px; background: var(--accent-gold);"></span>
                        Core Values (RAISE)
                    </h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px;">
                        <?php foreach(['Responsibility', 'Adaptability', 'Inclusivity', 'Sustainability', 'Excellence'] as $val): ?>
                            <div style="background: white; border: 1px solid #e2e8f0; padding: 12px 20px; border-radius: 10px; font-size: 1rem; font-weight: 700; color: #1e293b; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 4px;">
                                <span style="color: var(--accent-blue);"><?= substr($val, 0, 1) ?></span><?= substr($val, 1) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- Activity Modules Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
                <a href="feed.php?action=activity" class="feature-card" style="text-decoration: none; padding: 2rem; display: flex; flex-direction: column; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; border: 1px solid var(--border-color); background: white; border-radius: 12px;">
                    <div style="width: 45px; height: 45px; background: #eff6ff; color: var(--accent-blue); border-radius: 10px; display: flex; align-items: center; justify-content: center;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg></div>
                    <h3 style="margin: 0; color: #0f172a; font-size: 1.25rem;">Activity Evaluation</h3>
                    <p style="margin: 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">Review and process participant evaluations from recent activities.</p>
                </a>
                <a href="feed.php?action=accreditation" class="feature-card" style="text-decoration: none; padding: 2rem; display: flex; flex-direction: column; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; border: 1px solid var(--border-color); background: white; border-radius: 12px;">
                    <div style="width: 45px; height: 45px; background: #fff7ed; color: #ea580c; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                    <h3 style="margin: 0; color: #0f172a; font-size: 1.25rem;">Accreditation Tracker</h3>
                    <p style="margin: 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">Monitor program compliance and document submission statuses.</p>
                </a>
                <a href="feed.php?action=document" class="feature-card" style="text-decoration: none; padding: 2rem; display: flex; flex-direction: column; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; border: 1px solid var(--border-color); background: white; border-radius: 12px;">
                    <div style="width: 45px; height: 45px; background: #f0fdf4; color: #16a34a; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></div>
                    <h3 style="margin: 0; color: #0f172a; font-size: 1.25rem;">Document Mapping</h3>
                    <p style="margin: 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">Manage and map institutional documents to standards.</p>
                </a>
            </div>

        </div>
    </div>
</main>

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
                        <input type="date" id="birthdate" name="birthdate" value="<?= htmlspecialchars($userProfile['birthdate'] ?? '') ?>" required class="form-control">
                    </div>
                    <div style="flex: 1;">
                        <label for="gender"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Gender
                            *</label>
                        <select id="gender" name="gender" required class="form-control">
                            <option value="">Select Gender</option>
                            <option value="Male" <?= ($userProfile['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($userProfile['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Others" <?= ($userProfile['gender'] ?? '') === 'Others' ? 'selected' : '' ?>>Others</option>
                        </select>
                    </div>
                </div>

                <!-- Philippine Address Selectors -->
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="province"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Province
                            *</label>
                        <select id="province" name="province" required data-selected="<?= htmlspecialchars($userProfile['province'] ?? '') ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Province...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="city"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">City
                            / Municipality *</label>
                        <select id="city" name="city" required disabled data-selected="<?= htmlspecialchars($userProfile['city'] ?? '') ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select City...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="barangay"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Barangay
                            *</label>
                        <select id="barangay" name="barangay" required disabled data-selected="<?= htmlspecialchars($userProfile['barangay'] ?? '') ?>"
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
                        onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';"><?= htmlspecialchars($userProfile['address'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="contact_number"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Contact
                            Number *</label>
                        <input type="tel" id="contact_number" maxlength="11" name="contact_number" 
                            value="<?= !empty($userProfile['contact_number']) ? htmlspecialchars($userProfile['contact_number']) : '09' ?>" required
                            class="form-control">
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="division_id"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Division
                            *</label>
                        <select id="division_id" name="division_id" required class="form-control" data-selected="<?= htmlspecialchars($userProfile['division_id'] ?? '') ?>">
                            <option value="">Select Division...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="office_id"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Office
                            *</label>
                        <select id="office_id" name="office_id" required disabled class="form-control" data-selected="<?= htmlspecialchars($userProfile['office_id'] ?? '') ?>">
                            <option value="">Select Office...</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <label for="position"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Position
                        *</label>
                    <input type="text" id="position" name="position" value="<?= htmlspecialchars($userProfile['position'] ?? '') ?>" required class="form-control">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.05rem;">Save
                    Profile</button>
            </form>
        </div>
    </div>


<?php endif; ?>