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
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = "";
$message = "";
// PROCESS A COSTING CALCULATION
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request. Please refresh the page.");
        }
        $recipeId = filter_var(
            $_POST['recipe_id'] ?? null,
            FILTER_VALIDATE_INT
        );
        $margin = filter_var(
            $_POST['target_margin'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        if (!$recipeId || $recipeId <= 0) {
            throw new Exception("Please select a valid recipe.");
        }
        if (
            $margin === false ||
            $margin < 0 ||
            $margin >= 100
        ) {
            throw new Exception("The target profit margin must be at least 0% and less than 100%.");
        }
        $pdo->beginTransaction();
        // Get the selected active recipe.
        $recipeQuery = $pdo->prepare(
            "SELECT *
             FROM recipes
             WHERE recipe_id = ? AND is_active = 1
             FOR UPDATE"
        );
        $recipeQuery->execute([$recipeId]);
        $recipe = $recipeQuery->fetch();
        if (!$recipe) {
            throw new Exception("The selected recipe does not exist or is inactive.");
        }
        if ($recipe['batch_yield'] <= 0) {
            throw new Exception("The recipe batch yield must be greater than zero.");
        }
        // Get all recipe ingredients and their current average costs.
        // Inactive ingredients are included so they cannot be silently ignored.
        $ingredientQuery = $pdo->prepare(
            "SELECT
                i.ingredient_id,
                i.ingredient_name,
                i.base_unit,
                i.average_cost_per_unit,
                i.is_active,
                ri.required_quantity
             FROM recipe_ingredients ri
             INNER JOIN ingredients i
                ON ri.ingredient_id = i.ingredient_id
             WHERE ri.recipe_id = ?
             FOR UPDATE"
        );
        $ingredientQuery->execute([$recipeId]);
        $recipeIngredients = $ingredientQuery->fetchAll();
        if (count($recipeIngredients) === 0) {
            throw new Exception("This recipe has no ingredients. Add ingredients before calculating its cost.");
        }
        $ingredientCost = 0;
        foreach ($recipeIngredients as $ingredient) {
            if (!$ingredient['is_active']) {
                throw new Exception(
                    "The ingredient " . $ingredient['ingredient_name'] .
                    " is inactive. Reactivate or replace it before calculating the recipe cost."
                );
            }
            $unitCost = (float)$ingredient['average_cost_per_unit'];
            $quantity = (float)$ingredient['required_quantity'];
            if ($unitCost <= 0) {
                throw new Exception(
                    "The ingredient " . $ingredient['ingredient_name'] .
                    " has no recorded purchase cost. Record a purchase before calculating the recipe cost."
                );
            }
            if ($quantity <= 0) {
                throw new Exception("A recipe ingredient has an invalid quantity.");
            }
            $ingredientCost += $quantity * $unitCost;
        }
        // Get additional production costs.
        $otherCostQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(cost_amount), 0)
             FROM recipe_other_costs
             WHERE recipe_id = ?"
        );
        $otherCostQuery->execute([$recipeId]);
        $otherCost = (float)$otherCostQuery->fetchColumn();
        // Calculate production costs.
        $totalProductionCost = $ingredientCost + $otherCost;
        $batchYield = (float)$recipe['batch_yield'];
        $costPerProduct = $totalProductionCost / $batchYield;
        // Convert the percentage into a decimal.
        $marginDecimal = $margin / 100;
        // Calculate a selling price based on profit margin.
        $suggestedSellingPrice = $costPerProduct / (1 - $marginDecimal);
        // Save the calculation as a historical record.
        $historyInsert = $pdo->prepare(
            "INSERT INTO recipe_cost_history (
                recipe_id,
                ingredient_cost,
                other_cost,
                total_production_cost,
                cost_per_product,
                target_profit_margin,
                suggested_selling_price,
                batch_yield,
                yield_unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $historyInsert->execute([
            $recipeId,
            $ingredientCost,
            $otherCost,
            $totalProductionCost,
            $costPerProduct,
            $margin,
            $suggestedSellingPrice,
            $batchYield,
            $recipe['yield_unit']
        ]);
        $historyId = (int)$pdo->lastInsertId();
        $pdo->commit();
        header("Location: recipe_costing.php?calculated=" . $historyId);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
// LOAD ACTIVE RECIPES
$recipes = $pdo->query(
    "SELECT recipe_id, recipe_name, batch_yield, yield_unit
     FROM recipes
     WHERE is_active = 1
     ORDER BY recipe_name"
)->fetchAll();
// LOAD THE LATEST SAVED CALCULATION
$latestCalculation = null;
if (isset($_GET['calculated'])) {
    $historyId = (int)$_GET['calculated'];
    $latestQuery = $pdo->prepare(
        "SELECT
            h.*,
            r.recipe_name
         FROM recipe_cost_history h
         INNER JOIN recipes r
            ON h.recipe_id = r.recipe_id
         WHERE h.cost_history_id = ?"
    );
    $latestQuery->execute([$historyId]);
    $latestCalculation = $latestQuery->fetch();
}
// LOAD COSTING HISTORY
$history = $pdo->query(
    "SELECT
        h.*,
        r.recipe_name
     FROM recipe_cost_history h
     INNER JOIN recipes r
        ON h.recipe_id = r.recipe_id
     ORDER BY h.calculated_at DESC
     LIMIT 30"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipe Costing | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Recipe Costing</h1>
            <p>
                Calculate ingredient costs, production costs,
                cost per product and suggested selling prices.
            </p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <?php if ($error): ?>
        <div class="error-message"><?= h($error) ?></div>
    <?php endif; ?>
    <section class="content-section">
        <h2>Calculate Recipe Cost</h2>
        <form method="POST" class="ingredient-form">
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['csrf_token']) ?>">
            <label>Select Recipe</label>
            <select name="recipe_id" required>
                <option value="">Choose a recipe</option>
                <?php foreach ($recipes as $recipe): ?>
                    <option value="<?= h($recipe['recipe_id']) ?>">
                        <?= h($recipe['recipe_name']) ?>
                        (<?= h($recipe['batch_yield']) ?>
                        <?= h($recipe['yield_unit']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Target Profit Margin (%)</label>
            <input
                type="number"
                name="target_margin"
                min="0"
                max="99.99"
                step="0.01"
                value="30"
                required
            >
            <p class="form-hint">
                Enter a percentage between 0 and less than 100.
                For example, enter 30 for a 30% profit margin.
            </p>
            <button type="submit" class="primary-button">
                Calculate and Save Cost
            </button>
        </form>
    </section>
    <?php if ($latestCalculation): ?>
        <section class="content-section">
            <h2>Costing Result</h2>
            <h3><?= h($latestCalculation['recipe_name']) ?></h3>
            <div class="cost-result-grid">
                <div class="cost-result">
                    <span>Ingredient Cost</span>
                    <strong>
                        TZS <?= number_format(
                            $latestCalculation['ingredient_cost'], 2
                        ) ?>
                    </strong>
                </div>
                <div class="cost-result">
                    <span>Additional Production Costs</span>
                    <strong>
                        TZS <?= number_format(
                            $latestCalculation['other_cost'], 2
                        ) ?>
                    </strong>
                </div>
                <div class="cost-result">
                    <span>Total Batch Production Cost</span>
                    <strong>
                        TZS <?= number_format(
                            $latestCalculation['total_production_cost'], 2
                        ) ?>
                    </strong>
                </div>
                <div class="cost-result">
                    <span>Cost per Product</span>
                    <strong>
                        TZS <?= number_format(
                            $latestCalculation['cost_per_product'], 2
                        ) ?>
                    </strong>
                </div>
                <div class="cost-result">
                    <span>Target Profit Margin</span>
                    <strong>
                        <?= number_format(
                            $latestCalculation['target_profit_margin'], 2
                        ) ?>%
                    </strong>
                </div>
                <div class="cost-result recommended-price">
                    <span>Suggested Selling Price</span>
                    <strong>
                        TZS <?= number_format(
                            $latestCalculation['suggested_selling_price'], 2
                        ) ?>
                    </strong>
                </div>
            </div>
            <p>
                This calculation uses a batch yield of
                <strong>
                    <?= h($latestCalculation['batch_yield']) ?>
                    <?= h($latestCalculation['yield_unit']) ?>
                </strong>.
            </p>
            <p class="form-hint">
                This is an estimated selling price based on the selected
                profit margin. The actual selling price can be adjusted
                based on market conditions and business decisions.
            </p>
        </section>
    <?php endif; ?>
    <section class="content-section">
        <h2>Costing History</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Recipe</th>
                        <th>Batch Yield</th>
                        <th>Ingredient Cost</th>
                        <th>Other Cost</th>
                        <th>Total Cost</th>
                        <th>Cost per Product</th>
                        <th>Margin</th>
                        <th>Selling Price</th>
                        <th>Date Calculated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($history) === 0): ?>
                    <tr>
                        <td colspan="9">
                            No costing calculations have been saved yet.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($history as $record): ?>
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
