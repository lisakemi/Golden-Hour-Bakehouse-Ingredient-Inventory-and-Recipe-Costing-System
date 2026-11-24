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

function resolveRecipeIngredientQtyColumn(PDO $pdo): string {
    static $resolvedColumn = null;
    if ($resolvedColumn !== null) {
        return $resolvedColumn;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM recipe_ingredients");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, "Field");

    $candidates = [
        "quantity_in_base_unit",
        "quantity_required",
        "quantity",
        "qty",
        "amount"
    ];

    foreach ($candidates as $col) {
        if (in_array($col, $names, true)) {
            $resolvedColumn = $col;
            return $resolvedColumn;
        }
    }

    foreach ($columns as $colInfo) {
        $field = $colInfo["Field"] ?? "";
        $type = strtolower((string) ($colInfo["Type"] ?? ""));
        if (
            !in_array($field, ["recipe_ingredient_id", "id", "recipe_id", "ingredient_id"], true) &&
            (str_contains($type, "decimal") || str_contains($type, "double") || str_contains($type, "float") || str_contains($type, "int"))
        ) {
            $resolvedColumn = $field;
            return $resolvedColumn;
        }
    }

    throw new RuntimeException("Could not determine the quantity column on recipe_ingredients.");
}

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$errors = [];
$calculation = null;
$riQtyCol = resolveRecipeIngredientQtyColumn($pdo);

// Fetch active recipes
$recipesStmt = $pdo->query(
    "SELECT recipe_id, recipe_name, batch_yield, yield_unit
     FROM recipes
     WHERE is_active = 1
     ORDER BY recipe_name"
);
$recipes = $recipesStmt->fetchAll();

// Preload recipe ingredients & current stock for live batch-sizing preview
$recipeIngredientsRaw = $pdo->query(
    "SELECT
        ri.recipe_id,
        ri.`{$riQtyCol}` AS quantity_required,
        i.ingredient_id,
        i.ingredient_name,
        i.base_unit,
        i.current_stock,
        i.average_cost_per_unit
     FROM recipe_ingredients ri
     INNER JOIN ingredients i
        ON ri.ingredient_id = i.ingredient_id
     INNER JOIN recipes r
        ON ri.recipe_id = r.recipe_id
     WHERE r.is_active = 1
     ORDER BY i.ingredient_name ASC"
)->fetchAll();

$recipeIngredientsMap = [];
foreach ($recipeIngredientsRaw as $row) {
    $rid = (int) $row['recipe_id'];
    if (!isset($recipeIngredientsMap[$rid])) {
        $recipeIngredientsMap[$rid] = [];
    }
    $recipeIngredientsMap[$rid][] = [
        'ingredient_id' => (int) $row['ingredient_id'],
        'ingredient_name' => $row['ingredient_name'],
        'base_unit' => $row['base_unit'],
        'quantity_required' => (float) $row['quantity_required'],
        'current_stock' => (float) $row['current_stock'],
        'average_cost_per_unit' => (float) $row['average_cost_per_unit']
    ];
}

$recipeMetaMap = [];
foreach ($recipes as $r) {
    $recipeMetaMap[(int) $r['recipe_id']] = [
        'batch_yield' => (float) $r['batch_yield'],
        'yield_unit' => $r['yield_unit']
    ];
}

$selectedRecipeId = isset($_POST['recipe_id']) && ctype_digit((string)$_POST['recipe_id'])
    ? (int) $_POST['recipe_id']
    : 0;
$submittedMultiplier = isset($_POST['batch_multiplier']) && is_numeric($_POST['batch_multiplier'])
    ? (float) $_POST['batch_multiplier']
    : 1.00;
$submittedMargin = isset($_POST['target_profit_margin']) && is_numeric($_POST['target_profit_margin'])
    ? (float) $_POST['target_profit_margin']
    : 30;

// Calculate and save recipe cost
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $errors[] = "Invalid request. Please refresh the page and try again.";
    } else {
        $recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT);
        $batchMultiplier = filter_input(INPUT_POST, 'batch_multiplier', FILTER_VALIDATE_FLOAT);
        $targetMargin = filter_input(INPUT_POST, 'target_profit_margin', FILTER_VALIDATE_FLOAT);

        if (!$recipeId || $recipeId <= 0) {
            $errors[] = "Please select a valid recipe.";
        }
        if ($batchMultiplier === false || $batchMultiplier <= 0 || $batchMultiplier > 1000) {
            $errors[] = "Batch multiplier must be greater than 0 and up to 1,000.";
        }
        if ($targetMargin === false || $targetMargin < 0 || $targetMargin >= 100) {
            $errors[] = "Target profit margin must be between 0% and 99.99%.";
        }

        if (!$errors) {
            try {
                // Verify recipe is active
                $recipeStmt = $pdo->prepare(
                    "SELECT recipe_id, recipe_name, batch_yield, yield_unit
                     FROM recipes
                     WHERE recipe_id = ? AND is_active = 1"
                );
                $recipeStmt->execute([$recipeId]);
                $recipe = $recipeStmt->fetch();

                if (!$recipe) {
                    $errors[] = "Selected recipe was not found.";
                } else {
                    $baseYield = (float) $recipe['batch_yield'];
                    if ($baseYield <= 0) {
                        $errors[] = "Recipe batch yield must be greater than zero.";
                    } else {
                        $multiplier = round((float) $batchMultiplier, 2);
                        $scaledYieldTotal = round($baseYield * $multiplier, 2);

                        // Fetch recipe ingredients and current average cost + stock
                        $ingStmt = $pdo->prepare(
                            "SELECT
                                ri.`{$riQtyCol}` AS quantity_required,
                                i.ingredient_name,
                                i.base_unit,
                                i.current_stock,
                                i.average_cost_per_unit
                             FROM recipe_ingredients ri
                             INNER JOIN ingredients i
                                ON ri.ingredient_id = i.ingredient_id
                             WHERE ri.recipe_id = ?
                             ORDER BY i.ingredient_name"
                        );
                        $ingStmt->execute([$recipeId]);
                        $ingredients = $ingStmt->fetchAll();

                        if (!$ingredients) {
                            $errors[] = "This recipe has no ingredients assigned.";
                        } else {
                            $ingredientCost = 0.0;
                            $ingredientBreakdown = [];
                            $hasShortage = false;

                            foreach ($ingredients as $item) {
                                $baseQty = (float) $item['quantity_required'];
                                $scaledQty = round($baseQty * $multiplier, 3);
                                $currentStock = (float) $item['current_stock'];
                                $shortfall = max(0.0, round($scaledQty - $currentStock, 3));
                                if ($shortfall > 0) {
                                    $hasShortage = true;
                                }

                                $unitCost = (float) $item['average_cost_per_unit'];
                                $lineCost = round($scaledQty * $unitCost, 2);
                                $ingredientCost += $lineCost;

                                $ingredientBreakdown[] = [
                                    'name' => $item['ingredient_name'],
                                    'unit' => $item['base_unit'],
                                    'base_quantity' => $baseQty,
                                    'scaled_quantity' => $scaledQty,
                                    'current_stock' => $currentStock,
                                    'shortfall' => $shortfall,
                                    'unit_cost' => $unitCost,
                                    'line_cost' => $lineCost
                                ];
                            }

                            // Fetch other recipe costs and scale by batch multiplier
                            $otherStmt = $pdo->prepare(
                                "SELECT cost_name, cost_amount
                                 FROM recipe_other_costs
                                 WHERE recipe_id = ?
                                 ORDER BY cost_name"
                            );
                            $otherStmt->execute([$recipeId]);
                            $otherCosts = $otherStmt->fetchAll();

                            $otherCostTotal = 0.0;
                            $scaledOtherCosts = [];
                            foreach ($otherCosts as $cost) {
                                $baseAmount = (float) $cost['cost_amount'];
                                $scaledAmount = round($baseAmount * $multiplier, 2);
                                $otherCostTotal += $scaledAmount;
                                $scaledOtherCosts[] = [
                                    'cost_name' => $cost['cost_name'],
                                    'base_amount' => $baseAmount,
                                    'scaled_amount' => $scaledAmount
                                ];
                            }

                            $totalProductionCost = round($ingredientCost + $otherCostTotal, 2);
                            $costPerProduct = round($totalProductionCost / $scaledYieldTotal, 2);
                            $margin = (float) $targetMargin;
                            $suggestedSellingPrice = round(
                                $costPerProduct / (1 - ($margin / 100)),
                                2
                            );

                            // Save costing record to history
                            $historyStmt = $pdo->prepare(
                                "INSERT INTO recipe_cost_history (
                                    recipe_id,
                                    batch_yield,
                                    batch_multiplier,
                                    scaled_yield_total,
                                    yield_unit,
                                    ingredient_cost,
                                    other_cost,
                                    total_production_cost,
                                    cost_per_product,
                                    target_profit_margin,
                                    suggested_selling_price
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                            );
                            $historyStmt->execute([
                                $recipeId,
                                $baseYield,
                                $multiplier,
                                $scaledYieldTotal,
                                $recipe['yield_unit'],
                                round($ingredientCost, 2),
                                round($otherCostTotal, 2),
                                $totalProductionCost,
                                $costPerProduct,
                                $margin,
                                $suggestedSellingPrice
                            ]);

                            $calculation = [
                                'recipe_name' => $recipe['recipe_name'],
                                'batch_yield' => $baseYield,
                                'batch_multiplier' => $multiplier,
                                'scaled_yield_total' => $scaledYieldTotal,
                                'yield_unit' => $recipe['yield_unit'],
                                'ingredients' => $ingredientBreakdown,
                                'has_shortage' => $hasShortage,
                                'other_costs' => $scaledOtherCosts,
                                'ingredient_cost' => round($ingredientCost, 2),
                                'other_cost' => round($otherCostTotal, 2),
                                'total_production_cost' => $totalProductionCost,
                                'cost_per_product' => $costPerProduct,
                                'target_profit_margin' => $margin,
                                'suggested_selling_price' => $suggestedSellingPrice
                            ];
                        }
                    }
                }
            } catch (PDOException $e) {
                $errors[] = "A database error occurred while calculating recipe cost.";
            }
        }
    }
}

