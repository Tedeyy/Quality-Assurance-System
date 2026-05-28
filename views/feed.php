<?php
session_start();

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/component/access.php';

/**
 * Show a readable error in the content area when a page crashes (common on shared hosting with display_errors off).
 */
function qa_render_page_error(string $title, string $detail = ''): void {
    echo '<main class="hero" style="display:block;padding:2rem;min-height:50vh;">';
    echo '<div class="container" style="max-width:720px;margin:0 auto;">';
    echo '<div style="background:#fee2e2;color:#991b1b;padding:1.25rem 1.5rem;border-radius:10px;border:1px solid #f87171;">';
    echo '<strong style="display:block;margin-bottom:0.5rem;">' . htmlspecialchars($title) . '</strong>';
    if ($detail !== '') {
        echo '<p style="margin:0;font-size:0.9rem;line-height:1.5;">' . htmlspecialchars($detail) . '</p>';
    }
    echo '<p style="margin:0.75rem 0 0;font-size:0.85rem;color:#7f1d1d;">';
    echo 'If this is on InfinityFree: confirm <code>.env</code> is in <code>htdocs</code>, the database exists, and table names match (e.g. <code>sdgs</code>, not <code>SDGs</code>).';
    echo '</p></div></div></main>';
}

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!empty($GLOBALS['qa_page_rendered'])) {
        return;
    }
    if (headers_sent()) {
        echo '<main class="hero" style="display:block;padding:2rem;">';
    }
    qa_render_page_error(
        'This page stopped loading due to a server error.',
        ($err['message'] ?? 'Unknown error') . ' (' . basename($err['file'] ?? '') . ':' . ($err['line'] ?? 0) . ')'
    );
});

$action = $_GET['action'] ?? '';
$can_access_all_modules = false;
$restricted_to_accreditation_tracking = false;

if (isset($_SESSION['user_id'])) {
    $db = $db ?? (new Database())->getConnection();
    $can_access_all_modules = qa_current_user_can_access_all_modules($db);
    $restricted_to_accreditation_tracking = !$can_access_all_modules;

    if ($restricted_to_accreditation_tracking && !in_array($action, ['dashboard', '', 'accreditation', 'login', 'signup'], true)) {
        $action = 'accreditation';
        $_GET['action'] = 'accreditation';
    }
}

require_once __DIR__ . '/component/header.php';
require_once __DIR__ . '/component/navbar.php';
require_once __DIR__ . '/component/confirmationpane.php';

// Display any session messages
if (isset($_SESSION['error'])) {
    echo '<div style="background-color: #fee2e2; color: #991b1b; padding: 1rem; text-align: center; font-weight: 500; border-bottom: 1px solid #f87171;">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    echo '<div style="background-color: #dcfce7; color: #166534; padding: 1rem; text-align: center; font-weight: 500; border-bottom: 1px solid #4ade80;">' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}

$content_file = null;
if (isset($_SESSION['user_id'])) {
    if ($action === 'dashboard' || $action === '' || $action === 'login' || $action === 'signup') {
        $content_file = __DIR__ . '/content/dashboard.php';
    } elseif ($action === 'accreditation') {
        $content_file = __DIR__ . '/content/accreditation/acctracker.php';
    } elseif ($action === 'accmasterlist') {
        $content_file = __DIR__ . '/content/accreditation/accmasterlist.php';
    } elseif ($action === 'accmapping') {
        $content_file = __DIR__ . '/content/accreditation/accmapping.php';
    } elseif ($action === 'activity') {
        $content_file = __DIR__ . '/content/ame/activityevaluation.php';
    } elseif ($action === 'actmasterlist') {
        $content_file = __DIR__ . '/content/ame/actmasterlist.php';
    } elseif ($action === 'evaluationmonitoring') {
        $content_file = __DIR__ . '/content/ame/evaluationmonitoring.php';
    } elseif ($action === 'view_activity') {
        $content_file = __DIR__ . '/content/ame/activitypane.php';
    } elseif ($action === 'evaluations' || $action === 'respondents') {
        $content_file = __DIR__ . '/content/ame/evaluations.php';
    } elseif ($action === 'document') {
        $content_file = __DIR__ . '/content/document/doctracker.php';
    } elseif ($action === 'docmasterlist') {
        $content_file = __DIR__ . '/content/document/docmasterlist.php';
    } elseif ($action === 'doclinkage') {
        $content_file = __DIR__ . '/content/document/doclinkage.php';
    } else {
        $content_file = __DIR__ . '/content/dashboard.php';
    }
} else {
    $content_file = ($action === 'signup')
        ? __DIR__ . '/content/auth/signup.php'
        : __DIR__ . '/content/auth/login.php';
}

if ($content_file && is_readable($content_file)) {
    try {
        require $content_file;
        $GLOBALS['qa_page_rendered'] = true;
    } catch (Throwable $e) {
        error_log('feed.php content error: ' . $e->getMessage());
        qa_render_page_error('Page could not load.', $e->getMessage());
        $GLOBALS['qa_page_rendered'] = true;
    }
} else {
    qa_render_page_error('Page not found.', 'Missing view: ' . ($content_file ?? 'unknown'));
    $GLOBALS['qa_page_rendered'] = true;
}

require_once __DIR__ . '/component/footer.php';
?>
