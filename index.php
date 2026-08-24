<?php
// index.php
include 'global.php';
startSecureSession();

if (isset($_GET['click'])) {
    $url_id = intval($_GET['click']);
    $result = handleUrlClick($conn, $url_id);
    if ($result) {
        header("Location: " . $result['url']);
        exit;
    }
}

$urls = getUrls($conn);
// Grab remote user

$remote_user = '';
$remote_user = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : 'Normal';
$is_ne = '';
$is_ne = check_is_ne($conn, $remote_user);

$self = htmlspecialchars($_SERVER['PHP_SELF']);
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];

$firstCategorySlug = null;
foreach ($urls as $category => $urls_in_category) {
    $firstCategorySlug = 'cat-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($category)), '-');
    break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NE Url List - <?php echo htmlspecialchars($remote_user); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header class="main-header">
        <div class="header-container">
            <h1>Tools</h1>
            <p class="subtitle">Links</p>
        </div>
    </header>

        <div class="search-container" style="max-width: 1400px; margin: 0 auto 20px auto; padding: 0 20px;">
                <input type="text" id="portalSearch" placeholder="Search tools, descriptions, or URLs..." onkeyup="filterTools()" style="width: 100%; padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.05); outline: none; transition: border 0.2s;">
        </div>

    <?php if ($is_ne): ?>
    <div class="dashboard-container" style="margin-bottom: 24px;">
        <div class="card">
            <a href="admin.php" class="admin-btn">Admin Panel</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="dashboard-container" style="margin-bottom: 24px;">
        <div class="card admin-section">
            <a href="submit.php" class="btn btn-primary">Submit a URL</a>
        </div>
    </div>

    <nav class="tabs-nav">
        <?php $first = true; foreach ($urls as $category => $urls_in_category): ?>
            <?php $slug = 'cat-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($category)), '-'); ?>
            <button class="tab-btn<?php echo $first ? ' active' : ''; ?>" onclick="switchTab(event, '<?php echo $slug; ?>')"><?php echo htmlspecialchars($category); ?></button>
            <?php $first = false; ?>
        <?php endforeach; ?>
    </nav>

    <main class="dashboard-container">

        <?php $first = true; foreach ($urls as $category => $urls_in_category): ?>
            <?php $slug = 'cat-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($category)), '-'); ?>
            <section id="<?php echo $slug; ?>" class="card tab-content<?php echo $first ? ' active' : ''; ?>">
                <h2><?php echo htmlspecialchars($category); ?></h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-clicks">Clicks</th>
                                <th class="col-desc">Description</th>
                                <th class="col-url">URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($urls_in_category as $url_data): ?>
                            <tr>
                                <td class="clicks-badge"><?php echo $url_data['click_count']; ?></td>
                                <td class="desc-text"><?php echo htmlspecialchars($url_data['description']); ?></td>
                                <td><a href="<?php echo $self; ?>?click=<?php echo $url_data['id']; ?>" target="_blank"><?php echo htmlspecialchars($url_data['url']); ?></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php $first = false; ?>
        <?php endforeach; ?>

    </main>

    <footer class="main-footer">
        <?php if ($is_admin): ?>
            <a href="admin.php" class="admin-btn">Admin Panel</a>
        <?php endif; ?>
    </footer>

        <script>
                // Global variable to keep track of the current active tab
                let currentTabId = <?php echo json_encode($firstCategorySlug); ?>;

                function switchTab(event, tabId) {
                        currentTabId = tabId;

                        // Clear search box when explicitly switching tabs
                        document.getElementById('portalSearch').value = '';

                        const contents = document.querySelectorAll('.tab-content');
                        contents.forEach(content => content.classList.remove('active'));

                        const buttons = document.querySelectorAll('.tab-btn');
                        buttons.forEach(btn => btn.classList.remove('active'));

                        document.getElementById(tabId).classList.add('active');
                        if(event) event.currentTarget.classList.add('active');
                }

                function filterTools() {
                        const query = document.getElementById('portalSearch').value.toLowerCase();
                        const tabButtonsNav = document.querySelector('.tabs-nav');
                        const sections = document.querySelectorAll('.tab-content');

                        // If search is empty, restore normal tab behavior
                        if (query === '') {
                                tabButtonsNav.style.display = 'flex'; // Show tab buttons again
                                sections.forEach(section => {
                                        section.classList.remove('active');
                                        // Unhide all rows within sections
                                        section.querySelectorAll('tbody tr').forEach(row => row.style.display = '');
                                });
                                // Reactivate the last viewed tab
                                if (currentTabId) {
                                        document.getElementById(currentTabId).classList.add('active');
                                }
                                return;
                        }

                        // If user is searching, hide tab buttons to avoid UI confusion
                        tabButtonsNav.style.display = 'none';

                        sections.forEach(section => {
                                let sectionHasMatches = false;
                                const rows = section.querySelectorAll('tbody tr');

                                rows.forEach(row => {
                                        // Grab the text from the Description cell and URL cell
                                        const description = row.querySelector('.desc-text').textContent.toLowerCase();
                                        const url = row.querySelector('td a').textContent.toLowerCase();

                                        // Check if search query matches description OR url
                                        if (description.includes(query) || url.includes(query)) {
                                                row.style.display = ''; // Show row
                                                sectionHasMatches = true;
                                        } else {
                                                row.style.display = 'none'; // Hide row
                                        }
                                });

                                // If this specific category section has at least one match, show the card
                                if (sectionHasMatches) {
                                        section.classList.add('active');
                                } else {
                                        section.classList.remove('active');
                                }
                        });
                }
        </script>
</body>
</html>
