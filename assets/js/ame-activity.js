/**
 * Activity list actions (dropdown, view, delete).
 * Loaded as an external script so handlers work even if a later inline script fails to parse.
 */
(function () {
    window.toggleDropdown = function (id, e) {
        var evt = e || window.event;
        if (evt && evt.stopPropagation) {
            evt.stopPropagation();
        }
        var menu = document.getElementById('dropdown-' + id);
        if (!menu) {
            return;
        }
        document.querySelectorAll('.dropdown-menu').forEach(function (m) {
            if (m.id !== 'dropdown-' + id) {
                m.style.display = 'none';
            }
        });
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    };

    window.viewActivity = function (id) {
        window.location.href = 'feed.php?action=view_activity&id=' + id;
    };

    window.deleteActivity = function (id) {
        if (confirm('Are you sure you want to delete this activity?')) {
            window.location.href = '../api/activities.php?action=delete&id=' + id;
        }
    };

    document.addEventListener('click', function () {
        document.querySelectorAll('.dropdown-menu').forEach(function (m) {
            m.style.display = 'none';
        });
    });
})();
