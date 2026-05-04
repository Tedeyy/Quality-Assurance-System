<!-- Custom Confirmation/Notification Pane -->
<div id="confirmationPane" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content" style="max-width: 400px; text-align: center; padding: 2rem;">
        <div id="confirmIcon" style="margin-bottom: 1.5rem;">
            <!-- Icon will be injected by JS -->
        </div>
        
        <h2 id="confirmTitle" style="margin-bottom: 0.5rem; color: var(--text-primary);">Confirm Action</h2>
        <p id="confirmMessage" style="color: var(--text-secondary); margin-bottom: 2rem; line-height: 1.5;"></p>
        
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button id="confirmCancelBtn" class="btn" style="background: #f1f5f9; color: var(--text-secondary); flex: 1; padding: 0.8rem;" onclick="hideConfirmation()">Cancel</button>
            <button id="confirmActionBtn" class="btn btn-primary" style="flex: 1; padding: 0.8rem;">Confirm</button>
        </div>
    </div>
</div>

<script>
    let confirmCallback = null;

    function showConfirmation({ title, message, type, actionLabel, onConfirm }) {
        const pane = document.getElementById('confirmationPane');
        const titleEl = document.getElementById('confirmTitle');
        const messageEl = document.getElementById('confirmMessage');
        const iconEl = document.getElementById('confirmIcon');
        const actionBtn = document.getElementById('confirmActionBtn');
        const cancelBtn = document.getElementById('confirmCancelBtn');

        // Reset and set content
        titleEl.innerText = title || (type === 'success' ? 'Success' : 'Are you sure?');
        messageEl.innerText = message || '';
        actionBtn.innerText = actionLabel || 'Confirm';
        
        // Action-specific styling
        if (type === 'danger') {
            actionBtn.style.background = '#ef4444';
            actionBtn.innerText = actionLabel || (onConfirm ? 'Delete' : 'OK');
            iconEl.innerHTML = `<div style="width: 60px; height: 60px; background: #fef2f2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>`;
            cancelBtn.style.display = onConfirm ? 'block' : 'none';
        } else if (type === 'success') {
            actionBtn.style.background = '#22c55e';
            actionBtn.innerText = actionLabel || 'OK';
            iconEl.innerHTML = `<div style="width: 60px; height: 60px; background: #f0fdf4; color: #22c55e; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>`;
            cancelBtn.style.display = 'none';
        } else {
            actionBtn.style.background = 'var(--accent-blue)';
            actionBtn.innerText = actionLabel || (onConfirm ? 'Confirm' : 'OK');
            iconEl.innerHTML = `<div style="width: 60px; height: 60px; background: #eff6ff; color: var(--accent-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            </div>`;
            cancelBtn.style.display = onConfirm ? 'block' : 'none';
        }

        pane.style.display = 'flex';
        
        // Use a local copy of the callback to avoid closure/timing issues
        const currentCallback = onConfirm;
        
        actionBtn.onclick = (e) => {
            e.preventDefault();
            hideConfirmation();
            if (currentCallback && typeof currentCallback === 'function') {
                currentCallback();
            }
        };
    }

    function hideConfirmation() {
        document.getElementById('confirmationPane').style.display = 'none';
        confirmCallback = null;
    }
</script>
