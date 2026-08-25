<?php
// global.php
// session_start();

// Catch any exception that escapes the app (e.g. a PDOException from an
// unvalidated foreign key) and show a generic message instead of leaking
// internal details, while still logging the real error server-side.
set_exception_handler(function ($e) {
    error_log('Uncaught exception: ' . $e->getMessage());
    http_response_code(500);
    echo 'Something went wrong. Please try again later.';
});

// Start (or resume) the session with hardened cookie params. Must be called
// before any session_start() so cookie params can still be set.
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Generate (or reuse) a per-session CSRF token.
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify a submitted CSRF token against the one stored in the session.
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

$servername = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "username"; // Your database username
$password = getenv('DB_PASSWORD') ?: "password"; // Your database password
$dbname = getenv('DB_NAME') ?: "url_list";

// Create connection using PDO for better security and error handling
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('A database error occurred. Please try again later.'); }

// Function to fetch categories. "General" is always pinned first; the rest
// follow the admin-defined manual sort_order.
function getCategories($conn) {
    $stmt = $conn->query("SELECT * FROM categories ORDER BY (name = 'General') DESC, sort_order ASC, name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch URLs, grouped by category in the same manual order as
// getCategories(), with each category's URLs sorted by click count.
function getUrls($conn) {
    $sql = "SELECT categories.name as category, urls.id, urls.url, urls.click_count, description, urls.category_id FROM urls JOIN categories ON urls.category_id = categories.id ORDER BY (categories.name = 'General') DESC, categories.sort_order ASC, categories.name ASC, urls.click_count DESC";
    $stmt = $conn->query($sql);
    $urls = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $urls[$row['category']][] = $row;
    }
    return $urls;
}

// Fetch current click counts for all live URLs, keyed by id. Used by the
// bubble diagram to poll for size changes without a full page reload.
function getUrlClickCounts($conn) {
    $stmt = $conn->query("SELECT id, click_count FROM urls");
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['id']] = (int) $row['click_count'];
    }
    return $counts;
}

// Function to handle URL click
function handleUrlClick($conn, $url_id) {
    $stmt = $conn->prepare("UPDATE urls SET click_count = click_count + 1 WHERE id = :id");
    $stmt->bindParam(':id', $url_id);
    $stmt->execute();

    $stmt = $conn->prepare("SELECT url FROM urls WHERE id = :id");
    $stmt->bindParam(':id', $url_id);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC); }

// Function to check whether a submitted URL is safe/well-formed enough to store
function isValidSubmissionUrl($url) {
    if (strlen($url) === 0 || strlen($url) > 255) {
        return false;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

// Check whether a URL already exists among live or pending submissions
function urlExists($conn, $url) {
    $stmt = $conn->prepare("SELECT 1 FROM urls WHERE url = :url UNION ALL SELECT 1 FROM submitted_urls WHERE url = :url2 LIMIT 1");
    $stmt->bindParam(':url', $url);
    $stmt->bindParam(':url2', $url);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

// Function to handle URL submission
function submitUrl($conn, $url, $description, $category_id) {
    $stmt = $conn->prepare("INSERT INTO submitted_urls (url, description, category_id) VALUES (:url, :description, :category_id)");
    $stmt->bindParam(':url', $url);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':category_id', $category_id);
    return $stmt->execute();
}

// Function to handle user registration
function registerUser($conn, $username, $password) {
    $password_hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':password', $password_hashed);
    return $stmt->execute();
}

// Function to handle user login
function loginUser($conn, $username, $password) {
    $stmt = $conn->prepare("SELECT id, password, is_admin, is_ne, role FROM users WHERE username = :username");
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['is_ne'] = $user['is_ne'];
        // Admin accounts created before roles existed have no role assigned;
        // treat them as Full Admin so existing logins keep working.
        $role = $user['role'];
        if (empty($role) && $user['is_admin']) {
            $role = 'full_admin';
        }
        $_SESSION['role'] = $role;
        return true;
    }
    return false;
}

// Valid admin roles, keyed by stored value, with a human-readable label.
function getAdminRoles() {
    return [
        'read_only' => 'Admin Read Only',
        'approver' => 'URL Submission Approver',
        'full_admin' => 'Full Admin',
    ];
}

function isValidAdminRole($role) {
    return array_key_exists($role, getAdminRoles());
}

// Fetch all admin accounts
function getAdminUsers($conn) {
    $stmt = $conn->query("SELECT id, username, role FROM users WHERE is_admin = 1 ORDER BY username");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Count how many Full Admin accounts exist (used to prevent locking everyone out)
function countFullAdmins($conn) {
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND role = 'full_admin'");
    return (int) $stmt->fetchColumn();
}

// Create a new admin account with the given role
function addAdminUser($conn, $username, $password, $role) {
    $password_hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin, is_ne, role) VALUES (:username, :password, 1, 0, :role)");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':password', $password_hashed);
    $stmt->bindParam(':role', $role);
    return $stmt->execute();
}

