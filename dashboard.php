<?php
session_start();
require_once "config/database.php";
// Protect the dashboard
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
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
// Retrieve recent inventory activities
$sql = "SELECT
            inventory_transactions.*,
            ingredients.ingredient_name
        FROM inventory_transactions
        INNER JOIN ingredients
        ON inventory_transactions.ingredient_id =
           ingredients.ingredient_id
        ORDER BY transaction_date DESC
        LIMIT 5";
$stmt = $pdo->query($sql);
$recentTransactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="dashboard-container">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="logo">
            <h2>Golden Hour</h2>
            <p>BAKEHOUSE MANAGEMENT</p>
        </div>
        <ul>
            <li><a href="dashboard.php" class="active">Dashboard</a></li>
            <li><a href="pages/ingredients.php">Ingredients</a></li>
            <li><a href="pages/suppliers.php">Suppliers</a></li>
            <li><a href="pages/purchases.php">Purchases</a></li>
            <li><a href="pages/inventory.php">Inventory Management</a></li>
            <li><a href="pages/recipes.php">Recipes</a></li>
            <li><a href="pages/recipe_costing.php">Recipe Costing</a></li>
            <li><a href="pages/reports.php">Reports</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>
    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome to your bakery management system.</p>
            </div>
            <div class="admin-info">
                Administrator:
                <?php echo htmlspecialchars($_SESSION["full_name"]); ?>
            </div>
        </div>
        <!-- SUMMARY CARDS -->
        <div class="summary-cards">
            <div class="card">
                <h3>Total Ingredients</h3>
                <p><?php echo $totalIngredients; ?></p>
            </div>
            <div class="card">
                <h3>Total Recipes</h3>
                <p><?php echo $totalRecipes; ?></p>
            </div>
            <div class="card">
                <h3>Total Suppliers</h3>
                <p><?php echo $totalSuppliers; ?></p>
            </div>
            <div class="card">
                <h3>Low Stock Alerts</h3>
                <p><?php echo $lowStock; ?></p>
            </div>
        </div>
        <!-- RECENT INVENTORY ACTIVITIES -->
        <section class="content-section">
            <h2>Recent Inventory Activities</h2>
            <table>
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Activity</th>
                        <th>Quantity Change</th>
                        <th>Remaining Stock</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recentTransactions) > 0): ?>
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($transaction["ingredient_name"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction["transaction_type"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction["quantity_change"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction["balance_after"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction["transaction_date"]); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">
                                No inventory activities recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <!-- QUICK ACTIONS -->
        <section class="content-section">
            <h2>Quick Actions</h2>
            <div class="quick-actions">
                <a href="pages/ingredients.php">Manage Ingredients</a>
                <a href="pages/suppliers.php">Manage Suppliers</a>
                <a href="pages/purchases.php">Record Purchase</a>
                <a href="pages/recipes.php">Manage Recipes</a>
            </div>
        </section>
    </main>
</div>
</body>
</html>
