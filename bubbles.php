<?php
// bubbles.php
include 'global.php';
startSecureSession();

if (isset($_GET['data'])) {
    header('Content-Type: application/json');
    echo json_encode(getUrlClickCounts($conn));
    exit;
}

if (isset($_GET['click'])) {
    $url_id = intval($_GET['click']);
    $result = handleUrlClick($conn, $url_id);
    if ($result) {
        header("Location: " . $result['url']);
        exit;
    }
}

$urls = getUrls($conn);

$remote_user = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : 'Normal';
$is_ne = check_is_ne($conn, $remote_user);
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
$self = htmlspecialchars($_SERVER['PHP_SELF']);

// Flatten into a single list, preserving category for coloring/filtering.
$bubbles = [];
$max_clicks = 0;
foreach ($urls as $category => $urls_in_category) {
    foreach ($urls_in_category as $url_data) {
        $max_clicks = max($max_clicks, (int) $url_data['click_count']);
        $bubbles[] = [
            'id' => (int) $url_data['id'],
            'url' => $url_data['url'],
            'description' => $url_data['description'],
            'clicks' => (int) $url_data['click_count'],
            'category' => $category,
        ];
    }
}

$category_names = array_keys($urls);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bubble Diagram - <?php echo htmlspecialchars($remote_user); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bubbles-body">

    <header class="main-header">
        <div class="header-container">
            <h1>Tools</h1>
            <p class="subtitle">Bubble Diagram</p>
        </div>
    </header>

    <div class="search-container" style="max-width: 1400px; margin: 0 auto 20px auto; padding: 0 20px;">
        <input type="text" id="bubbleSearch" placeholder="Search tools, descriptions, or URLs..." style="width: 100%; padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.05); outline: none; transition: border 0.2s;">
    </div>

    <div class="dashboard-container bubble-toolbar">
        <a href="index.php" class="btn btn-secondary">Table View</a>
        <a href="submit.php" class="btn btn-primary">Submit a URL</a>
        <?php if ($is_ne || $is_admin): ?>
            <a href="admin.php" class="admin-btn">Admin Panel</a>
        <?php endif; ?>
    </div>

    <div class="dashboard-container">
        <div id="bubbleLegend" class="bubble-legend"></div>
    </div>

    <main class="dashboard-container">
        <div id="bubbleField" class="bubble-field card">
            <div id="bubbleEmpty" class="empty-state" style="display:none; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);">No matching URLs.</div>
        </div>
    </main>

    <div id="bubbleTooltip" class="bubble-tooltip" style="display:none;"></div>

    <footer class="main-footer">
        <?php if ($is_admin): ?>
            <a href="admin.php" class="admin-btn">Admin Panel</a>
        <?php endif; ?>
    </footer>

    <script>
        const BUBBLE_DATA = <?php echo json_encode($bubbles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const CATEGORY_NAMES = <?php echo json_encode($category_names, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let currentMaxClicks = <?php echo (int) max($max_clicks, 1); ?>;
        const CLICK_URL = <?php echo json_encode($self); ?>;
        const DATA_URL = <?php echo json_encode($self); ?> + '?data=1';
        const POLL_INTERVAL_MS = 3000;

        const PALETTE = [
            '#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed',
            '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5'
        ];

        const categoryColor = {};
        CATEGORY_NAMES.forEach((name, i) => { categoryColor[name] = PALETTE[i % PALETTE.length]; });

        const field = document.getElementById('bubbleField');
        const emptyState = document.getElementById('bubbleEmpty');
        const tooltip = document.getElementById('bubbleTooltip');
        const legend = document.getElementById('bubbleLegend');
        const searchBox = document.getElementById('bubbleSearch');

        // Build legend / category filter toggles.
        const activeCategories = new Set(CATEGORY_NAMES);
        CATEGORY_NAMES.forEach(name => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'legend-chip active';
            chip.style.setProperty('--chip-color', categoryColor[name]);
            chip.textContent = name;
            chip.addEventListener('click', () => {
                if (activeCategories.has(name)) {
                    activeCategories.delete(name);
                    chip.classList.remove('active');
                } else {
                    activeCategories.add(name);
                    chip.classList.add('active');
                }
                applyFilters();
            });
            legend.appendChild(chip);
        });

        const MIN_R = 34;
        const MAX_R = 90;

        function radiusFor(clicks) {
            const ratio = Math.sqrt(clicks / currentMaxClicks);
            return MIN_R + (MAX_R - MIN_R) * ratio;
        }

        const bubbleEls = BUBBLE_DATA.map(data => {
            const r = radiusFor(data.clicks);
            const el = document.createElement('a');
            el.className = 'bubble';
            el.href = CLICK_URL + '?click=' + data.id;
            el.target = '_blank';
            el.rel = 'noopener';
            el.style.width = el.style.height = (r * 2) + 'px';
            el.style.background = categoryColor[data.category] || '#94a3b8';
            el.dataset.search = (data.description + ' ' + data.url).toLowerCase();

            const label = document.createElement('span');
            label.className = 'bubble-label';
            label.textContent = data.description;
            el.appendChild(label);

            const countBadge = document.createElement('span');
            countBadge.className = 'bubble-count';
            countBadge.textContent = data.clicks;
            el.appendChild(countBadge);

            el.addEventListener('mouseenter', () => {
                tooltip.innerHTML = '<strong>' + escapeHtml(data.description) + '</strong><br>' +
                    '<span class="bubble-tooltip-url">' + escapeHtml(data.url) + '</span><br>' +
                    escapeHtml(data.category) + ' &middot; ' + data.clicks + ' click' + (data.clicks === 1 ? '' : 's');
                tooltip.style.display = 'block';
            });
            el.addEventListener('mousemove', (e) => {
                tooltip.style.left = (e.clientX + 16) + 'px';
                tooltip.style.top = (e.clientY + 16) + 'px';
            });
            el.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });

            field.appendChild(el);

            return {
                el, r, data,
                targetR: r,
                countEl: countBadge,
                x: Math.random() * 800,
                y: Math.random() * 400,
                vx: 0,
                vy: 0,
                visible: true,
            };
        });

        // The most-clicked bubble acts as the gravity well everything else is drawn toward.
        let anchorIndex = 0;
        bubbleEls.forEach((b, i) => {
            if (b.data.clicks > bubbleEls[anchorIndex].data.clicks) anchorIndex = i;
        });

        // Optimistic bump: this tab's own click registers immediately (the size
        // grows right away) instead of waiting for the next poll to confirm it.
        bubbleEls.forEach(b => {
            b.el.addEventListener('click', () => {
                b.data.clicks += 1;
                b.countEl.textContent = b.data.clicks;
                if (b.data.clicks > currentMaxClicks) {
                    currentMaxClicks = b.data.clicks;
                }
                bubbleEls.forEach((other, i) => {
                    other.targetR = radiusFor(other.data.clicks);
                    if (other.data.clicks > bubbleEls[anchorIndex].data.clicks) anchorIndex = i;
                });
            });
        });

        // Poll the server for up-to-date click counts (covers clicks from other
        // tabs/users) and animate bubble sizes toward the new values in real time.
        function applyClickCounts(counts) {
            let maxClicks = 1;
            Object.keys(counts).forEach(id => { maxClicks = Math.max(maxClicks, counts[id]); });
            currentMaxClicks = maxClicks;

            let newAnchorIndex = anchorIndex;
            bubbleEls.forEach((b, i) => {
                const latest = counts[b.data.id];
                if (typeof latest === 'number' && latest !== b.data.clicks) {
                    b.data.clicks = latest;
                    b.countEl.textContent = latest;
                }
                b.targetR = radiusFor(b.data.clicks);
                if (b.data.clicks > bubbleEls[newAnchorIndex].data.clicks) newAnchorIndex = i;
            });
            anchorIndex = newAnchorIndex;
        }

        function pollClickCounts() {
            fetch(DATA_URL)
                .then(res => res.ok ? res.json() : null)
                .then(counts => { if (counts) applyClickCounts(counts); })
                .catch(() => { /* ignore transient network errors, retry next interval */ });
        }
        setInterval(pollClickCounts, POLL_INTERVAL_MS);

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function applyFilters() {
            const query = searchBox.value.trim().toLowerCase();
            let anyVisible = false;
            bubbleEls.forEach(b => {
                const matchesSearch = query === '' || b.el.dataset.search.includes(query);
                const matchesCategory = activeCategories.has(b.data.category);
                b.visible = matchesSearch && matchesCategory;
                b.el.classList.toggle('bubble-hidden', !b.visible);
                if (b.visible) anyVisible = true;
            });
            emptyState.style.display = anyVisible ? 'none' : 'block';
        }

        searchBox.addEventListener('input', applyFilters);

        const GRAVITY = 0.06;         // pull strength toward the center for regular bubbles
        const ANCHOR_GRAVITY = 0.02;  // weaker pull for the anchor so it stays put but can't drift off-center
        const DAMPING = 0.9;    // velocity decay so the cluster settles instead of oscillating forever
        const JITTER = 0.015;   // tiny random nudge each frame so it never goes fully still

        // Every bubble is drawn toward the fixed center of the display like gravity
        // (the anchor bubble gets a weaker pull so it settles there and everything
        // else clusters around it), then pushed apart by mass-weighted collisions
        // so heavier (bigger) bubbles barely move when smaller ones bump into them.
        // Pulling toward a fixed point (rather than toward the anchor's own,
        // movable position) keeps the whole cluster from drifting off-center.
        function step() {
            const w = field.clientWidth;
            const h = field.clientHeight;
            const centerX = w / 2;
            const centerY = h / 2;

            bubbleEls.forEach((b, i) => {
                if (!b.visible) return;

                // Smoothly grow/shrink toward the latest known click count instead
                // of snapping, so size changes read as an animation.
                const rDiff = b.targetR - b.r;
                if (Math.abs(rDiff) > 0.05) {
                    b.r += rDiff * 0.1;
                    b.el.style.width = b.el.style.height = (b.r * 2) + 'px';
                }

                const dx = centerX - b.x;
                const dy = centerY - b.y;
                const dist = Math.sqrt(dx * dx + dy * dy) || 0.01;
                const strength = i === anchorIndex ? ANCHOR_GRAVITY : GRAVITY;
                b.vx += (dx / dist) * strength;
                b.vy += (dy / dist) * strength;

                b.vx += (Math.random() - 0.5) * JITTER;
                b.vy += (Math.random() - 0.5) * JITTER;

                b.vx *= DAMPING;
                b.vy *= DAMPING;

                b.x += b.vx;
                b.y += b.vy;

                if (b.x - b.r < 0) { b.x = b.r; b.vx = Math.abs(b.vx) * 0.4; }
                if (b.x + b.r > w) { b.x = w - b.r; b.vx = -Math.abs(b.vx) * 0.4; }
                if (b.y - b.r < 0) { b.y = b.r; b.vy = Math.abs(b.vy) * 0.4; }
                if (b.y + b.r > h) { b.y = h - b.r; b.vy = -Math.abs(b.vy) * 0.4; }
            });

            // Mass-weighted separation (mass ~ area) so bubbles pack around the
            // anchor without overlapping, and the anchor itself barely budges.
            const visible = bubbleEls.filter(b => b.visible);
            for (let iter = 0; iter < 2; iter++) {
                for (let i = 0; i < visible.length; i++) {
                    for (let j = i + 1; j < visible.length; j++) {
                        const a = visible[i], c = visible[j];
                        const dx = c.x - a.x, dy = c.y - a.y;
                        const dist = Math.sqrt(dx * dx + dy * dy) || 0.01;
                        const minDist = a.r + c.r;
                        if (dist < minDist) {
                            const overlap = minDist - dist;
                            const nx = dx / dist, ny = dy / dist;
                            const massA = a.r * a.r, massC = c.r * c.r;
                            const totalMass = massA + massC;
                            const moveA = overlap * (massC / totalMass);
                            const moveC = overlap * (massA / totalMass);
                            a.x -= nx * moveA;
                            a.y -= ny * moveA;
                            c.x += nx * moveC;
                            c.y += ny * moveC;
                        }
                    }
                }
            }

            bubbleEls.forEach(b => {
                b.el.style.transform = 'translate(' + (b.x - b.r) + 'px, ' + (b.y - b.r) + 'px)';
            });

            requestAnimationFrame(step);
        }

        // Seed initial positions inside the field once it has real dimensions,
        // anchoring the largest bubble at the center as the gravity well.
        window.addEventListener('load', () => {
            const w = field.clientWidth || 800;
            const h = field.clientHeight || 500;
            bubbleEls.forEach(b => {
                b.x = b.r + Math.random() * Math.max(1, w - b.r * 2);
                b.y = b.r + Math.random() * Math.max(1, h - b.r * 2);
            });
            bubbleEls[anchorIndex].x = w / 2;
            bubbleEls[anchorIndex].y = h / 2;
            requestAnimationFrame(step);
        });
    </script>
</body>
</html>