// Update an existing admin account's role
function updateAdminRole($conn, $user_id, $role) {
    $stmt = $conn->prepare("UPDATE users SET role = :role WHERE id = :id AND is_admin = 1");
    $stmt->bindParam(':role', $role);
    $stmt->bindParam(':id', $user_id);
    return $stmt->execute();
}

// Reset an existing admin account's password
function updateAdminPassword($conn, $user_id, $password) {
    $password_hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id AND is_admin = 1");
    $stmt->bindParam(':password', $password_hashed);
    $stmt->bindParam(':id', $user_id);
    return $stmt->execute();
}

// Remove an admin account
function deleteAdminUser($conn, $user_id) {
    $stmt = $conn->prepare("DELETE FROM users WHERE id = :id AND is_admin = 1");
    $stmt->bindParam(':id', $user_id);
    return $stmt->execute();
}

// Look up a user's current admin status/role directly from the database, so
// a role change or account removal takes effect immediately rather than
// waiting for the affected user's next login.
function getUserAdminStatus($conn, $user_id) {
    $stmt = $conn->prepare("SELECT is_admin, role FROM users WHERE id = :id");
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Read-only check of whether the given username is flagged "is_ne" in the
// DB. Used purely to decide whether to show an admin-panel shortcut link;
// it must never grant session/auth state on its own (that only happens via
// the password-checked loginUser()).
function check_is_ne($conn, $username) {
    $stmt = $conn->prepare("SELECT is_ne FROM users WHERE username = :username");
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return (bool) ($user && $user['is_ne']);
}

// Function to handle URL approval and rejection
function approveUrl($conn, $url_id, $url, $description, $category_id) {
    $stmt = $conn->prepare("INSERT INTO urls (url, description, category_id, click_count) VALUES (:url, :description, :category_id, 0)");
    $stmt->bindParam(':url', $url);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':category_id', $category_id);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM submitted_urls WHERE id = :id");
    $stmt->bindParam(':id', $url_id);
    return $stmt->execute();
}

function rejectUrl($conn, $url_id) {
    $stmt = $conn->prepare("DELETE FROM submitted_urls WHERE id = :id");
    $stmt->bindParam(':id', $url_id);
    return $stmt->execute();
}

function addUrl($conn, $url, $description, $category_id) {
    $stmt = $conn->prepare("INSERT INTO urls (url, description, category_id) VALUES (:url, :description, :category_id)");
    $stmt->bindParam(':url', $url);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':category_id', $category_id);
    return $stmt->execute();
}

function updateUrl($conn, $url_id, $description, $category_id) {
    $stmt = $conn->prepare("UPDATE urls SET description = :description, category_id = :category_id WHERE id = :id");
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':category_id', $category_id);
    $stmt->bindParam(':id', $url_id);
    return $stmt->execute();
}

function deleteUrl($conn, $url_id) {
    $stmt = $conn->prepare("DELETE FROM urls WHERE id = :id");
    $stmt->bindParam(':id', $url_id);
    return $stmt->execute();
}

// Fetch URLs awaiting approval
function getSubmittedUrls($conn) {
    $stmt = $conn->query("SELECT submitted_urls.id, submitted_urls.url, submitted_urls.description, categories.name as category, submitted_urls.category_id FROM submitted_urls JOIN categories ON submitted_urls.category_id = categories.id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to add a new category. New categories are appended to the end of
// the manual sort order.
function addCategory($conn, $name) {
    $next_order = $conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM categories")->fetchColumn();

    $stmt = $conn->prepare("INSERT INTO categories (name, sort_order) VALUES (:name, :sort_order)");
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':sort_order', $next_order, PDO::PARAM_INT);
    return $stmt->execute();
}

// Move a category up or down one position in the manual sort order. The
// "General" category is always forced first (see getCategories()) so it's
// excluded here; reordering only applies to the rest.
function moveCategory($conn, $category_id, $direction) {
    $stmt = $conn->query("SELECT id, sort_order FROM categories WHERE name != 'General' ORDER BY sort_order ASC, name ASC");
    $ordered = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $index = null;
    foreach ($ordered as $i => $cat) {
        if ($cat['id'] == $category_id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return false;
    }

    $swap_index = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swap_index < 0 || $swap_index >= count($ordered)) {
        return false;
    }

    $current = $ordered[$index];
    $swap_with = $ordered[$swap_index];

    $stmt = $conn->prepare("UPDATE categories SET sort_order = :sort_order WHERE id = :id");
    $stmt->execute([':sort_order' => $swap_with['sort_order'], ':id' => $current['id']]);
    $stmt->execute([':sort_order' => $current['sort_order'], ':id' => $swap_with['id']]);
    return true;
}

// Function to delete a category. Returns false if the category is still in use.
function deleteCategory($conn, $category_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM urls WHERE category_id = :id");
    $stmt->bindParam(':id', $category_id);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM submitted_urls WHERE category_id = :id");
    $stmt->bindParam(':id', $category_id);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM categories WHERE id = :id");
    $stmt->bindParam(':id', $category_id);
    return $stmt->execute();
}

// Function to log the current user out
function logoutUser() {
    $_SESSION = [];
    session_destroy();
}
?>
