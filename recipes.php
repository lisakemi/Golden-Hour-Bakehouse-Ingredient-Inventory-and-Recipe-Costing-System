<?php
session_start();
require_once "../config/database.php";
require_once "../config/settings_helper.php";

// Protect the page
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

$settings = getSystemSettings($pdo);

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "info";

/**
 * Resolve the quantity column name on recipe_ingredients dynamically.
 */
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

$riQtyCol = resolveRecipeIngredientQtyColumn($pdo);

// Retrieve active ingredients with nutritional values
$ingredients = $pdo->query(
    "SELECT
        ingredient_id,
        ingredient_name,
        base_unit,
        calories_per_unit,
        protein_g_per_unit,
        carbs_g_per_unit,
        fat_g_per_unit,
        sugar_g_per_unit,
        fiber_g_per_unit,
        sodium_mg_per_unit
     FROM ingredients
     WHERE is_active = 1
     ORDER BY ingredient_name"
)->fetchAll();

// Process recipe form
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (
            !isset($_POST["csrf_token"]) ||
            !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
        ) {
            throw new Exception("Invalid request. Please refresh the page.");
        }

        $recipeName = trim($_POST["recipe_name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $batchYield = $_POST["batch_yield"] ?? "";
        $yieldUnit = trim($_POST["yield_unit"] ?? "");
        $servingSizeLabel = trim($_POST["serving_size_label"] ?? "1 serving");
        $ingredientIds = $_POST["ingredient_id"] ?? [];
        $quantities = $_POST["quantity_required"] ?? [];
        $costNames = $_POST["cost_name"] ?? [];
        $costAmounts = $_POST["cost_amount"] ?? [];

        if ($recipeName === "") {
            throw new Exception("Recipe name is required.");
        }

        if (
            !is_numeric($batchYield) ||
            !is_finite((float)$batchYield) ||
            (float)$batchYield <= 0
        ) {
            throw new Exception("Batch yield must be greater than zero.");
        }

        if ($yieldUnit === "") {
            throw new Exception("Yield unit is required.");
        }

        if ($servingSizeLabel === "") {
            $servingSizeLabel = "1 serving";
        }

        if (
            !is_array($ingredientIds) ||
            count($ingredientIds) === 0 ||
            count($ingredientIds) !== count($quantities)
        ) {
            throw new Exception("Please add at least one recipe ingredient.");
        }

        $recipeIngredients = [];
        $seenIngredients = [];

        foreach ($ingredientIds as $index => $ingredientId) {
            if (
                !ctype_digit((string)$ingredientId) ||
                (int)$ingredientId < 1
            ) {
                throw new Exception("Invalid ingredient selected.");
            }

            $ingredientId = (int)$ingredientId;

            if (isset($seenIngredients[$ingredientId])) {
                throw new Exception("Each ingredient should appear only once in a recipe.");
            }
            $seenIngredients[$ingredientId] = true;

            $quantity = $quantities[$index] ?? "";

            if (
                !is_numeric($quantity) ||
                !is_finite((float)$quantity) ||
                (float)$quantity <= 0
            ) {
                throw new Exception("Ingredient quantities must be greater than zero.");
            }

            $recipeIngredients[] = [
                "ingredient_id" => $ingredientId,
                "quantity" => round((float)$quantity, 3)
            ];
        }

        $otherCosts = [];
        foreach ($costNames as $index => $costName) {
            $costName = trim((string)$costName);
            $costAmount = $costAmounts[$index] ?? "";

            if ($costName === "" && ($costAmount === "" || (float)$costAmount == 0)) {
                continue;
            }

            if ($costName === "") {
                throw new Exception("Please provide a name for each additional cost.");
            }

            if (
                !is_numeric($costAmount) ||
                !is_finite((float)$costAmount) ||
                (float)$costAmount < 0
            ) {
                throw new Exception("Additional cost amounts must be zero or greater.");
            }

            $otherCosts[] = [
                "name" => $costName,
                "amount" => round((float)$costAmount, 2)
            ];
        }

        // Start transaction
        $pdo->beginTransaction();

        // Insert recipe
        $stmt = $pdo->prepare(
            "INSERT INTO recipes
             (recipe_name, description, batch_yield, yield_unit, serving_size_label)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $recipeName,
            $description ?: null,
            round((float)$batchYield, 2),
            $yieldUnit,
            $servingSizeLabel
        ]);
        $recipeId = $pdo->lastInsertId();

        // Insert recipe ingredients using resolved column name
        $stmtIngredient = $pdo->prepare(
            "INSERT INTO recipe_ingredients
             (recipe_id, ingredient_id, `{$riQtyCol}`)
             VALUES (?, ?, ?)"
        );

        foreach ($recipeIngredients as $item) {
            // Confirm ingredient is active
            $checkStmt = $pdo->prepare(
                "SELECT ingredient_id
                 FROM ingredients
                 WHERE ingredient_id = ?
                 AND is_active = 1"
            );
            $checkStmt->execute([$item["ingredient_id"]]);
            if (!$checkStmt->fetch()) {
                throw new Exception("One of the selected ingredients is inactive or unavailable.");
            }

            $stmtIngredient->execute([
                $recipeId,
                $item["ingredient_id"],
                $item["quantity"]
            ]);
        }

        // Insert additional costs
        if (count($otherCosts) > 0) {
            $stmtCost = $pdo->prepare(
                "INSERT INTO recipe_other_costs
                 (recipe_id, cost_name, cost_amount)
                 VALUES (?, ?, ?)"
            );

            foreach ($otherCosts as $cost) {
                $stmtCost->execute([
                    $recipeId,
                    $cost["name"],
                    $cost["amount"]
                ]);
            }
        }

        $pdo->commit();

        $message = "Recipe \"" . $recipeName . "\" created successfully!";
        $messageType = "success";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            if ($e->getCode() === "23000") {
                $message = "A recipe with this name already exists.";
            } else {
                $message = "A database error occurred while saving the recipe.";
            }
        } else {
            $message = $e->getMessage();
        }
        $messageType = "error";
    }
}

