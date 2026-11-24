<?php
session_start();
require_once "config/database.php";
require_once "config/settings_helper.php";

// Protect the dashboard
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$settings = getSystemSettings($pdo);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "acknowledge_alert"
) {
    if (
        isset($_POST["csrf_token"]) &&
        hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"]) &&
        isset($_POST["notification_id"]) &&
        ctype_digit((string)$_POST["notification_id"])
    ) {
        $ackStmt = $pdo->prepare(
            "UPDATE low_stock_notifications
             SET status = 'acknowledged',
                 acknowledged_at = CURRENT_TIMESTAMP
             WHERE notification_id = ?
               AND status = 'active'"
        );
        $ackStmt->execute([(int)$_POST["notification_id"]]);
    }
    header("Location: dashboard.php");
    exit();
}

// Retrieve dashboard statistics
$totalIngredients = $pdo->query(
    "SELECT COUNT(*) FROM ingredients WHERE is_active = 1"
)->fetchColumn();

$totalRecipes = $pdo->query(
    "SELECT COUNT(*) FROM recipes WHERE is_active = 1"
)->fetchColumn();

$totalSuppliers = $pdo->query(
    "SELECT COUNT(*) FROM suppliers WHERE is_active = 1"
)->fetchColumn();

$lowStock = $pdo->query(
    "SELECT COUNT(*) FROM ingredients
     WHERE is_active = 1
     AND current_stock <= minimum_stock"
)->fetchColumn();

$activeAlertCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM low_stock_notifications lsn
     INNER JOIN ingredients i ON lsn.ingredient_id = i.ingredient_id
     WHERE lsn.status = 'active'
       AND i.is_active = 1
       AND i.current_stock <= i.minimum_stock"
)->fetchColumn();

// Retrieve low-stock ingredients & notification status for the right-hand alert panel
$lowStockStmt = $pdo->query(
    "SELECT
        i.ingredient_name,
        i.current_stock,
        i.minimum_stock,
        lsn.notification_id,
        lsn.status AS alert_status
     FROM ingredients i
     LEFT JOIN low_stock_notifications lsn
       ON lsn.ingredient_id = i.ingredient_id
      AND lsn.status IN ('active', 'acknowledged')
     WHERE i.is_active = 1
       AND i.current_stock <= i.minimum_stock
     ORDER BY (lsn.status = 'active') DESC, i.current_stock ASC
     LIMIT 5"
);
$lowStockItems = $lowStockStmt->fetchAll();

// Retrieve recent inventory activities
$sql = "SELECT
            inventory_transactions.*,
            ingredients.ingredient_name
        FROM inventory_transactions
        INNER JOIN ingredients
        ON inventory_transactions.ingredient_id = ingredients.ingredient_id
        ORDER BY transaction_date DESC, transaction_id DESC
        LIMIT 6";

$stmt = $pdo->query($sql);
$recentTransactions = $stmt->fetchAll();

// Chart 1 data: Purchase spending by day (anchored to latest purchase date or today)
$latestPurchaseDate = $pdo->query(
    "SELECT DATE(MAX(purchase_date)) FROM purchases"
)->fetchColumn();
$anchorDate = $latestPurchaseDate ?: date("Y-m-d");

$purchaseRowsStmt = $pdo->prepare(
    "SELECT DATE(purchase_date) AS p_day, SUM(total_amount) AS p_total
     FROM purchases
     WHERE DATE(purchase_date) BETWEEN DATE_SUB(:anchor_date, INTERVAL 6 DAY) AND :anchor_date
     GROUP BY DATE(purchase_date)"
);
$purchaseRowsStmt->execute(["anchor_date" => $anchorDate]);
$purchaseRows = $purchaseRowsStmt->fetchAll();

$purchaseMap = [];
foreach ($purchaseRows as $row) {
    $purchaseMap[$row["p_day"]] = (float) $row["p_total"];
}

$dayLabels = [];
$dailyPurchases = [];
for ($i = 6; $i >= 0; $i--) {
    $dateKey = date("Y-m-d", strtotime($anchorDate . " -{$i} days"));
    $dayLabels[] = date("D", strtotime($dateKey));
    $dailyPurchases[] = $purchaseMap[$dateKey] ?? 0.0;
}
$totalPurchaseSpend = array_sum($dailyPurchases);

