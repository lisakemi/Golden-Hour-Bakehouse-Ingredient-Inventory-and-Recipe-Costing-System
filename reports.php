<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once "../config/database.php";
require_once "../config/settings_helper.php";
$settings = getSystemSettings($pdo);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Default reporting period: current month.
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

// Validate dates strictly.
$fromDate = DateTime::createFromFormat('!Y-m-d', $from);
$toDate = DateTime::createFromFormat('!Y-m-d', $to);

if (
    !$fromDate ||
    $fromDate->format('Y-m-d') !== $from ||
    !$toDate ||
    $toDate->format('Y-m-d') !== $to ||
    $from > $to
) {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

$startDateTime = $from . ' 00:00:00';
$endDateTime = $to . ' 23:59:59';

// LOW STOCK REPORT
$lowStock = $pdo->query(
    "SELECT
        ingredient_name,
        current_stock,
        minimum_stock,
        base_unit
     FROM ingredients
     WHERE is_active = 1
       AND current_stock <= minimum_stock
     ORDER BY ingredient_name"
)->fetchAll();

// INVENTORY MOVEMENT REPORT
$movementQuery = $pdo->prepare(
    "SELECT
        t.transaction_id,
        i.ingredient_name,
        i.base_unit,
        t.transaction_type,
        t.quantity_change,
        t.balance_after,
        t.unit_cost,
        t.waste_category,
        t.estimated_loss,
        t.notes,
        t.transaction_date
     FROM inventory_transactions t
     INNER JOIN ingredients i
        ON t.ingredient_id = i.ingredient_id
     WHERE t.transaction_date BETWEEN ? AND ?
     ORDER BY t.transaction_date DESC, t.transaction_id DESC"
);
$movementQuery->execute([$startDateTime, $endDateTime]);
$movements = $movementQuery->fetchAll();

// WASTE & SPOILAGE REPORT
$wastageQuery = $pdo->prepare(
    "SELECT
        t.transaction_id,
        i.ingredient_name,
        i.base_unit,
        ABS(t.quantity_change) AS wasted_quantity,
        t.waste_category,
        t.estimated_loss,
        t.notes,
        t.transaction_date
     FROM inventory_transactions t
     INNER JOIN ingredients i
        ON t.ingredient_id = i.ingredient_id
     WHERE t.transaction_type = 'wastage'
       AND t.transaction_date BETWEEN ? AND ?
     ORDER BY t.transaction_date DESC, t.transaction_id DESC"
);
$wastageQuery->execute([$startDateTime, $endDateTime]);
$wastageRecords = $wastageQuery->fetchAll();

// PURCHASE REPORT
$purchaseQuery = $pdo->prepare(
    "SELECT
        p.purchase_id,
        p.purchase_date,
        p.total_amount,
        s.supplier_name,
        COUNT(pi.purchase_item_id) AS item_count
     FROM purchases p
     LEFT JOIN suppliers s
        ON p.supplier_id = s.supplier_id
     LEFT JOIN purchase_items pi
        ON p.purchase_id = pi.purchase_id
     WHERE p.purchase_date BETWEEN ? AND ?
     GROUP BY
        p.purchase_id,
        p.purchase_date,
        p.total_amount,
        s.supplier_name
     ORDER BY p.purchase_date DESC"
);
$purchaseQuery->execute([$startDateTime, $endDateTime]);
$purchases = $purchaseQuery->fetchAll();

// RECIPE COSTING REPORT
$costingQuery = $pdo->prepare(
    "SELECT
        h.cost_history_id,
        r.recipe_name,
        h.batch_yield,
        h.yield_unit,
        h.ingredient_cost,
        h.other_cost,
        h.total_production_cost,
        h.cost_per_product,
        h.target_profit_margin,
        h.suggested_selling_price,
        h.calculated_at
     FROM recipe_cost_history h
     INNER JOIN recipes r
        ON h.recipe_id = r.recipe_id
     WHERE h.calculated_at BETWEEN ? AND ?
     ORDER BY h.calculated_at DESC"
);
$costingQuery->execute([$startDateTime, $endDateTime]);
$costings = $costingQuery->fetchAll();

// REPORT TOTALS
$totalPurchases = array_sum(
    array_column($purchases, 'total_amount')
);
$totalWastageLoss = array_sum(
    array_column($wastageRecords, 'estimated_loss')
);
$totalCostings = count($costings);
$totalMovements = count($movements);
$totalLowStock = count($lowStock);

$adminName = h($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php echo renderThemeOverrides($settings); ?>
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
                    <li><a href="../dashboard.php">Dashboard</a></li>
                    <li><a href="ingredients.php">Ingredients</a></li>
                    <li><a href="suppliers.php">Suppliers</a></li>
                    <li><a href="purchases.php">Purchases</a></li>
                    <li><a href="inventory.php">Inventory</a></li>
                    <li><a href="recipes.php">Recipes</a></li>
                    <li><a href="recipe_costing.php">Recipe Costing</a></li>
                    <li><a href="reports.php" class="active">Reports</a></li>
                    <li><a href="settings.php">Settings</a></li>
                </ul>
            </nav>
        </div>

        <div class="sidebar-footer">
            <a href="../logout.php" class="logout-link">Logout</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content dark-main">

        <!-- TOPBAR -->
        <header class="topbar dark-topbar">
            <div>
                <h1>Reports and Analytics</h1>
                <p class="topbar-subtitle">Review ingredient stock, inventory movements, waste/spoilage, purchases and recipe costing records.</p>
            </div>

            <div class="topbar-controls">
                <input
                    type="search"
                    id="reportSearch"
                    class="dark-search-input"
                    placeholder="Filter report rows..."
                    aria-label="Filter report rows"
                >
                <span class="admin-badge">Admin: <?= $adminName ?></span>
            </div>
        </header>

        <!-- SELECT REPORTING PERIOD CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Select Reporting Period</h2>
                <span class="card-tag tag-gold"><?= h($from) ?> to <?= h($to) ?></span>
            </div>

            <form method="GET" class="report-filter dark-report-filter">
                <div class="form-field">
                    <label for="from">From Date</label>
                    <input type="date" id="from" name="from"
                           value="<?= h($from) ?>" required>
                </div>
                <div class="form-field">
                    <label for="to">To Date</label>
                    <input type="date" id="to" name="to"
                           value="<?= h($to) ?>" required>
                </div>
                <div class="form-actions-bar">
                    <button type="submit" class="primary-button dark-submit-btn">
                        Generate Reports
                    </button>
                </div>
            </form>
        </section>

        <!-- SUMMARY KPI CARDS -->
        <section class="report-summary-grid dark-report-summary">
            <div class="report-summary-card dark-summary-card">
                <span>Low Stock Ingredients</span>
                <strong class="<?= $totalLowStock > 0 ? 'summary-warn' : '' ?>">
                    <?= number_format($totalLowStock) ?>
                </strong>
            </div>
            <div class="report-summary-card dark-summary-card">
                <span>Inventory Transactions</span>
                <strong><?= number_format($totalMovements) ?></strong>
            </div>
            <div class="report-summary-card dark-summary-card">
                <span>Total Purchases</span>
                <strong class="cell-highlight">
                    TZS <?= number_format($totalPurchases, 2) ?>
                </strong>
            </div>
            <div class="report-summary-card dark-summary-card">
                <span>Waste & Spoilage Loss</span>
                <strong class="<?= $totalWastageLoss > 0 ? 'summary-warn' : '' ?>">
                    TZS <?= number_format($totalWastageLoss, 2) ?>
                </strong>
            </div>
        </section>

        <!-- 1. LOW STOCK REPORT -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <div>
                    <h2>1. Low Stock Report</h2>
                    <p class="form-hint dark-hint">
                        These active ingredients have reached or fallen below their minimum stock levels.
                    </p>
                </div>
                <span class="card-tag <?= $totalLowStock > 0 ? 'tag-danger' : 'tag-neutral' ?>">
                    <?= number_format($totalLowStock) ?> Flagged
                </span>
            </div>

            <div class="table-container">
                <table class="dark-table report-table" id="lowStockReportTable">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Current Stock</th>
                            <th>Minimum Stock</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lowStock): ?>
                        <tr>
                            <td colspan="4" class="empty-row">No ingredients are currently below their minimum stock levels.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lowStock as $item): ?>
                        <tr>
                            <td class="cell-strong"><?= h($item['ingredient_name']) ?></td>
                            <td>
                                <span class="status-pill badge-danger">
                                    <?= number_format($item['current_stock'], 3) ?>
                                </span>
                            </td>
                            <td><?= number_format($item['minimum_stock'], 3) ?></td>
                            <td class="cell-muted"><?= h($item['base_unit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($lowStock) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        class="dark-secondary-btn disclosure-toggle-btn report-disclosure-btn"
                        data-target="#lowStockReportTable"
                        aria-expanded="false"
                        aria-controls="lowStockReportTable"
                    >
                        Show more (<?= count($lowStock) - 5 ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

        <!-- 2. INVENTORY MOVEMENT REPORT -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <div>
                    <h2>2. Inventory Movement Report</h2>
                    <p class="form-hint dark-hint">
                        Displays stock transactions recorded during the selected period. Negative quantities reduce stock; positive quantities increase it.
                    </p>
                </div>
                <span class="card-tag tag-neutral"><?= number_format($totalMovements) ?> Records</span>
            </div>

            <div class="table-container">
                <table class="dark-table report-table" id="movementReportTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ingredient</th>
                            <th>Transaction</th>
                            <th>Quantity Change</th>
                            <th>Balance After</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$movements): ?>
                        <tr>
                            <td colspan="6" class="empty-row">No inventory transactions found for this period.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($movements as $item): ?>
                        <?php
                            $typeRaw = strtolower((string)$item['transaction_type']);
                            $badgeClass = 'badge-neutral';
                            if ($typeRaw === 'purchase') {
                                $badgeClass = 'badge-success';
                            } elseif ($typeRaw === 'wastage' || $typeRaw === 'usage') {
                                $badgeClass = 'badge-danger';
                            } elseif ($typeRaw === 'adjustment') {
                                $badgeClass = 'badge-warning';
                            }
                        ?>
                        <tr>
                            <td class="cell-muted"><?= h($item['transaction_date']) ?></td>
                            <td class="cell-strong"><?= h($item['ingredient_name']) ?></td>
                            <td>
                                <span class="status-pill <?= $badgeClass ?>">
                                    <?= h(ucfirst($item['transaction_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <?= number_format($item['quantity_change'], 3) ?>
                            </td>
                            <td>
                                <?= number_format($item['balance_after'], 3) ?>
                            </td>
                            <td class="cell-muted"><?= h($item['base_unit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($movements) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        class="dark-secondary-btn disclosure-toggle-btn report-disclosure-btn"
                        data-target="#movementReportTable"
                        aria-expanded="false"
                        aria-controls="movementReportTable"
                    >
                        Show more (<?= count($movements) - 5 ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

        <!-- 3. PURCHASE REPORT -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>3. Purchase Report</h2>
                <span class="card-tag tag-neutral"><?= count($purchases) ?> Orders</span>
            </div>

            <div class="table-container">
                <table class="dark-table report-table" id="purchaseReportTable">
                    <thead>
                        <tr>
                            <th>Purchase ID</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Items</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$purchases): ?>
                        <tr>
                            <td colspan="5" class="empty-row">No purchases found for this period.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($purchases as $purchase): ?>
                        <tr>
                            <td class="cell-strong">#<?= h($purchase['purchase_id']) ?></td>
                            <td class="cell-muted"><?= h($purchase['purchase_date']) ?></td>
                            <td><?= h($purchase['supplier_name'] ?? 'Not recorded') ?></td>
                            <td><?= h($purchase['item_count']) ?></td>
                            <td class="cell-strong">
                                TZS <?= number_format($purchase['total_amount'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($purchases) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        class="dark-secondary-btn disclosure-toggle-btn report-disclosure-btn"
                        data-target="#purchaseReportTable"
                        aria-expanded="false"
                        aria-controls="purchaseReportTable"
                    >
                        Show more (<?= count($purchases) - 5 ?> more)
                    </button>
                </div>
            <?php endif; ?>

            <div class="dark-report-total">
                <span>Total Purchase Expenditure:</span>
                <strong class="cell-highlight">TZS <?= number_format($totalPurchases, 2) ?></strong>
            </div>
        </section>

        <!-- 4. RECIPE COSTING REPORT -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>4. Recipe Costing Report (<?= number_format($totalCostings) ?> Calculations)</h2>
                <span class="card-tag tag-neutral"><?= number_format($totalCostings) ?> Records</span>
            </div>

            <div class="table-container">
                <table class="dark-table report-table" id="costingReportTable">
                    <thead>
                        <tr>
                            <th>Recipe</th>
                            <th>Batch Yield</th>
                            <th>Ingredient Cost</th>
                            <th>Other Cost</th>
                            <th>Total Production Cost</th>
                            <th>Cost per Product</th>
                            <th>Margin</th>
                            <th>Suggested Price</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$costings): ?>
                        <tr>
                            <td colspan="9" class="empty-row">No costing records found for this period.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($costings as $record): ?>
                        <tr>
                            <td class="cell-strong"><?= h($record['recipe_name']) ?></td>
                            <td>
                                <?= h($record['batch_yield']) ?>
                                <?= h($record['yield_unit']) ?>
                            </td>
                            <td>
                                TZS <?= number_format($record['ingredient_cost'], 2) ?>
                            </td>
                            <td>
                                TZS <?= number_format($record['other_cost'], 2) ?>
                            </td>
                            <td class="cell-strong">
                                TZS <?= number_format($record['total_production_cost'], 2) ?>
                            </td>
                            <td>
                                TZS <?= number_format($record['cost_per_product'], 2) ?>
                            </td>
                            <td>
                                <span class="status-pill badge-neutral">
                                    <?= number_format($record['target_profit_margin'], 2) ?>%
                                </span>
                            </td>
                            <td class="cell-highlight">
                                TZS <?= number_format($record['suggested_selling_price'], 2) ?>
                            </td>
                            <td class="cell-muted"><?= h($record['calculated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($costings) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        class="dark-secondary-btn disclosure-toggle-btn report-disclosure-btn"
                        data-target="#costingReportTable"
                        aria-expanded="false"
                        aria-controls="costingReportTable"
                    >
                        Show more (<?= count($costings) - 5 ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

        <!-- 5. WASTE & SPOILAGE REPORT -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <div>
                    <h2>5. Waste and Spoilage Report</h2>
                    <p class="form-hint dark-hint">
                        Tracks spoiled, expired, or damaged ingredients and their estimated cost impact during the selected period.
                    </p>
                </div>
                <span class="card-tag <?= count($wastageRecords) > 0 ? 'tag-danger' : 'tag-neutral' ?>">
                    <?= count($wastageRecords) ?> Incidents
                </span>
            </div>

            <div class="table-container">
                <table class="dark-table report-table" id="wastageReportTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ingredient</th>
                            <th>Reason</th>
                            <th>Quantity Wasted</th>
                            <th>Estimated Loss</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$wastageRecords): ?>
                        <tr>
                            <td colspan="6" class="empty-row">No waste or spoilage incidents recorded for this period.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($wastageRecords as $waste): ?>
                        <tr>
                            <td class="cell-muted"><?= h($waste['transaction_date']) ?></td>
                            <td class="cell-strong"><?= h($waste['ingredient_name']) ?></td>
                            <td>
                                <span class="status-pill badge-danger">
                                    <?= h($waste['waste_category'] ?: 'Uncategorized') ?>
                                </span>
                            </td>
                            <td>
                                <?= number_format($waste['wasted_quantity'], 3) ?>
                                <?= h($waste['base_unit']) ?>
                            </td>
                            <td class="stock-low-text">
                                TZS <?= number_format($waste['estimated_loss'], 2) ?>
                            </td>
                            <td class="cell-muted"><?= h($waste['notes'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($wastageRecords) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        class="dark-secondary-btn disclosure-toggle-btn report-disclosure-btn"
                        data-target="#wastageReportTable"
                        aria-expanded="false"
                        aria-controls="wastageReportTable"
                    >
                        Show more (<?= count($wastageRecords) - 5 ?> more)
                    </button>
                </div>
            <?php endif; ?>

            <div class="dark-report-total">
                <span>Total Waste & Spoilage Loss:</span>
                <strong class="summary-warn">TZS <?= number_format($totalWastageLoss, 2) ?></strong>
            </div>
        </section>

    </main>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("reportSearch");
    const tables = Array.from(document.querySelectorAll(".report-table"));
    if (tables.length === 0) return;

    const controllers = tables.map(function (table) {
        const tbody = table.querySelector("tbody");
        const toggleBtn = table.id
            ? document.querySelector('.report-disclosure-btn[data-target="#' + table.id + '"]')
            : null;

        const dataRows = tbody
            ? Array.from(tbody.querySelectorAll("tr")).filter(row => !row.querySelector(".empty-row"))
            : [];
        const totalRows = dataRows.length;
        let expanded = false;

        function updateDisclosureButton(matchingCount) {
            if (!toggleBtn) return;
            const term = searchInput ? searchInput.value.trim() : "";

            if (term !== "" || totalRows <= INITIAL_VISIBLE_ROWS) {
                toggleBtn.style.display = "none";
                return;
            }

            toggleBtn.style.display = "inline-flex";
            toggleBtn.setAttribute("aria-expanded", expanded ? "true" : "false");

            if (expanded) {
                toggleBtn.textContent = "Show less";
            } else {
                const hiddenCount = Math.max(0, (matchingCount ?? totalRows) - INITIAL_VISIBLE_ROWS);
                toggleBtn.textContent = "Show more (" + hiddenCount + " more)";
            }
        }

        function apply() {
            const term = searchInput ? searchInput.value.trim().toLowerCase() : "";
            let matchingIndex = 0;

            dataRows.forEach(function (row) {
                const matches = term === "" || row.textContent.toLowerCase().includes(term);

                if (!matches) {
                    row.style.display = "none";
                    return;
                }

                if (term !== "" || expanded || matchingIndex < INITIAL_VISIBLE_ROWS) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }

                matchingIndex++;
            });

            updateDisclosureButton(matchingIndex);
        }

        if (toggleBtn) {
            toggleBtn.addEventListener("click", function () {
                expanded = !expanded;
                apply();
            });
        }

        return { apply };
    });

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            controllers.forEach(ctrl => ctrl.apply());
        });
    }

    controllers.forEach(ctrl => ctrl.apply());
});
</script>
</body>
</html>