// Retrieve active recipes
$recipes = $pdo->query(
    "SELECT
        recipe_id,
        recipe_name,
        description,
        batch_yield,
        yield_unit,
        serving_size_label,
        is_active
     FROM recipes
     WHERE is_active = 1
     ORDER BY recipe_name"
)->fetchAll();

// Retrieve recipe ingredients with nutritional data for automated nutritional analysis
$nutritionRows = $pdo->query(
    "SELECT
        ri.recipe_id,
        ri.`{$riQtyCol}` AS quantity_required,
        i.ingredient_name,
        i.base_unit,
        i.calories_per_unit,
        i.protein_g_per_unit,
        i.carbs_g_per_unit,
        i.fat_g_per_unit,
        i.sugar_g_per_unit,
        i.fiber_g_per_unit,
        i.sodium_mg_per_unit
     FROM recipe_ingredients ri
     INNER JOIN ingredients i
        ON ri.ingredient_id = i.ingredient_id
     INNER JOIN recipes r
        ON ri.recipe_id = r.recipe_id
     WHERE r.is_active = 1
     ORDER BY i.ingredient_name ASC"
)->fetchAll();

$recipeNutritionSummary = [];
$recipeIngredientNutritionMap = [];

foreach ($recipes as $r) {
    $rid = (int) $r["recipe_id"];
    $yield = max(0.0001, (float) $r["batch_yield"]);
    $recipeNutritionSummary[$rid] = [
        "recipe_id" => $rid,
        "recipe_name" => $r["recipe_name"],
        "batch_yield" => (float) $r["batch_yield"],
        "yield_unit" => $r["yield_unit"],
        "serving_size_label" => $r["serving_size_label"] ?: "1 serving",
        "batch" => [
            "calories" => 0.0,
            "protein"  => 0.0,
            "carbs"    => 0.0,
            "fat"      => 0.0,
            "sugar"    => 0.0,
            "fiber"    => 0.0,
            "sodium"   => 0.0,
        ],
        "per_unit" => [
            "calories" => 0.0,
            "protein"  => 0.0,
            "carbs"    => 0.0,
            "fat"      => 0.0,
            "sugar"    => 0.0,
            "fiber"    => 0.0,
            "sodium"   => 0.0,
        ],
        "yield_divisor" => $yield
    ];
    $recipeIngredientNutritionMap[$rid] = [];
}

