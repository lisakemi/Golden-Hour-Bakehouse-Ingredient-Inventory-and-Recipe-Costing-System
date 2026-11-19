<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once "../config/database.php";
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
        t.notes,
        t.transaction_date
     FROM inventory_transactions t
     INNER JOIN ingredients i
        ON t.ingredient_id = i.ingredient_id
     WHERE t.transaction_date BETWEEN ? AND ?
     ORDER BY t.transaction_date DESC"
);
$movementQuery->execute([$startDateTime, $endDateTime]);
$movements = $movementQuery->fetchAll();
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
$totalCostings = count($costings);
$totalMovements = count($movements);
$totalLowStock = count($lowStock);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Reports and Analytics</h1>
            <p>
                Review ingredient stock, inventory movements,
                purchases and recipe costing records.
            </p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <section class="content-section">
        <h2>Select Reporting Period</h2>
        <form method="GET" class="report-filter">
            <div>
                <label for="from">From Date</label>
                <input type="date" id="from" name="from"
                       value="<?= h($from) ?>" required>
            </div>
            <div>
                <label for="to">To Date</label>
                <input type="date" id="to" name="to"
                       value="<?= h($to) ?>" required>
            </div>
            <button type="submit" class="primary-button">
                Generate Reports
            </button>
        </form>
    </section>
    <section class="report-summary-grid">
        <div class="report-summary-card">
            <span>Low Stock Ingredients</span>
            <strong><?= number_format($totalLowStock) ?></strong>
        </div>
        <div class="report-summary-card">
            <span>Inventory Transactions</span>
            <strong><?= number_format($totalMovements) ?></strong>
        </div>
        <div class="report-summary-card">
            <span>Total Purchases</span>
            <strong>
                TZS <?= number_format($totalPurchases, 2) ?>
            </strong>
        </div>
        <div class="report-summary-card">
            <span>Costing Calculations</span>
            <strong><?= number_format($totalCostings) ?></strong>
        </div>
    </section>
    <!-- LOW STOCK REPORT -->
    <section class="content-section">
        <h2>1. Low Stock Report</h2>
        <p>
            These active ingredients have reached or fallen below
            their minimum stock levels.
        </p>
        <div class="table-container">
            <table>
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
                        <td colspan="4">No ingredients are currently below their minimum stock levels.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($lowStock as $item): ?>
                    <tr>
                        <td><?= h($item['ingredient_name']) ?></td>
                        <td><?= number_format($item['current_stock'], 3) ?></td>
                        <td><?= number_format($item['minimum_stock'], 3) ?></td>
                        <td><?= h($item['base_unit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <!-- INVENTORY MOVEMENT REPORT -->
    <section class="content-section">
        <h2>2. Inventory Movement Report</h2>
        <p>
            Displays stock transactions recorded during the selected period.
            Negative quantities reduce stock; positive quantities increase it.
        </p>
        <div class="table-container">
            <table>
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
                        <td colspan="6">No inventory transactions found for this period.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($movements as $item): ?>
                    <tr>
                        <td><?= h($item['transaction_date']) ?></td>
                        <td><?= h($item['ingredient_name']) ?></td>
                        <td><?= h(ucfirst($item['transaction_type'])) ?></td>
                        <td>
                            <?= number_format($item['quantity_change'], 3) ?>
                        </td>
                        <td>
                            <?= number_format($item['balance_after'], 3) ?>
                        </td>
                        <td><?= h($item['base_unit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <!-- PURCHASE REPORT -->
    <section class="content-section">
        <h2>3. Purchase Report</h2>
        <div class="table-container">
            <table>
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
                        <td colspan="5">No purchases found for this period.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($purchases as $purchase): ?>
                    <tr>
                        <td><?= h($purchase['purchase_id']) ?></td>
                        <td><?= h($purchase['purchase_date']) ?></td>
                        <td><?= h($purchase['supplier_name'] ?? 'Not recorded') ?></td>
                        <td><?= h($purchase['item_count']) ?></td>
                        <td>
                            TZS <?= number_format($purchase['total_amount'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="report-total">
            Total Purchase Expenditure:
            <strong>TZS <?= number_format($totalPurchases, 2) ?></strong>
        </p>
    </section>
    <!-- RECIPE COSTING REPORT -->
    <section class="content-section">
        <h2>4. Recipe Costing Report</h2>
        <div class="table-container">
            <table>
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
                        <td colspan="9">No costing records found for this period.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($costings as $record): ?>
                    <tr>
                        <td><?= h($record['recipe_name']) ?></td>
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
                        <td>
                            TZS <?= number_format($record['total_production_cost'], 2) ?>
                        </td>
                        <td>
                            TZS <?= number_format($record['cost_per_product'], 2) ?>
                        </td>
                        <td>
                            <?= number_format($record['target_profit_margin'], 2) ?>%
                        </td>
                        <td>
                            TZS <?= number_format($record['suggested_selling_price'], 2) ?>
                        </td>
                        <td><?= h($record['calculated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
