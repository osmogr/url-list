<?php
// admin.php
include 'global.php';
startSecureSession();

if (isset($_GET['logout'])) {
    logoutUser();
    header('Location: admin.php');
    exit;
}

$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Your session expired or the request could not be verified. Please go back and try again.');
}

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    if (loginUser($conn, $username, $password) && !empty($_SESSION['is_admin'])) {
        header('Location: admin.php');
        exit;
    } else {
        logoutUser();
        $login_error = 'Invalid username or password, or account is not an admin.';
    }
}

// Re-check admin status/role against the database on every request, rather than
// trusting the session values set at login. Otherwise a role change or account
// removal wouldn't take effect until the affected user logged in again.
if (!empty($_SESSION['user_id'])) {
    $current_status = getUserAdminStatus($conn, $_SESSION['user_id']);
    if ($current_status && $current_status['is_admin']) {
        $_SESSION['is_admin'] = $current_status['is_admin'];
        $_SESSION['role'] = $current_status['role'] ?: 'full_admin';
    } else {
        logoutUser();
    }
}

$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
$role = $_SESSION['role'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

// Permission model:
// - full_admin: everything, including managing admin accounts
// - approver: can only approve/reject pending URL submissions
// - read_only: can view the admin panel but cannot change anything
$can_manage_content = $is_admin && $role === 'full_admin';
$can_approve = $is_admin && in_array($role, ['full_admin', 'approver'], true);
$can_manage_admins = $is_admin && $role === 'full_admin';

$action_message = '';
$admin_roles = getAdminRoles();

if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $url = $_POST['url'];
            $description = $_POST['description'];
            $category_id = $_POST['category_id'];
            addUrl($conn, $url, $description, $category_id);
            $action_message = 'URL added.';
        }
    } elseif (isset($_POST['delete'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $url_id = $_POST['delete'];
            deleteUrl($conn, $url_id);
            $action_message = 'URL deleted.';
        }
    } elseif (isset($_POST['update_url'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $url_id = $_POST['update_url'];
            $description = $_POST['description'][$url_id] ?? '';
            $category_id = $_POST['category_id'][$url_id] ?? null;
            updateUrl($conn, $url_id, $description, $category_id);
            $action_message = 'URL updated.';
        }
    } elseif (isset($_POST['update_all'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $descriptions = $_POST['description'] ?? [];
            $category_ids = $_POST['category_id'] ?? [];
            $updated = 0;
            foreach ($descriptions as $url_id => $description) {
                $category_id = $category_ids[$url_id] ?? null;
                updateUrl($conn, $url_id, $description, $category_id);
                $updated++;
            }
            $action_message = $updated . ' URL' . ($updated === 1 ? '' : 's') . ' updated.';
        }
    } elseif (isset($_POST['approve'])) {
        if (!$can_approve) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $url_id = $_POST['url_id'];
            $url = $_POST['url'];
            $description = $_POST['description'];
            $category_id = $_POST['category_id'];
            approveUrl($conn, $url_id, $url, $description, $category_id);
            $action_message = 'Submission approved.';
        }
    } elseif (isset($_POST['reject'])) {
        if (!$can_approve) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $url_id = $_POST['url_id'];
            rejectUrl($conn, $url_id);
            $action_message = 'Submission rejected.';
        }
    } elseif (isset($_POST['add_category'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $category_name = trim($_POST['category_name']);
            if ($category_name === '') {
                $action_message = 'Category name cannot be empty.';
            } else {
                addCategory($conn, $category_name);
                $action_message = 'Category added.';
            }
        }
    } elseif (isset($_POST['delete_category'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $category_id = $_POST['category_id'];
            if (deleteCategory($conn, $category_id)) {
                $action_message = 'Category deleted.';
            } else {
                $action_message = 'Cannot delete category: it still has URLs or pending submissions assigned to it.';
            }
        }
    } elseif (isset($_POST['move_category_up'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            moveCategory($conn, $_POST['category_id'], 'up');
            $action_message = 'Category order updated.';
        }
    } elseif (isset($_POST['move_category_down'])) {
        if (!$can_manage_content) {
            $action_message = 'You do not have permission to do that.';
        } else {
            moveCategory($conn, $_POST['category_id'], 'down');
            $action_message = 'Category order updated.';
        }
    } elseif (isset($_POST['add_admin'])) {
        if (!$can_manage_admins) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $new_username = trim($_POST['username']);
            $new_password = $_POST['password'];
            $new_role = $_POST['role'];
            if ($new_username === '' || $new_password === '') {
                $action_message = 'Username and password are required.';
            } elseif (!isValidAdminRole($new_role)) {
                $action_message = 'Invalid role selected.';
            } else {
                try {
                    addAdminUser($conn, $new_username, $new_password, $new_role);
                    $action_message = 'Admin account added.';
                } catch (PDOException $e) {
                    $action_message = 'Could not add admin account: username already exists.';
                }
            }
        }
    } elseif (isset($_POST['update_admin_role'])) {
        if (!$can_manage_admins) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $admin_user_id = $_POST['user_id'];
            $new_role = $_POST['role'];
            $stmt = $conn->prepare("SELECT role FROM users WHERE id = :id AND is_admin = 1");
            $stmt->bindParam(':id', $admin_user_id);
            $stmt->execute();
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            $target_is_last_full_admin = $target && $target['role'] === 'full_admin' && countFullAdmins($conn) <= 1;

            if (!isValidAdminRole($new_role)) {
                $action_message = 'Invalid role selected.';
            } elseif ($new_role === 'full_admin') {
                updateAdminRole($conn, $admin_user_id, $new_role);
                $action_message = 'Admin role updated.';
            } elseif ($admin_user_id == $current_user_id) {
                $action_message = 'You cannot remove your own Full Admin role.';
            } elseif ($target_is_last_full_admin) {
                $action_message = 'Cannot change role: at least one Full Admin account must remain.';
            } else {
                updateAdminRole($conn, $admin_user_id, $new_role);
                $action_message = 'Admin role updated.';
            }
        }
    } elseif (isset($_POST['update_admin_password'])) {
        if (!$can_manage_admins) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $admin_user_id = $_POST['user_id'];
            $new_password = $_POST['password'];
            if ($new_password === '') {
                $action_message = 'Password cannot be empty.';
            } else {
                updateAdminPassword($conn, $admin_user_id, $new_password);
                $action_message = 'Admin password updated.';
            }
        }
    } elseif (isset($_POST['delete_admin'])) {
        if (!$can_manage_admins) {
            $action_message = 'You do not have permission to do that.';
        } else {
            $admin_user_id = $_POST['user_id'];
            if ($admin_user_id == $current_user_id) {
                $action_message = 'You cannot delete your own account.';
            } else {
                $stmt = $conn->prepare("SELECT role FROM users WHERE id = :id");
                $stmt->bindParam(':id', $admin_user_id);
                $stmt->execute();
                $target = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($target && $target['role'] === 'full_admin' && countFullAdmins($conn) <= 1) {
                    $action_message = 'Cannot delete: at least one Full Admin account must remain.';
                } else {
                    deleteAdminUser($conn, $admin_user_id);
                    $action_message = 'Admin account deleted.';
                }
            }
        }
    }
}

