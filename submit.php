<?php
// submit.php
include 'global.php';
startSecureSession();

$submit_message = '';
$submit_error = '';
$url = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_url'])) {
    $url = trim($_POST['url']);
    $description = trim($_POST['description']);
    $category_id = $_POST['category_id'];

    if (!isValidSubmissionUrl($url)) {
        $submit_error = 'Please enter a valid http:// or https:// URL.';
    } else {
        submitUrl($conn, $url, $description, $category_id);
        $submit_message = 'Thanks! Your URL was submitted and is awaiting review.';
        $url = '';
        $description = '';
    }
}

$categories = getCategories($conn);

$remote_user = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : 'Normal';
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];

$self = htmlspecialchars($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit a URL - NE Url List</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header class="main-header">
        <div class="header-container">
            <h1>Tools</h1>
            <p class="subtitle">Links</p>
        </div>
    </header>

    <div class="dashboard-container" style="margin-bottom: 24px;">
        <div class="card admin-section">
            <h2>Submit a URL</h2>
            <?php if ($submit_message): ?>
                <div class="admin-message" style="margin-bottom: 14px;"><?php echo htmlspecialchars($submit_message); ?></div>
            <?php endif; ?>
            <?php if ($submit_error): ?>
                <p class="alert-inline" style="margin-bottom: 14px; display: block;"><?php echo htmlspecialchars($submit_error); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo $self; ?>" class="admin-form">
                <div class="form-group">
                    <label for="submit-url">URL</label>
                    <input type="text" id="submit-url" name="url" placeholder="https://example.com" required value="<?php echo htmlspecialchars($url); ?>">
                </div>
                <div class="form-group">
                    <label for="submit-description">Description</label>
                    <input type="text" id="submit-description" name="description" placeholder="What is this tool for?" value="<?php echo htmlspecialchars($description); ?>">
                </div>
                <div class="form-group">
                    <label for="submit-category">Category</label>
                    <select id="submit-category" name="category_id" required>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="submit_url" class="btn btn-primary">Submit for Review</button>
            </form>
        </div>
    </div>

    <footer class="main-footer">
        <a href="index.php" class="admin-btn">Back to Links</a>
        <?php if ($is_admin): ?>
            <a href="admin.php" class="admin-btn">Admin Panel</a>
        <?php endif; ?>
    </footer>

</body>
</html>