foreach ($nutritionRows as $row) {
    $rid = (int) $row["recipe_id"];
    if (!isset($recipeNutritionSummary[$rid])) {
        continue;
    }

    $qty = (float) $row["quantity_required"];
    $cal = $qty * (float) $row["calories_per_unit"];
    $pro = $qty * (float) $row["protein_g_per_unit"];
    $crb = $qty * (float) $row["carbs_g_per_unit"];
    $fat = $qty * (float) $row["fat_g_per_unit"];
    $sug = $qty * (float) $row["sugar_g_per_unit"];
    $fib = $qty * (float) $row["fiber_g_per_unit"];
    $sod = $qty * (float) $row["sodium_mg_per_unit"];

    $recipeNutritionSummary[$rid]["batch"]["calories"] += $cal;
    $recipeNutritionSummary[$rid]["batch"]["protein"]  += $pro;
    $recipeNutritionSummary[$rid]["batch"]["carbs"]    += $crb;
    $recipeNutritionSummary[$rid]["batch"]["fat"]      += $fat;
    $recipeNutritionSummary[$rid]["batch"]["sugar"]    += $sug;
    $recipeNutritionSummary[$rid]["batch"]["fiber"]    += $fib;
    $recipeNutritionSummary[$rid]["batch"]["sodium"]   += $sod;

    $recipeIngredientNutritionMap[$rid][] = [
        "ingredient_name" => $row["ingredient_name"],
        "quantity_required" => $qty,
        "base_unit" => $row["base_unit"],
        "calories" => round($cal, 2),
        "protein"  => round($pro, 2),
        "carbs"    => round($crb, 2),
        "fat"      => round($fat, 2),
        "sugar"    => round($sug, 2),
        "fiber"    => round($fib, 2),
        "sodium"   => round($sod, 1)
    ];
}