if ($is_admin) {
    $urls = getUrls($conn);
    $categories = getCategories($conn);
    $submitted_urls = getSubmittedUrls($conn);
    if ($can_manage_admins) {
        $admin_users = getAdminUsers($conn);
    }
}

$self = htmlspecialchars($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - NE Url List</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header class="main-header">
        <div class="header-container">
            <h1>Admin Panel</h1>
            <p class="subtitle">Url List</p>
        </div>
    </header>

    <main class="dashboard-container">

        <?php if (!$is_admin): ?>

            <div class="card" style="max-width: 420px; margin: 0 auto;">
                <h2>Login</h2>
                <?php if ($login_error): ?>
                    <p class="alert-inline"><?php echo htmlspecialchars($login_error); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo $self; ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary">Login</button>
                </form>
            </div>

        <?php else: ?>

            <div class="admin-toolbar">
                <a href="index.php">Back to URL List</a>
                <a href="?logout=1" class="btn btn-secondary">Logout</a>
            </div>

            <?php if ($action_message): ?>
                <div class="card admin-message"><?php echo htmlspecialchars($action_message); ?></div>
            <?php endif; ?>

            <div class="card admin-section">
                <h2>Pending Submissions<?php echo count($submitted_urls) ? ' <span class="badge-pending">' . count($submitted_urls) . '</span>' : ''; ?></h2>
                <?php if (empty($submitted_urls)): ?>
                    <p class="empty-state">No submissions awaiting review.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th class="col-url">URL</th>
                                    <th class="col-desc">Description</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submitted_urls as $submission): ?>
                                <tr>
                                    <td><a href="<?php echo htmlspecialchars($submission['url']); ?>" target="_blank"><?php echo htmlspecialchars($submission['url']); ?></a></td>
                                    <td>
                                        <?php if ($can_approve): ?>
                                        <form method="post" action="<?php echo $self; ?>" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="url_id" value="<?php echo $submission['id']; ?>">
                                            <input type="hidden" name="url" value="<?php echo htmlspecialchars($submission['url']); ?>">
                                            <input type="text" name="description" placeholder="Description" value="<?php echo htmlspecialchars($submission['description'] ?? ''); ?>" required>
                                            <select name="category_id" required>
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo $category['id']; ?>"<?php echo $category['id'] == $submission['category_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="approve" class="btn btn-approve">Approve</button>
                                        </form>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($submission['description'] ?? ''); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($submission['category']); ?></td>
                                    <td>
                                        <?php if ($can_approve): ?>
                                        <form method="post" action="<?php echo $self; ?>" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="url_id" value="<?php echo $submission['id']; ?>">
                                            <button type="submit" name="reject" class="btn btn-reject">Reject</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($can_manage_content): ?>
            <div class="card admin-section">
                <h2>Add URL</h2>
                <form method="post" action="<?php echo $self; ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label for="url">URL</label>
                        <input type="text" id="url" name="url" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" required>
                    </div>
                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="add" class="btn btn-primary">Add URL</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="card admin-section">
                <h2>Manage URLs</h2>
                <?php if (empty($urls)): ?>
                    <p class="empty-state">No URLs yet.</p>
                <?php else: ?>
                    <?php if ($can_manage_content): ?>
                    <form id="bulk-urls-form" method="post" action="<?php echo $self; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="bulk-actions">
                        <button type="submit" name="update_all" id="update-all-btn" class="btn btn-primary" style="display: none;">Update All (<span id="update-all-count">0</span>)</button>
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th class="col-clicks">Clicks</th>
                                    <th class="col-desc">Description</th>
                                    <th class="col-url">URL</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($urls as $category => $urls_in_category): ?>
                                    <?php foreach ($urls_in_category as $url_data): ?>
                                    <tr data-url-row="<?php echo $url_data['id']; ?>">
                                        <td class="clicks-badge"><span><?php echo $url_data['click_count']; ?></span></td>
                                        <td class="desc-text">
                                            <?php if ($can_manage_content): ?>
                                            <input type="text" name="description[<?php echo $url_data['id']; ?>]" value="<?php echo htmlspecialchars($url_data['description']); ?>" data-original="<?php echo htmlspecialchars($url_data['description'], ENT_QUOTES); ?>" data-url-id="<?php echo $url_data['id']; ?>" class="desc-edit-input dirty-tracked">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($url_data['description']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><a href="<?php echo htmlspecialchars($url_data['url']); ?>" target="_blank"><?php echo htmlspecialchars($url_data['url']); ?></a></td>
                                        <td>
                                            <?php if ($can_manage_content): ?>
                                            <select name="category_id[<?php echo $url_data['id']; ?>]" data-original="<?php echo $url_data['category_id']; ?>" data-url-id="<?php echo $url_data['id']; ?>" class="dirty-tracked">
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?php echo $cat['id']; ?>"<?php echo $cat['id'] == $url_data['category_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($category); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($can_manage_content): ?>
                                            <div class="inline-form">
                                                <button type="submit" name="update_url" value="<?php echo $url_data['id']; ?>" class="btn btn-secondary">Update</button>
                                                <button type="submit" name="delete" value="<?php echo $url_data['id']; ?>" class="btn btn-reject" onclick="return confirm('Delete this URL?');">Delete</button>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($can_manage_content): ?>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($can_manage_content): ?>
            <div class="card admin-section">
                <h2>Manage Categories</h2>
                <form method="post" action="<?php echo $self; ?>" class="inline-form" style="margin-bottom: 20px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="text" name="category_name" placeholder="New category name" required>
                    <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                </form>
                <?php if (empty($categories)): ?>
                    <p class="empty-state">No categories yet.</p>
                <?php else: ?>
                    <?php $reorderable_categories = array_values(array_filter($categories, function ($c) { return $c['name'] !== 'General'; })); ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Order</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['name']); ?></td>
                                    <td>
                                        <?php if ($category['name'] === 'General'): ?>
                                            <span class="empty-state">Always first</span>
                                        <?php else: ?>
                                            <?php $pos = array_search($category['id'], array_column($reorderable_categories, 'id')); ?>
                                            <form method="post" action="<?php echo $self; ?>" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                <button type="submit" name="move_category_up" class="btn btn-secondary"<?php echo $pos === 0 ? ' disabled' : ''; ?>>&uarr;</button>
                                                <button type="submit" name="move_category_down" class="btn btn-secondary"<?php echo $pos === count($reorderable_categories) - 1 ? ' disabled' : ''; ?>>&darr;</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo $self; ?>" class="inline-form" onsubmit="return confirm('Delete this category?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <button type="submit" name="delete_category" class="btn btn-reject">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can_manage_admins): ?>
            <div class="card admin-section">
                <h2>Manage Admin Accounts</h2>
                <form method="post" action="<?php echo $self; ?>" class="admin-form" style="margin-bottom: 24px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label for="admin-username">Username</label>
                        <input type="text" id="admin-username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="admin-password">Password</label>
                        <input type="password" id="admin-password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="admin-role">Role</label>
                        <select id="admin-role" name="role" required>
                            <?php foreach ($admin_roles as $role_value => $role_label): ?>
                                <option value="<?php echo htmlspecialchars($role_value); ?>"><?php echo htmlspecialchars($role_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="add_admin" class="btn btn-primary">Add Admin Account</button>
                </form>

                <?php if (empty($admin_users)): ?>
                    <p class="empty-state">No admin accounts yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Reset Password</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admin_users as $admin_user): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($admin_user['username']); ?>
                                        <?php if ($admin_user['id'] == $current_user_id): ?><span class="badge-pending">You</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo $self; ?>" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $admin_user['id']; ?>">
                                            <select name="role">
                                                <?php foreach ($admin_roles as $role_value => $role_label): ?>
                                                    <option value="<?php echo htmlspecialchars($role_value); ?>"<?php echo $role_value === $admin_user['role'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($role_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_admin_role" class="btn btn-secondary">Update</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo $self; ?>" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $admin_user['id']; ?>">
                                            <input type="password" name="password" placeholder="New password" required>
                                            <button type="submit" name="update_admin_password" class="btn btn-secondary">Reset</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($admin_user['id'] != $current_user_id): ?>
                                        <form method="post" action="<?php echo $self; ?>" class="inline-form" onsubmit="return confirm('Delete this admin account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $admin_user['id']; ?>">
                                            <button type="submit" name="delete_admin" class="btn btn-reject">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>

    </main>

    <script>
        (function () {
            var form = document.getElementById('bulk-urls-form');
            if (!form) return;

            var updateAllBtn = document.getElementById('update-all-btn');
            var countSpan = document.getElementById('update-all-count');
            var dirtyRows = new Set();

            function refreshUpdateAllVisibility() {
                if (dirtyRows.size >= 2) {
                    countSpan.textContent = dirtyRows.size;
                    updateAllBtn.style.display = '';
                } else {
                    updateAllBtn.style.display = 'none';
                }
            }

            form.querySelectorAll('.dirty-tracked').forEach(function (field) {
                var rowId = field.getAttribute('data-url-id');
                var original = field.getAttribute('data-original');

                field.addEventListener('input', function () {
                    checkField(field, rowId, original);
                });
                field.addEventListener('change', function () {
                    checkField(field, rowId, original);
                });
            });

            function checkField(field, rowId, original) {
                var row = form.querySelector('tr[data-url-row="' + rowId + '"]');
                var rowIsDirty = false;
                if (row) {
                    row.querySelectorAll('.dirty-tracked').forEach(function (f) {
                        if (f.value !== f.getAttribute('data-original')) {
                            rowIsDirty = true;
                        }
                    });
                }

                if (rowIsDirty) {
                    dirtyRows.add(rowId);
                } else {
                    dirtyRows.delete(rowId);
                }
                refreshUpdateAllVisibility();
            }
        })();
    </script>

</body>
</html>