// Fetch recent recipe cost history
$historyStmt = $pdo->query(
    "SELECT
        h.cost_history_id,
        r.recipe_name,
        h.batch_yield,
        h.batch_multiplier,
        h.scaled_yield_total,
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
     ORDER BY h.calculated_at DESC
     LIMIT 20"
);
$history = $historyStmt->fetchAll();

$adminName = h($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipe Costing & Batch Calculator | Golden Hour Bakehouse</title>
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
                    <li><a href="recipe_costing.php" class="active">Recipe Costing</a></li>
                    <li><a href="reports.php">Reports</a></li>
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
                <h1>Recipe Costing & Batch Calculator</h1>
                <p class="topbar-subtitle">Scale batch sizes, check live ingredient stock, and calculate production cost & selling prices.</p>
            </div>

            <div class="topbar-controls">
                <span class="admin-badge">Admin: <?= $adminName ?></span>
            </div>
        </header>

        <?php if ($errors): ?>
            <div class="dark-alert alert-error" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- CALCULATE RECIPE COST & BATCH SIZING CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Batch-Sizing & Cost Calculator</h2>
                <span class="card-tag tag-gold">Scale & Pricing Engine</span>
            </div>

            <?php if (count($recipes) > 0): ?>
                <form method="POST" class="dark-form" id="batchCalculatorForm">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION["csrf_token"]) ?>"
                    >

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="recipe_id">Select Recipe <span class="req-star">*</span></label>
                            <select name="recipe_id" id="recipe_id" required>
                                <option value="">-- Choose a Recipe --</option>
                                <?php foreach ($recipes as $recipe): ?>
                                    <?php $rid = (int) $recipe['recipe_id']; ?>
                                    <option
                                        value="<?= $rid ?>"
                                        data-base-yield="<?= h($recipe['batch_yield']) ?>"
                                        data-yield-unit="<?= h($recipe['yield_unit']) ?>"
                                        <?= $selectedRecipeId === $rid ? 'selected' : '' ?>
                                    >
                                        <?= h($recipe['recipe_name']) ?>
                                        (Base Yield: <?= h($recipe['batch_yield']) ?> <?= h($recipe['yield_unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint dark-hint" id="baseYieldHint">
                                Select a recipe to inspect base yield and live stock requirements.
                            </p>
                        </div>

                        <div class="form-field">
                            <label for="target_profit_margin">Target Profit Margin (%) <span class="req-star">*</span></label>
                            <input
                                type="number"
                                name="target_profit_margin"
                                id="target_profit_margin"
                                min="0"
                                max="99.99"
                                step="0.01"
                                value="<?= h($submittedMargin) ?>"
                                required
                            >
                            <p class="form-hint dark-hint">
                                Suggested price = Cost per product ÷ (1 - margin).
                            </p>
                        </div>
                    </div>

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="batch_multiplier">Batch Multiplier (e.g., 1 = 1x batch, 2.5 = 2.5x) <span class="req-star">*</span></label>
                            <input
                                type="number"
                                name="batch_multiplier"
                                id="batch_multiplier"
                                min="0.01"
                                max="1000"
                                step="0.01"
                                value="<?= h(number_format($submittedMultiplier, 2, '.', '')) ?>"
                                required
                            >
                            <p class="form-hint dark-hint">
                                Adjust multiplier or target output yield below; both fields stay synced.
                            </p>
                        </div>

                        <div class="form-field">
                            <label for="target_output_yield" id="targetYieldLabel">Target Output Yield</label>
                            <input
                                type="number"
                                id="target_output_yield"
                                min="0.01"
                                step="0.01"
                                placeholder="Select recipe first..."
                                disabled
                            >
                            <p class="form-hint dark-hint" id="targetYieldHint">
                                Enter desired number of units to auto-compute the batch multiplier.
                            </p>
                        </div>
                    </div>

                    <!-- LIVE SCALED INGREDIENT & STOCK PREVIEW -->
                    <div id="liveBatchPreview" style="display: none; margin-top: 12px;">
                        <div class="panel-header" style="margin-bottom: 10px;">
                            <h3>Live Scaled Ingredients & Stock Check</h3>
                            <span class="card-tag tag-neutral" id="livePreviewBadge">1.00x Batch</span>
                        </div>
                        <div class="table-container">
                            <table class="dark-table" id="liveBatchTable">
                                <thead>
                                    <tr>
                                        <th>Ingredient</th>
                                        <th>Base Qty</th>
                                        <th>Scaled Required</th>
                                        <th>Available Stock</th>
                                        <th>Stock Status</th>
                                        <th>Est. Cost</th>
                                    </tr>
                                </thead>
                                <tbody id="liveBatchTableBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-actions-bar">
                        <button type="submit" class="primary-button dark-submit-btn">
                            Calculate & Save Batch Cost
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p class="empty-note">No active recipes are available. Please create a recipe first.</p>
            <?php endif; ?>
        </section>

        <?php if ($calculation): ?>
            <!-- COST CALCULATION SUMMARY CARD -->
            <section class="content-section dark-panel subpage-card">
                <div class="panel-header">
                    <h2>Batch Cost Summary: <?= h($calculation['recipe_name']) ?></h2>
                    <span class="card-tag <?= $calculation['has_shortage'] ? 'tag-danger' : 'tag-gold' ?>">
                        <?= number_format($calculation['batch_multiplier'], 2) ?>x Batch
                        (<?= number_format($calculation['scaled_yield_total'], 2) ?> <?= h($calculation['yield_unit']) ?>)
                    </span>
                </div>

                <?php if ($calculation['has_shortage']): ?>
                    <div class="dark-alert alert-error" role="alert">
                        One or more ingredients have insufficient stock for this batch size. Check the shortfalls below before baking.
                    </div>
                <?php endif; ?>

                <div class="table-container">
                    <table class="dark-table">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Base Qty</th>
                                <th>Scaled Qty</th>
                                <th>In Stock</th>
                                <th>Stock Check</th>
                                <th>Unit Cost</th>
                                <th>Line Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($calculation['ingredients'] as $item): ?>
                            <tr>
                                <td class="cell-strong"><?= h($item['name']) ?></td>
                                <td class="cell-muted">
                                    <?= number_format($item['base_quantity'], 3) ?> <?= h($item['unit']) ?>
                                </td>
                                <td class="cell-strong">
                                    <?= number_format($item['scaled_quantity'], 3) ?> <?= h($item['unit']) ?>
                                </td>
                                <td>
                                    <?= number_format($item['current_stock'], 3) ?> <?= h($item['unit']) ?>
                                </td>
                                <td>
                                    <?php if ($item['shortfall'] > 0): ?>
                                        <span class="status-pill badge-danger">
                                            Short <?= number_format($item['shortfall'], 3) ?> <?= h($item['unit']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill badge-success">Sufficient</span>
                                    <?php endif; ?>
                                </td>
                                <td>TZS <?= number_format($item['unit_cost'], 4) ?></td>
                                <td class="cell-strong">TZS <?= number_format($item['line_cost'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($calculation['other_costs']): ?>
                    <div class="panel-header" style="margin-top: 22px;">
                        <h3>Scaled Additional Production Costs</h3>
                        <span class="card-tag tag-neutral"><?= number_format($calculation['batch_multiplier'], 2) ?>x Overhead</span>
                    </div>

                    <div class="table-container">
                        <table class="dark-table">
                            <thead>
                                <tr>
                                    <th>Cost Item</th>
                                    <th>Base Batch Amount</th>
                                    <th>Scaled Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($calculation['other_costs'] as $cost): ?>
                                <tr>
                                    <td class="cell-strong"><?= h($cost['cost_name']) ?></td>
                                    <td class="cell-muted">TZS <?= number_format($cost['base_amount'], 2) ?></td>
                                    <td>TZS <?= number_format($cost['scaled_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="cost-metrics-grid">
                    <div class="cost-metric-box">
                        <span>Scaled Yield</span>
                        <strong><?= number_format($calculation['scaled_yield_total'], 2) ?> <?= h($calculation['yield_unit']) ?></strong>
                    </div>
                    <div class="cost-metric-box">
                        <span>Total Ingredient Cost</span>
                        <strong>TZS <?= number_format($calculation['ingredient_cost'], 2) ?></strong>
                    </div>
                    <div class="cost-metric-box">
                        <span>Total Other Costs</span>
                        <strong>TZS <?= number_format($calculation['other_cost'], 2) ?></strong>
                    </div>
                    <div class="cost-metric-box">
                        <span>Total Production Cost</span>
                        <strong>TZS <?= number_format($calculation['total_production_cost'], 2) ?></strong>
                    </div>
                    <div class="cost-metric-box">
                        <span>Cost per <?= h($calculation['yield_unit']) ?></span>
                        <strong>TZS <?= number_format($calculation['cost_per_product'], 2) ?></strong>
                    </div>
                    <div class="cost-metric-box cost-metric-highlight">
                        <span>Suggested Price (<?= number_format($calculation['target_profit_margin'], 2) ?>% Margin)</span>
                        <strong>TZS <?= number_format($calculation['suggested_selling_price'], 2) ?></strong>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <div class="between-cards-search-bar">
            <input
                type="search"
                id="costingHistorySearch"
                class="dark-search-input"
                placeholder="Filter costing history..."
                aria-label="Filter costing history"
            >
        </div>

        <!-- RECENT RECIPE COSTING HISTORY CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Recent Recipe Costing History</h2>
                <span class="card-tag tag-neutral"><?= count($history) ?> Records</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="costingHistoryTable">
                    <thead>
                        <tr>
                            <th>Recipe</th>
                            <th>Batch Scale</th>
                            <th>Total Yield</th>
                            <th>Ingredient Cost</th>
                            <th>Other Cost</th>
                            <th>Total Cost</th>
                            <th>Cost per Unit</th>
                            <th>Margin</th>
                            <th>Suggested Price</th>
                            <th>Calculated At</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($history) > 0): ?>
                        <?php foreach ($history as $record): ?>
                            <?php
                                $mult = (float) ($record['batch_multiplier'] ?? 1.00);
                                $scaledYield = $record['scaled_yield_total'] !== null
                                    ? (float) $record['scaled_yield_total']
                                    : (float) $record['batch_yield'];
                            ?>
                            <tr>
                                <td class="cell-strong"><?= h($record['recipe_name']) ?></td>
                                <td>
                                    <span class="status-pill <?= $mult != 1.0 ? 'badge-warning' : 'badge-neutral' ?>">
                                        <?= number_format($mult, 2) ?>x
                                    </span>
                                </td>
                                <td>
                                    <?= number_format($scaledYield, 2) ?>
                                    <?= h($record['yield_unit']) ?>
                                </td>
                                <td>TZS <?= number_format($record['ingredient_cost'], 2) ?></td>
                                <td>TZS <?= number_format($record['other_cost'], 2) ?></td>
                                <td class="cell-strong">
                                    TZS <?= number_format($record['total_production_cost'], 2) ?>
                                </td>
                                <td>TZS <?= number_format($record['cost_per_product'], 2) ?></td>
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
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="empty-row">No recipe cost calculations recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($history) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="toggleCostingHistoryBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="costingHistoryTable"
                    >
                        Show more (<?= count($history) - 5 ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

    </main>

</div>

<script>
const recipeIngredientsMap = <?= json_encode($recipeIngredientsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const recipeMetaMap = <?= json_encode($recipeMetaMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.addEventListener("DOMContentLoaded", function () {
    const recipeSelect = document.getElementById("recipe_id");
    const multiplierInput = document.getElementById("batch_multiplier");
    const targetYieldInput = document.getElementById("target_output_yield");
    const targetYieldLabel = document.getElementById("targetYieldLabel");
    const baseYieldHint = document.getElementById("baseYieldHint");
    const livePreview = document.getElementById("liveBatchPreview");
    const livePreviewBadge = document.getElementById("livePreviewBadge");
    const liveTableBody = document.getElementById("liveBatchTableBody");

    function escapeHtml(str) {
        const div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
    }

    function renderLiveBatchPreview() {
        if (!recipeSelect || !multiplierInput || !livePreview || !liveTableBody) return;

        const recipeId = recipeSelect.value;
        const meta = recipeMetaMap[recipeId];
        const items = recipeIngredientsMap[recipeId] || [];

        if (!meta) {
            targetYieldInput.disabled = true;
            targetYieldInput.value = "";
            targetYieldLabel.textContent = "Target Output Yield";
            baseYieldHint.textContent = "Select a recipe to inspect base yield and live stock requirements.";
            livePreview.style.display = "none";
            return;
        }

        const baseYield = Number(meta.batch_yield || 0);
        const yieldUnit = meta.yield_unit || "units";
        const mult = Math.max(0.01, Number(multiplierInput.value || 1));
        const scaledYield = baseYield * mult;

        targetYieldInput.disabled = false;
        targetYieldLabel.textContent = "Target Output Yield (" + yieldUnit + ")";
        baseYieldHint.textContent = "Base recipe yields " + baseYield.toFixed(2) + " " + yieldUnit + " per 1.00x batch.";

        if (document.activeElement !== targetYieldInput) {
            targetYieldInput.value = scaledYield.toFixed(2);
        }

        livePreviewBadge.textContent = mult.toFixed(2) + "x Batch (" + scaledYield.toFixed(2) + " " + yieldUnit + ")";
        liveTableBody.innerHTML = "";

        if (items.length === 0) {
            liveTableBody.innerHTML = '<tr><td colspan="6" class="empty-row">No ingredients assigned to this recipe.</td></tr>';
        } else {
            items.forEach(function (item) {
                const baseQty = Number(item.quantity_required);
                const scaledQty = baseQty * mult;
                const stock = Number(item.current_stock);
                const shortfall = Math.max(0, scaledQty - stock);
                const estCost = scaledQty * Number(item.average_cost_per_unit);

                const statusHtml = shortfall > 0.0005
                    ? '<span class="status-pill badge-danger">Short ' + shortfall.toFixed(3) + ' ' + escapeHtml(item.base_unit) + '</span>'
                    : '<span class="status-pill badge-success">In Stock</span>';

                const tr = document.createElement("tr");
                tr.innerHTML =
                    '<td class="cell-strong">' + escapeHtml(item.ingredient_name) + '</td>' +
                    '<td class="cell-muted">' + baseQty.toFixed(3) + ' ' + escapeHtml(item.base_unit) + '</td>' +
                    '<td class="cell-strong">' + scaledQty.toFixed(3) + ' ' + escapeHtml(item.base_unit) + '</td>' +
                    '<td>' + stock.toFixed(3) + ' ' + escapeHtml(item.base_unit) + '</td>' +
                    '<td>' + statusHtml + '</td>' +
                    '<td>TZS ' + estCost.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
                liveTableBody.appendChild(tr);
            });
        }

        livePreview.style.display = "block";
    }

    if (recipeSelect) {
        recipeSelect.addEventListener("change", renderLiveBatchPreview);
    }

    if (multiplierInput) {
        multiplierInput.addEventListener("input", renderLiveBatchPreview);
    }

    if (targetYieldInput) {
        targetYieldInput.addEventListener("input", function () {
            const recipeId = recipeSelect ? recipeSelect.value : "";
            const meta = recipeMetaMap[recipeId];
            if (!meta || Number(meta.batch_yield) <= 0) return;

            const desiredYield = Number(this.value);
            if (desiredYield > 0) {
                const computedMult = desiredYield / Number(meta.batch_yield);
                multiplierInput.value = computedMult.toFixed(2);
                renderLiveBatchPreview();
            }
        });
    }

    renderLiveBatchPreview();

    // 5-row disclosure & search for Costing History table
    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("costingHistorySearch");
    const tableBody = document.querySelector("#costingHistoryTable tbody");
    const toggleBtn = document.getElementById("toggleCostingHistoryBtn");
    if (!tableBody) return;

    const dataRows = Array.from(tableBody.querySelectorAll("tr")).filter(
        row => !row.querySelector(".empty-row")
    );
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

    function applyFilterAndDisclosure() {
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
            applyFilterAndDisclosure();
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            applyFilterAndDisclosure();
        });
    }

    applyFilterAndDisclosure();
});
</script>

</body>
</html>