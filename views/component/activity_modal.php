<!-- Activity Modal Component -->
<div id="addActivityModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: white; padding: 2rem; border-radius: 12px; width: 600px; max-width: 90vw; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 id="modalTitle" style="margin: 0; color: var(--accent-blue);">Create New Activity</h2>
            <button onclick="document.getElementById('addActivityModal').style.display='none'" style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        
        <form id="addActivityForm" method="POST" action="../api/activities.php?action=create">
            <input type="hidden" name="activity_id" id="edit_activity_id">
            <input type="hidden" name="redirect_url" id="redirect_url" value="">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; margin-bottom: 1.2rem;">
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Activity Title *</label>
                    <input type="text" name="title" placeholder="Enter activity title" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Requesting Office *</label>
                    <select name="requesting_office_id" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; background: white;">
                        <option value="">Select Office</option>
                        <?php 
                        $offices_stmt = $db->query("SELECT office_id, name, acronym FROM divisions_offices ORDER BY name ASC");
                        while($office = $offices_stmt->fetch(PDO::FETCH_ASSOC)): 
                        ?>
                            <option value="<?= $office['office_id'] ?>" <?= (isset($_SESSION['user_office_id']) && $_SESSION['user_office_id'] == $office['office_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($office['name']) ?> (<?= htmlspecialchars($office['acronym']) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.8rem;">Sustainable Development Goals (SDGs)</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; background: #f8fafc; padding: 1.2rem; border-radius: 8px; border: 1px solid var(--border-color); max-height: 150px; overflow-y: auto;">
                        <?php 
                        $sdgs_stmt = $db->query("SELECT sdg_id, title FROM SDGs ORDER BY sdg_id ASC");
                        while($sdg = $sdgs_stmt->fetch(PDO::FETCH_ASSOC)): 
                        ?>
                            <label style="display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; cursor: pointer; padding: 8px; border-radius: 6px; transition: background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="sdg_ids[]" value="<?= $sdg['sdg_id'] ?>" style="margin-top: 2px; width: 17px; height: 17px; cursor: pointer; accent-color: var(--accent-blue);">
                                <span style="color: #334155; line-height: 1.4;"><b>SDG <?= $sdg['sdg_id'] ?></b>: <?= htmlspecialchars($sdg['title']) ?></span>
                            </label>
                        <?php endwhile; ?>
                    </div>
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.8rem;">Target Participants</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; background: #f8fafc; padding: 1.2rem; border-radius: 8px; border: 1px solid var(--border-color); max-height: 150px; overflow-y: auto;">
                        <?php 
                        $target_groups = ['Everyone', 'Student', 'Non-teaching Faculty', 'Teaching Faculty', 'Staff', 'Stakeholders', 'Out of School Youth', 'Guests', 'Others'];
                        foreach($target_groups as $group): 
                        ?>
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem; cursor: pointer; padding: 8px; border-radius: 6px; transition: background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="target_groups[]" value="<?= htmlspecialchars($group) ?>" style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--accent-blue);">
                                <span style="color: #334155;"><?= htmlspecialchars($group) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Estimated Number of Participants</label>
                    <input type="number" name="number_of_participants" placeholder="e.g. 50" min="0" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div style="grid-column: span 2;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <label style="font-size: 0.9rem; font-weight: 600;">Facilitators (Speakers/Organizers)</label>
                        <button type="button" onclick="addFacilitator()" style="background: var(--accent-blue); color: white; border: none; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Add More
                        </button>
                    </div>
                    <div id="facilitatorsContainer" style="display: flex; flex-direction: column; gap: 10px;">
                        <!-- Facilitator rows will be added here -->
                    </div>
                </div>
                <div>
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Event Date *</label>
                    <input type="date" name="eventdate" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Status</label>
                    <select name="eventstatus" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; background: white;">
                        <option value="Pending">Upcoming</option>
                        <option value="Ongoing">In Progress</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Venue/Location</label>
                    <input type="text" name="eventvenue" placeholder="e.g. Conference Room A, Zoom Link, etc." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Description</label>
                    <textarea name="description" placeholder="Briefly describe the activity..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; height: 100px; resize: none;"></textarea>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" onclick="document.getElementById('addActivityModal').style.display='none'" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="submitBtn" class="btn btn-primary">Create Activity</button>
            </div>
        </form>
    </div>
</div>

<script>
    function addFacilitator(name = '', role = 'speaker') {
        const container = document.getElementById('facilitatorsContainer');
        const row = document.createElement('div');
        row.className = 'facilitator-row';
        row.style.cssText = 'display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); animation: slideIn 0.2s ease-out;';
        row.innerHTML = `
            <div style="flex: 1;">
                <input type="text" name="facilitator_names[]" placeholder="Full Name" value="${name}" required style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px; outline: none; font-size: 0.85rem;">
            </div>
            <div style="width: 130px;">
                <select name="facilitator_roles[]" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px; outline: none; background: white; font-size: 0.85rem; cursor: pointer;">
                    <option value="speaker" ${role === 'speaker' ? 'selected' : ''}>Speaker</option>
                    <option value="organizer" ${role === 'organizer' ? 'selected' : ''}>Organizer</option>
                </select>
            </div>
            <button type="button" onclick="this.closest('.facilitator-row').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
        `;
        container.appendChild(row);
    }

    function openAddModal(redirectUrl = '') {
        const modal = document.getElementById('addActivityModal');
        const form = document.getElementById('addActivityForm');
        form.reset();
        form.action = '../api/activities.php?action=create';
        document.getElementById('edit_activity_id').value = '';
        document.getElementById('redirect_url').value = redirectUrl;
        document.getElementById('modalTitle').textContent = 'Create New Activity';
        document.getElementById('submitBtn').textContent = 'Create Activity';
        document.getElementById('facilitatorsContainer').innerHTML = '';
        addFacilitator();
        modal.style.display = 'flex';
    }

    async function editActivity(id, redirectUrl = '') {
        try {
            const response = await fetch('../api/activities.php?action=get&id=' + id);
            if (!response.ok) throw new Error('Failed to fetch activity details');
            const data = await response.json();

            const modal = document.getElementById('addActivityModal');
            const form = document.getElementById('addActivityForm');
            form.reset();
            form.action = '../api/activities.php?action=update';
            
            document.getElementById('edit_activity_id').value = data.activity_id;
            document.getElementById('redirect_url').value = redirectUrl;
            document.getElementById('modalTitle').textContent = 'Edit Activity';
            document.getElementById('submitBtn').textContent = 'Save Changes';
            
            form.querySelector('[name="title"]').value = data.title;
            form.querySelector('[name="description"]').value = data.description;
            form.querySelector('[name="eventdate"]').value = data.eventdate;
            form.querySelector('[name="eventstatus"]').value = data.eventstatus;
            form.querySelector('[name="eventvenue"]').value = data.eventvenue;
            form.querySelector('[name="requesting_office_id"]').value = data.requesting_office_id;
            form.querySelector('[name="number_of_participants"]').value = data.number_of_participants;

            // Set SDGs
            const sdgCheckboxes = form.querySelectorAll('[name="sdg_ids[]"]');
            sdgCheckboxes.forEach(cb => {
                cb.checked = data.sdg_ids.includes(cb.value);
            });

            // Set Target Groups
            const tgCheckboxes = form.querySelectorAll('[name="target_groups[]"]');
            tgCheckboxes.forEach(cb => {
                cb.checked = data.target_groups.includes(cb.value);
            });

            // Set Facilitators
            const container = document.getElementById('facilitatorsContainer');
            container.innerHTML = '';
            
            if (data.speaker) {
                data.speaker.split(', ').forEach(name => addFacilitator(name, 'speaker'));
            }
            if (data.organizer) {
                data.organizer.split(', ').forEach(name => addFacilitator(name, 'organizer'));
            }
            if (!data.speaker && !data.organizer) {
                addFacilitator();
            }

            modal.style.display = 'flex';
        } catch (error) {
            console.error('Error:', error);
            alert('Error loading activity details: ' + error.message);
        }
    }

    // Close modal on click outside
    window.onclick = function(event) {
        const modal = document.getElementById('addActivityModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
</script>
