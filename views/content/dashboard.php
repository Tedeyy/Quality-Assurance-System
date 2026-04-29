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

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h1 style="color: var(--accent-blue); font-size: 2.5rem;">
                Welcome, <?= htmlspecialchars($_SESSION['user_fname'] ?? 'User') ?>!
            </h1>
            <span
                style="background-color: var(--gold-accent); color: #fff; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem;">
                <?= htmlspecialchars(ucfirst($_SESSION['user_position'] ?? 'User')) ?>
            </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
            <!-- Activity Module -->
            <div class="feature-card" style="text-align: left; transition: transform 0.3s; cursor: pointer;"
                onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='none'">
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
                <a href="#"
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
            <div class="feature-card" style="text-align: left; transition: transform 0.3s; cursor: pointer;"
                onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='none'">
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
                <a href="#"
                    style="color: var(--gold-accent); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                    Track Progress
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            </div>

            <!-- Quick Actions -->
            <div class="feature-card"
                style="text-align: left; background: linear-gradient(135deg, rgba(0,28,87,0.02) 0%, rgba(223,182,65,0.05) 100%);">
                <h3 style="color: var(--accent-blue); margin-bottom: 1rem; font-size: 1.25rem;">Quick Actions</h3>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 0.8rem;">
                        <a href="#"
                            style="color: var(--text-primary); text-decoration: none; display: flex; align-items: center; gap: 10px; font-weight: 500;">
                            <div
                                style="background: rgba(0,28,87,0.1); padding: 5px; border-radius: 5px; color: var(--accent-blue);">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </div>
                            Submit Feedback
                        </a>
                    </li>
                    <li style="margin-bottom: 0.8rem;">
                        <a href="#"
                            style="color: var(--text-primary); text-decoration: none; display: flex; align-items: center; gap: 10px; font-weight: 500;">
                            <div
                                style="background: rgba(0,28,87,0.1); padding: 5px; border-radius: 5px; color: var(--accent-blue);">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            </div>
                            Update Profile
                        </a>
                    </li>
                </ul>
            </div>
        </div>

    </div>
</main>

<?php if ($needsProfileCompletion): ?>
    <!-- Profile Completion Modal -->
    <div id="profileModal"
        style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,28,87,0.7); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(5px);">
        <div
            style="background: white; padding: 2.5rem; border-radius: 8px; max-width: 700px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto;">
            <h2 style="color: var(--accent-blue); margin-bottom: 0.5rem; text-align: center;">Complete Your Profile</h2>
            <p style="color: var(--text-secondary); margin-bottom: 2rem; text-align: center;">Please provide your demographic and geographic information.</p>

            <form action="../api/auth.php?action=complete_profile" method="POST" style="text-align: left;">
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="birthdate"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Birthdate
                            *</label>
                        <input type="date" id="birthdate" name="birthdate" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s;"
                            onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                            onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                    </div>
                    <div style="flex: 1;">
                        <label for="gender"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Gender
                            *</label>
                        <select id="gender" name="gender" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;"
                            onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                            onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
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
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Province *</label>
                        <select id="province" name="province" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Province...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="city"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">City / Municipality *</label>
                        <select id="city" name="city" required disabled
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select City...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="barangay"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Barangay *</label>
                        <select id="barangay" name="barangay" required disabled
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Barangay...</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="address"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Street / Building Name *</label>
                    <textarea id="address" name="address" rows="2" required placeholder="123 Example Street, Apt 4B..."
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; resize: vertical;"
                        onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                        onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';"></textarea>
                </div>

                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="contact_number"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Contact Number *</label>
                        <input type="tel" id="contact_number" maxlength="11" name="contact_number" value="09" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s;"
                            onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                            onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                    </div>
                    <div style="flex: 1;">
                        <label for="office"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Office *</label>
                        <input type="text" id="office" name="office" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s;"
                            onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                            onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <label for="position"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Position *</label>
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

    <script>
        document.body.style.overflow = 'hidden';

        // Initialize PSGC Address Selectors
        document.addEventListener('DOMContentLoaded', async () => {
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');

            // Helper to sort alphabetically by name
            const sortByName = (a, b) => a.name.localeCompare(b.name);

            try {
                // 1. Fetch Provinces
                const provResponse = await fetch('https://psgc.gitlab.io/api/provinces/');
                let provinces = await provResponse.json();
                
                // Fetch NCR Districts (since Metro Manila is not a province)
                const ncrResponse = await fetch('https://psgc.gitlab.io/api/regions/130000000/districts/');
                const ncrDistricts = await ncrResponse.json();
                
                // Combine and sort
                const allProvinces = [...provinces, ...ncrDistricts].sort(sortByName);

                allProvinces.forEach(p => {
                    const option = document.createElement('option');
                    option.value = p.name;
                    option.textContent = p.name;
                    option.dataset.code = p.code;
                    option.dataset.isDistrict = (p.regionCode === "130000000");
                    provinceSelect.appendChild(option);
                });

                // 2. Handle Province Change
                provinceSelect.addEventListener('change', async function() {
                    citySelect.innerHTML = '<option value="">Loading...</option>';
                    citySelect.disabled = true;
                    barangaySelect.innerHTML = '<option value="">Select Barangay...</option>';
                    barangaySelect.disabled = true;

                    const selectedOption = this.options[this.selectedIndex];
                    if (!selectedOption.value) {
                        citySelect.innerHTML = '<option value="">Select City...</option>';
                        return;
                    }

                    const code = selectedOption.dataset.code;
                    const isDistrict = selectedOption.dataset.isDistrict === "true";
                    
                    // Fetch Cities
                    const endpoint = isDistrict 
                        ? `https://psgc.gitlab.io/api/districts/${code}/cities-municipalities/`
                        : `https://psgc.gitlab.io/api/provinces/${code}/cities-municipalities/`;

                    const cityResponse = await fetch(endpoint);
                    const cities = await cityResponse.json();
                    cities.sort(sortByName);

                    citySelect.innerHTML = '<option value="">Select City...</option>';
                    cities.forEach(c => {
                        const option = document.createElement('option');
                        option.value = c.name;
                        option.textContent = c.name;
                        option.dataset.code = c.code;
                        citySelect.appendChild(option);
                    });
                    citySelect.disabled = false;
                });

                // 3. Handle City Change
                citySelect.addEventListener('change', async function() {
                    barangaySelect.innerHTML = '<option value="">Loading...</option>';
                    barangaySelect.disabled = true;

                    const selectedOption = this.options[this.selectedIndex];
                    if (!selectedOption.value) {
                        barangaySelect.innerHTML = '<option value="">Select Barangay...</option>';
                        return;
                    }

                    const code = selectedOption.dataset.code;
                    
                    // Fetch Barangays
                    const brgyResponse = await fetch(`https://psgc.gitlab.io/api/cities-municipalities/${code}/barangays/`);
                    const barangays = await brgyResponse.json();
                    barangays.sort(sortByName);

                    barangaySelect.innerHTML = '<option value="">Select Barangay...</option>';
                    barangays.forEach(b => {
                        const option = document.createElement('option');
                        option.value = b.name;
                        option.textContent = b.name;
                        barangaySelect.appendChild(option);
                    });
                    barangaySelect.disabled = false;
                });

            } catch (error) {
                console.error("Error loading PSGC data:", error);
                provinceSelect.innerHTML = '<option value="">Error loading locations. Please refresh.</option>';
            }
        });
    </script>
<?php endif; ?>