foreach ($recipeNutritionSummary as $rid => &$summary) {
    $div = $summary["yield_divisor"];
    foreach (["calories", "protein", "carbs", "fat", "sugar", "fiber", "sodium"] as $nutrient) {
        $batchVal = round($summary["batch"][$nutrient], 2);
        $summary["batch"][$nutrient] = $batchVal;
        $summary["per_unit"][$nutrient] = round($batchVal / $div, 2);
    }
}
unset($summary);

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipes & Nutrition | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php echo renderThemeOverrides($settings); ?>
    <style>
        .dark-nutrition-select {
            width: 100%;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.28);
            background-color: #161821;
            color: #f5f2eb;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .dark-nutrition-select:hover,
        .dark-nutrition-select:focus {
            border-color: #d4af37;
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.14);
        }
        .dark-nutrition-select option {
            background-color: #161821;
            color: #f5f2eb;
        }
    </style>
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
                    <li><a href="recipes.php" class="active">Recipes</a></li>
                    <li><a href="recipe_costing.php">Recipe Costing</a></li>
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
                <h1>Recipe Management & Nutrition</h1>
                <p class="topbar-subtitle">Create recipes, define batch yields and costs, and review automated nutritional breakdowns.</p>
            </div>

            <div class="topbar-controls">
                <span class="admin-badge">Admin: <?php echo $adminName; ?></span>
            </div>
        </header>

        <?php if ($message !== ""): ?>
            <div class="dark-alert alert-<?php echo htmlspecialchars($messageType); ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- CREATE RECIPE CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Create New Recipe</h2>
                <span class="card-tag tag-gold">Formula Builder</span>
            </div>

            <?php if (count($ingredients) === 0): ?>
                <p class="empty-note">Please add active ingredients before creating a recipe.</p>
            <?php else: ?>
                <form method="POST" id="recipeForm" class="dark-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                    >

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="recipe_name">Recipe Name <span class="req-star">*</span></label>
                            <input
                                id="recipe_name"
                                type="text"
                                name="recipe_name"
                                placeholder="e.g. Classic Cinnamon Rolls"
                                required
                            >
                        </div>

                        <div class="form-field">
                            <label for="batch_yield">Batch Yield <span class="req-star">*</span></label>
                            <input
                                id="batch_yield"
                                type="number"
                                name="batch_yield"
                                min="0.01"
                                step="0.01"
                                placeholder="e.g. 12"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="yield_unit">Yield Unit <span class="req-star">*</span></label>
                            <input
                                id="yield_unit"
                                type="text"
                                name="yield_unit"
                                placeholder="e.g. rolls, loaves, cakes"
                                required
                            >
                        </div>

                        <div class="form-field">
                            <label for="serving_size_label">Serving Label</label>
                            <input
                                id="serving_size_label"
                                type="text"
                                name="serving_size_label"
                                value="1 serving"
                                placeholder="e.g. 1 roll or 1 slice"
                            >
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            placeholder="Optional recipe notes or baking instructions..."
                        ></textarea>
                    </div>

                    <div class="purchase-items-header">
                        <h3>Recipe Ingredients</h3>
                        <button type="button" id="addIngredientRowBtn" class="dark-secondary-btn">
                            + Add Ingredient
                        </button>
                    </div>

                    <div id="recipeIngredientsContainer"></div>

                    <p class="form-hint dark-hint">
                        Enter ingredient quantities in their base units (grams for dry/solid weights, millilitres for liquids, or pieces for countable items).
                    </p>

                    <div class="purchase-items-header">
                        <h3>Additional Production Costs</h3>
                        <button type="button" id="addCostRowBtn" class="dark-secondary-btn">
                            + Add Cost Item
                        </button>
                    </div>

                    <div id="recipeCostsContainer"></div>

                    <p class="form-hint dark-hint">
                        Optional batch costs such as packaging, baking gas/electricity, or labor.
                    </p>

                    <div class="form-actions-bar">
                        <button type="submit" class="primary-button dark-submit-btn">
                            Save Recipe
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <div class="between-cards-search-bar">
            <input
                type="search"
                id="recipeSearch"
                class="dark-search-input"
                placeholder="Filter recipes & nutrition..."
                aria-label="Filter recipes and nutrition"
            >
        </div>

        <!-- REGISTERED RECIPES CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Registered Recipes</h2>
                <span class="card-tag tag-neutral"><?php echo count($recipes); ?> Active</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="recipesTable">
                    <thead>
                        <tr>
                            <th>Recipe Name</th>
                            <th>Batch Yield</th>
                            <th>Calories / Unit</th>
                            <th>Macros / Unit (P / C / F)</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recipes) > 0): ?>
                            <?php foreach ($recipes as $recipe): ?>
                                <?php
                                    $rid = (int) $recipe["recipe_id"];
                                    $nPerUnit = $recipeNutritionSummary[$rid]["per_unit"] ?? [
                                        "calories" => 0, "protein" => 0, "carbs" => 0, "fat" => 0
                                    ];
                                ?>
                                <tr>
                                    <td class="cell-strong">
                                        <?php echo htmlspecialchars($recipe["recipe_name"]); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($recipe["batch_yield"]); ?>
                                        <?php echo htmlspecialchars($recipe["yield_unit"]); ?>
                                    </td>
                                    <td class="cell-highlight">
                                        <?php echo number_format($nPerUnit["calories"], 1); ?> kcal
                                    </td>
                                    <td class="cell-muted">
                                        <?php echo number_format($nPerUnit["protein"], 1); ?>g /
                                        <?php echo number_format($nPerUnit["carbs"], 1); ?>g /
                                        <?php echo number_format($nPerUnit["fat"], 1); ?>g
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($recipe["description"] ?? "—"); ?>
                                    </td>
                                    <td>
                                        <?php if ($recipe["is_active"]): ?>
                                            <span class="status-pill badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="status-pill badge-neutral">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-row">No recipes created yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($recipes) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="toggleRecipesBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="recipesTable"
                    >
                        Show more (<?php echo count($recipes) - 5; ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

        <!-- AUTOMATED NUTRITIONAL ANALYSIS CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <div>
                    <h2>Automated Nutritional Analysis</h2>
                    <p class="form-hint dark-hint">Calculated automatically from ingredient base-unit nutrition and recipe quantities.</p>
                </div>
                <span class="card-tag tag-gold" id="nutritionRecipeBadge">
                    <?php echo count($recipes) > 0 ? "Per Yield Unit & Batch" : "No Recipes"; ?>
                </span>
            </div>

            <?php if (count($recipes) > 0): ?>
                <div class="form-two-col" style="margin-bottom: 16px;">
                    <div class="form-field">
                        <label for="nutritionRecipeSelect">Inspect Recipe Nutrition</label>
                        <select id="nutritionRecipeSelect" class="dark-nutrition-select">
                            <?php foreach ($recipes as $r): ?>
                                <option value="<?php echo (int) $r["recipe_id"]; ?>">
                                    <?php echo htmlspecialchars($r["recipe_name"]); ?>
                                    (<?php echo htmlspecialchars($r["batch_yield"]); ?> <?php echo htmlspecialchars($r["yield_unit"]); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <p class="form-hint dark-hint" id="nutritionBasisNote" style="margin-top: 28px;"></p>
                    </div>
                </div>

                <div class="cost-metrics-grid" id="nutritionSummaryCards"></div>

                <div class="panel-header" style="margin-top: 20px;">
                    <h3>Ingredient Nutritional Contribution (Full Batch)</h3>
                    <span class="card-tag tag-neutral" id="nutritionIngredientCount">0 Ingredients</span>
                </div>

                <div class="table-container">
                    <table class="dark-table" id="nutritionBreakdownTable">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Qty Used</th>
                                <th>Calories</th>
                                <th>Protein</th>
                                <th>Carbs</th>
                                <th>Fat</th>
                                <th>Sugar</th>
                                <th>Fiber</th>
                                <th>Sodium</th>
                            </tr>
                        </thead>
                        <tbody id="nutritionBreakdownBody"></tbody>
                    </table>
                </div>

                <div class="table-disclosure-bar" id="nutritionDisclosureBar" style="display: none;">
                    <button
                        type="button"
                        id="toggleNutritionBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="nutritionBreakdownTable"
                    >
                        Show more
                    </button>
                </div>
            <?php else: ?>
                <p class="empty-note">Create a recipe with ingredients to see automated nutritional analysis.</p>
            <?php endif; ?>
        </section>

    </main>

</div>

<script>
const ingredientOptionsHtml = `
    <option value="">Select Ingredient</option>
    <?php foreach ($ingredients as $ingredient): ?>
        <option value="<?php echo (int)$ingredient["ingredient_id"]; ?>">
            <?php echo htmlspecialchars(
                $ingredient["ingredient_name"] . " (" . $ingredient["base_unit"] . ")",
                ENT_QUOTES
            ); ?>
        </option>
    <?php endforeach; ?>
`;

const recipeNutritionSummary = <?php
    echo json_encode(
        $recipeNutritionSummary,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );
?>;

const recipeIngredientNutritionMap = <?php
    echo json_encode(
        $recipeIngredientNutritionMap,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );
?>;

const ingredientsContainer = document.getElementById("recipeIngredientsContainer");
const addIngredientRowBtn = document.getElementById("addIngredientRowBtn");
const costsContainer = document.getElementById("recipeCostsContainer");
const addCostRowBtn = document.getElementById("addCostRowBtn");

function addIngredientRow() {
    if (!ingredientsContainer) return;

    const row = document.createElement("div");
    row.className = "recipe-ingredient-row dark-recipe-row";
    row.innerHTML = `
        <div class="form-field">
            <label>Ingredient</label>
            <select name="ingredient_id[]" required>
                ${ingredientOptionsHtml}
            </select>
        </div>
        <div class="form-field">
            <label>Quantity Required</label>
            <input
                type="number"
                name="quantity_required[]"
                min="0.001"
                step="0.001"
                placeholder="0.000"
                required
            >
        </div>
        <button type="button" class="remove-item-button dark-remove-btn">
            Remove
        </button>
    `;

    row.querySelector(".remove-item-button").addEventListener("click", function () {
        row.remove();
    });

    ingredientsContainer.appendChild(row);
}

function addCostRow() {
    if (!costsContainer) return;

    const row = document.createElement("div");
    row.className = "recipe-cost-row dark-recipe-row";
    row.innerHTML = `
        <div class="form-field">
            <label>Cost Name</label>
            <input
                type="text"
                name="cost_name[]"
                placeholder="e.g. Packaging, Gas, Labor"
            >
        </div>
        <div class="form-field">
            <label>Amount (TZS)</label>
            <input
                type="number"
                name="cost_amount[]"
                min="0"
                step="0.01"
                placeholder="0.00"
            >
        </div>
        <button type="button" class="remove-item-button dark-remove-btn">
            Remove
        </button>
    `;

    row.querySelector(".remove-item-button").addEventListener("click", function () {
        row.remove();
    });

    costsContainer.appendChild(row);
}

if (addIngredientRowBtn && ingredientsContainer) {
    addIngredientRowBtn.addEventListener("click", addIngredientRow);
    addIngredientRow();
}

if (addCostRowBtn && costsContainer) {
    addCostRowBtn.addEventListener("click", addCostRow);
    addCostRow();
}

document.addEventListener("DOMContentLoaded", function () {
    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("recipeSearch");

    // Disclosure & search for Registered Recipes table
    const recipesBody = document.querySelector("#recipesTable tbody");
    const toggleRecipesBtn = document.getElementById("toggleRecipesBtn");
    let recipesExpanded = false;

    const recipeRows = recipesBody
        ? Array.from(recipesBody.querySelectorAll("tr")).filter(row => !row.querySelector(".empty-row"))
        : [];

    function updateRecipesDisclosure(matchingCount) {
        if (!toggleRecipesBtn) return;
        const term = searchInput ? searchInput.value.trim() : "";
        if (term !== "" || recipeRows.length <= INITIAL_VISIBLE_ROWS) {
            toggleRecipesBtn.style.display = "none";
            return;
        }
        toggleRecipesBtn.style.display = "inline-flex";
        toggleRecipesBtn.setAttribute("aria-expanded", recipesExpanded ? "true" : "false");
        if (recipesExpanded) {
            toggleRecipesBtn.textContent = "Show less";
        } else {
            const hiddenCount = Math.max(0, (matchingCount ?? recipeRows.length) - INITIAL_VISIBLE_ROWS);
            toggleRecipesBtn.textContent = "Show more (" + hiddenCount + " more)";
        }
    }

    function applyRecipesFilterAndDisclosure() {
        const term = searchInput ? searchInput.value.trim().toLowerCase() : "";
        let matchingIndex = 0;
        recipeRows.forEach(function (row) {
            const matches = term === "" || row.textContent.toLowerCase().includes(term);
            if (!matches) {
                row.style.display = "none";
                return;
            }
            if (term !== "" || recipesExpanded || matchingIndex < INITIAL_VISIBLE_ROWS) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
            matchingIndex++;
        });
        updateRecipesDisclosure(matchingIndex);
    }

    if (toggleRecipesBtn) {
        toggleRecipesBtn.addEventListener("click", function () {
            recipesExpanded = !recipesExpanded;
            applyRecipesFilterAndDisclosure();
        });
    }

    // Automated Nutritional Analysis panel controller
    const nutritionSelect = document.getElementById("nutritionRecipeSelect");
    const nutritionCards = document.getElementById("nutritionSummaryCards");
    const nutritionBody = document.getElementById("nutritionBreakdownBody");
    const nutritionBasisNote = document.getElementById("nutritionBasisNote");
    const nutritionIngredientCount = document.getElementById("nutritionIngredientCount");
    const nutritionDisclosureBar = document.getElementById("nutritionDisclosureBar");
    const toggleNutritionBtn = document.getElementById("toggleNutritionBtn");
    let nutritionExpanded = false;

    function escapeHtml(str) {
        const div = document.createElement("div");
        div.textContent = String(str ?? "");
        return div.innerHTML;
    }

    function applyNutritionRowDisclosure() {
        if (!nutritionBody) return;
        const term = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const rows = Array.from(nutritionBody.querySelectorAll("tr")).filter(r => !r.querySelector(".empty-row"));
        let matchingIndex = 0;

        rows.forEach(function (row) {
            const matches = term === "" || row.textContent.toLowerCase().includes(term);
            if (!matches) {
                row.style.display = "none";
                return;
            }
            if (term !== "" || nutritionExpanded || matchingIndex < INITIAL_VISIBLE_ROWS) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
            matchingIndex++;
        });

        if (nutritionDisclosureBar && toggleNutritionBtn) {
            if (term !== "" || rows.length <= INITIAL_VISIBLE_ROWS) {
                nutritionDisclosureBar.style.display = "none";
            } else {
                nutritionDisclosureBar.style.display = "flex";
                toggleNutritionBtn.setAttribute("aria-expanded", nutritionExpanded ? "true" : "false");
                if (nutritionExpanded) {
                    toggleNutritionBtn.textContent = "Show less";
                } else {
                    const hiddenCount = Math.max(0, matchingIndex - INITIAL_VISIBLE_ROWS);
                    toggleNutritionBtn.textContent = "Show more (" + hiddenCount + " more)";
                }
            }
        }
    }

    function renderNutritionPanel() {
        if (!nutritionSelect || !nutritionCards || !nutritionBody) return;
        const rid = Number(nutritionSelect.value);
        const summary = recipeNutritionSummary[rid];
        const items = recipeIngredientNutritionMap[rid] || [];

        if (!summary) {
            nutritionCards.innerHTML = "";
            nutritionBody.innerHTML = '<tr><td colspan="9" class="empty-row">No nutritional data available.</td></tr>';
            return;
        }

        const unitLabel = escapeHtml(summary.yield_unit || "unit");
        const pu = summary.per_unit;
        const ba = summary.batch;

        if (nutritionBasisNote) {
            nutritionBasisNote.textContent =
                "Batch yield: " + Number(summary.batch_yield).toFixed(2) + " " + summary.yield_unit +
                " • Batch total calories: " + Number(ba.calories).toFixed(1) + " kcal";
        }

        if (nutritionIngredientCount) {
            nutritionIngredientCount.textContent = items.length + (items.length === 1 ? " Ingredient" : " Ingredients");
        }

        nutritionCards.innerHTML = `
            <div class="cost-metric-box cost-metric-highlight">
                <span>Calories / ${unitLabel}</span>
                <strong>${Number(pu.calories).toFixed(1)} kcal</strong>
            </div>
            <div class="cost-metric-box">
                <span>Protein / ${unitLabel}</span>
                <strong>${Number(pu.protein).toFixed(2)} g</strong>
            </div>
            <div class="cost-metric-box">
                <span>Carbs / ${unitLabel}</span>
                <strong>${Number(pu.carbs).toFixed(2)} g</strong>
            </div>
            <div class="cost-metric-box">
                <span>Fat / ${unitLabel}</span>
                <strong>${Number(pu.fat).toFixed(2)} g</strong>
            </div>
            <div class="cost-metric-box">
                <span>Sugar / ${unitLabel}</span>
                <strong>${Number(pu.sugar).toFixed(2)} g</strong>
            </div>
            <div class="cost-metric-box">
                <span>Fiber & Sodium / ${unitLabel}</span>
                <strong>${Number(pu.fiber).toFixed(2)}g • ${Number(pu.sodium).toFixed(1)}mg</strong>
            </div>
        `;

        nutritionBody.innerHTML = "";
        if (items.length === 0) {
            nutritionBody.innerHTML = '<tr><td colspan="9" class="empty-row">No ingredients assigned to this recipe yet.</td></tr>';
        } else {
            items.forEach(function (item) {
                const tr = document.createElement("tr");
                tr.innerHTML =
                    '<td class="cell-strong">' + escapeHtml(item.ingredient_name) + '</td>' +
                    '<td class="cell-muted">' + Number(item.quantity_required).toFixed(3) + ' ' + escapeHtml(item.base_unit) + '</td>' +
                    '<td class="cell-highlight">' + Number(item.calories).toFixed(1) + ' kcal</td>' +
                    '<td>' + Number(item.protein).toFixed(2) + ' g</td>' +
                    '<td>' + Number(item.carbs).toFixed(2) + ' g</td>' +
                    '<td>' + Number(item.fat).toFixed(2) + ' g</td>' +
                    '<td>' + Number(item.sugar).toFixed(2) + ' g</td>' +
                    '<td>' + Number(item.fiber).toFixed(2) + ' g</td>' +
                    '<td class="cell-muted">' + Number(item.sodium).toFixed(1) + ' mg</td>';
                nutritionBody.appendChild(tr);
            });
        }

        nutritionExpanded = false;
        applyNutritionRowDisclosure();
    }

    if (nutritionSelect) {
        nutritionSelect.addEventListener("change", renderNutritionPanel);
    }

    if (toggleNutritionBtn) {
        toggleNutritionBtn.addEventListener("click", function () {
            nutritionExpanded = !nutritionExpanded;
            applyNutritionRowDisclosure();
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            applyRecipesFilterAndDisclosure();
            applyNutritionRowDisclosure();
        });
    }

    applyRecipesFilterAndDisclosure();
    renderNutritionPanel();
});
</script>

</body>
</html>