// Chart 2 data: Active recipes breakdown by latest total_production_cost (fallback to batch_yield)
$recipeBreakdownRows = $pdo->query(
    "SELECT
        r.recipe_name,
        COALESCE(
            (SELECT rch.total_production_cost
             FROM recipe_cost_history rch
             WHERE rch.recipe_id = r.recipe_id
             ORDER BY rch.calculated_at DESC, rch.cost_history_id DESC
             LIMIT 1),
            r.batch_yield
        ) AS metric_value
     FROM recipes r
     WHERE r.is_active = 1
     ORDER BY metric_value DESC, r.recipe_name ASC"
)->fetchAll();

$doughnutLabels = [];
$doughnutValues = [];
$doughnutTotal = 0.0;

foreach ($recipeBreakdownRows as $item) {
    $val = max(0.0, (float) $item["metric_value"]);
    $doughnutLabels[] = $item["recipe_name"];
    $doughnutValues[] = $val;
    $doughnutTotal += $val;
}

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo renderThemeOverrides($settings); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dark-dashboard-body">

<div class="dashboard-container dark-dashboard">

    <!-- SIDEBAR -->
    <aside class="sidebar dark-sidebar">
        <div>
            <div class="logo">
                <div class="brand-mark">GH</div>
                <div>
                    <h2>Golden Hour</h2>
                    <p>Bakehouse Management</p>
                </div>
            </div>

            <nav aria-label="Main Navigation">
                <ul>
                    <li><a href="dashboard.php" class="active">Dashboard</a></li>
                    <li><a href="pages/ingredients.php">Ingredients</a></li>
                    <li><a href="pages/suppliers.php">Suppliers</a></li>
                    <li><a href="pages/purchases.php">Purchases</a></li>
                    <li><a href="pages/inventory.php">Inventory</a></li>
                    <li><a href="pages/recipes.php">Recipes</a></li>
                    <li><a href="pages/recipe_costing.php">Recipe Costing</a></li>
                    <li><a href="pages/reports.php">Reports</a></li>
                    <li><a href="pages/settings.php">Settings</a></li>
                </ul>
            </nav>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content dark-main">

        <!-- TOPBAR -->
        <header class="topbar dark-topbar">
            <div>
                <h1>Bakery Dashboard</h1>
                <p class="topbar-subtitle">Welcome back, <?php echo $adminName; ?></p>
            </div>

            <div class="topbar-controls">
                <span class="admin-badge">Admin: <?php echo $adminName; ?></span>
            </div>
        </header>

        <!-- TOP CHARTS ROW -->
        <section class="charts-grid" aria-label="Dashboard Charts">
            <div class="chart-card dark-panel">
                <div class="chart-card-top">
                    <div>
                        <h2>Purchase Overview (7-Day Window)</h2>
                        <p class="chart-stat"><?php echo number_format($totalPurchaseSpend, 2); ?></p>
                        <span class="chart-caption">Anchored to <?php echo htmlspecialchars($anchorDate); ?> • Updates with new purchases</span>
                    </div>
                </div>
                <div class="chart-canvas-wrap">
                    <canvas id="weeklyOverviewChart"></canvas>
                </div>
            </div>

            <div class="chart-card dark-panel">
                <div class="chart-card-top">
                    <div>
                        <h2>Recipe Cost Breakdown</h2>
                        <span class="chart-caption">Latest production cost across active recipes</span>
                    </div>
                </div>
                <div class="doughnut-layout">
                    <div class="doughnut-wrap">
                        <canvas id="topBreakdownChart"></canvas>
                        <div class="doughnut-center">
                            <strong><?php echo number_format($doughnutTotal, 0); ?></strong>
                            <span>Total</span>
                        </div>
                    </div>
                    <ul class="chart-legend-list" id="breakdownLegend"></ul>
                </div>
            </div>
        </section>

        <!-- SUMMARY CARDS ROW -->
        <section class="summary-cards dark-summary-cards" aria-label="Key Metrics">
            <div class="card dark-card">
                <div class="card-header-row">
                    <h3>Total Ingredients</h3>
                    <span class="card-tag tag-gold">Active</span>
                </div>
                <p class="metric-value"><?php echo (int) $totalIngredients; ?></p>
                <span class="card-subtext">Cataloged stock items</span>
            </div>

            <div class="card dark-card">
                <div class="card-header-row">
                    <h3>Total Recipes</h3>
                    <span class="card-tag tag-amber">Menu</span>
                </div>
                <p class="metric-value"><?php echo (int) $totalRecipes; ?></p>
                <span class="card-subtext">Active bakehouse formulas</span>
            </div>

            <div class="card dark-card">
                <div class="card-header-row">
                    <h3>Total Suppliers</h3>
                    <span class="card-tag tag-neutral">Vendors</span>
                </div>
                <p class="metric-value"><?php echo (int) $totalSuppliers; ?></p>
                <span class="card-subtext">Registered ingredient sources</span>
            </div>

            <div class="card dark-card">
                <div class="card-header-row">
                    <h3>Low Stock Alerts</h3>
                    <span class="card-tag <?php echo ((int) $lowStock > 0) ? 'tag-alert' : 'tag-ok'; ?>">
                        <?php echo ((int) $lowStock > 0) ? ($activeAlertCount > 0 ? $activeAlertCount . ' unread' : 'Action required') : 'Healthy'; ?>
                    </span>
                </div>
                <p class="metric-value <?php echo ((int) $lowStock > 0) ? 'metric-alert' : ''; ?>">
                    <?php echo (int) $lowStock; ?>
                </p>
                <span class="card-subtext">At or below minimum threshold</span>
            </div>
        </section>

        <!-- LOWER SPLIT GRID: RECENT ACTIVITIES & LOW STOCK / QUICK ACTIONS -->
        <section class="dashboard-bottom-grid">

            <!-- RECENT INVENTORY ACTIVITIES -->
            <div class="content-section dark-panel">
                <div class="panel-header">
                    <h2>Recent Inventory Activities</h2>
                    <a href="pages/inventory.php" class="panel-link">View All</a>
                </div>

                <div class="table-container">
                    <table class="dark-table" id="recentActivitiesTable">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Activity</th>
                                <th>Change</th>
                                <th>Remaining</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentTransactions) > 0): ?>
                                <?php foreach ($recentTransactions as $transaction): ?>
                                    <?php
                                        $typeRaw = strtolower((string) $transaction["transaction_type"]);
                                        $badgeClass = "badge-neutral";
                                        if (str_contains($typeRaw, "purchase") || str_contains($typeRaw, "add")) {
                                            $badgeClass = "badge-success";
                                        } elseif (str_contains($typeRaw, "wastage") || str_contains($typeRaw, "loss") || str_contains($typeRaw, "deduct")) {
                                            $badgeClass = "badge-danger";
                                        } elseif (str_contains($typeRaw, "adjust")) {
                                            $badgeClass = "badge-warning";
                                        }
                                    ?>
                                    <tr>
                                        <td class="cell-strong">
                                            <?php echo htmlspecialchars($transaction["ingredient_name"]); ?>
                                        </td>
                                        <td>
                                            <span class="status-pill <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($transaction["transaction_type"]); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($transaction["quantity_change"]); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($transaction["balance_after"]); ?>
                                        </td>
                                        <td class="cell-muted">
                                            <?php echo htmlspecialchars($transaction["transaction_date"]); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-row">
                                        No inventory activities recorded yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RIGHT COLUMN: LOW STOCK ALERTS & QUICK ACTIONS -->
            <div class="side-stack">
                <div class="content-section dark-panel">
                    <div class="panel-header">
                        <h2>Low Inventory Alerts</h2>
                        <a href="pages/purchases.php" class="panel-link">Restock</a>
                    </div>

                    <?php if (count($lowStockItems) > 0): ?>
                        <ul class="stock-alert-list">
                            <?php foreach ($lowStockItems as $item): ?>
                                <?php
                                    $current = (float) $item["current_stock"];
                                    $minimum = max((float) $item["minimum_stock"], 0.001);
                                    $pct = (int) max(6, min(100, round(($current / $minimum) * 100)));
                                ?>
                                <li class="stock-alert-item">
                                    <div class="stock-item-meta">
                                        <span class="stock-item-name">
                                            <?php echo htmlspecialchars($item["ingredient_name"]); ?>
                                        </span>
                                        <span class="stock-item-qty">
                                            <?php echo htmlspecialchars($item["current_stock"]); ?> /
                                            <?php echo htmlspecialchars($item["minimum_stock"]); ?>
                                            <?php if (!empty($item["notification_id"]) && ($item["alert_status"] ?? "") === "active"): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                    <input type="hidden" name="action" value="acknowledge_alert">
                                                    <input type="hidden" name="notification_id" value="<?php echo (int) $item["notification_id"]; ?>">
                                                    <button type="submit" class="text-button dark-text-action">Ack</button>
                                                </form>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="stock-bar-track">
                                        <div class="stock-bar-fill" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="empty-note">All active ingredients are above minimum stock levels.</p>
                    <?php endif; ?>
                </div>

                <div class="content-section dark-panel">
                    <div class="panel-header">
                        <h2>Quick Actions</h2>
                    </div>

                    <div class="quick-actions dark-quick-actions">
                        <a href="pages/ingredients.php">Ingredients</a>
                        <a href="pages/suppliers.php">Suppliers</a>
                        <a href="pages/purchases.php">Record Purchase</a>
                        <a href="pages/recipes.php">Recipes</a>
                    </div>
                </div>
            </div>

        </section>

    </main>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("activitySearch");
    const tableBody = document.querySelector("#recentActivitiesTable tbody");
    if (searchInput && tableBody) {
        searchInput.addEventListener("input", function () {
            const term = this.value.trim().toLowerCase();
            const rows = tableBody.querySelectorAll("tr");

            rows.forEach(function (row) {
                if (row.querySelector(".empty-row")) return;
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? "" : "none";
            });
        });
    }

    if (typeof Chart === "undefined") return;

    const barCtx = document.getElementById("weeklyOverviewChart");
    if (barCtx) {
        const dayLabels = <?php echo json_encode($dayLabels); ?>;
        const dailyValues = <?php echo json_encode($dailyPurchases); ?>;
        const palette = ["#E5A93C", "#F5C842", "#D99B26", "#F7D36A", "#E5A93C", "#F5C842", "#D99B26"];

        new Chart(barCtx, {
            type: "bar",
            data: {
                labels: dayLabels,
                datasets: [{
                    label: "Purchase Amount",
                    data: dailyValues,
                    backgroundColor: palette,
                    borderRadius: 8,
                    borderSkipped: false,
                    maxBarThickness: 36
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "#252730",
                        titleColor: "#FFFFFF",
                        bodyColor: "#E5E7EB",
                        borderColor: "#374151",
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: "#9CA3AF", font: { size: 12 } },
                        border: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: "rgba(255, 255, 255, 0.05)" },
                        ticks: { color: "#9CA3AF", font: { size: 11 } },
                        border: { display: false }
                    }
                }
            }
        });
    }

    const doughnutCtx = document.getElementById("topBreakdownChart");
    const legendList = document.getElementById("breakdownLegend");
    if (doughnutCtx) {
        let labels = <?php echo json_encode($doughnutLabels); ?>;
        let values = <?php echo json_encode($doughnutValues); ?>;
        const colors = ["#E5A93C", "#F5C842", "#F7D36A", "#D99B26"];

        const sum = values.reduce((acc, v) => acc + Number(v || 0), 0);
        if (sum <= 0 || labels.length === 0) {
            labels = ["No Data Yet"];
            values = [1];
        }

        new Chart(doughnutCtx, {
            type: "doughnut",
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "72%",
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "#252730",
                        titleColor: "#FFFFFF",
                        bodyColor: "#E5E7EB",
                        borderColor: "#374151",
                        borderWidth: 1,
                        padding: 10,
                        boxPadding: 6,
                        titleMarginBottom: 6,
                        caretPadding: 10,
                        callbacks: {
                            label: function (context) {
                                const num = Number(context.parsed || 0);
                                return " TZS " + num.toLocaleString(undefined, {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                }
            }
        });

        if (legendList) {
            legendList.innerHTML = "";
            if (sum <= 0) {
                legendList.innerHTML = '<li class="legend-empty">Add recipes or costing records to populate shares.</li>';
            } else {
                labels.forEach(function (label, idx) {
                    const val = Number(values[idx] || 0);
                    const pct = ((val / sum) * 100).toFixed(0);
                    const li = document.createElement("li");
                    li.className = "legend-item";
                    li.innerHTML =
                        '<span class="legend-left">' +
                            '<span class="legend-dot" style="background:' + colors[idx % colors.length] + '"></span>' +
                            '<span class="legend-label">' + label + '</span>' +
                        '</span>' +
                        '<span class="legend-pct">' + pct + '%</span>';
                    legendList.appendChild(li);
                });
            }
        }
    }
});
</script>

</body>
</html>