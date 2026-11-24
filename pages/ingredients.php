<?php
session_start();
require_once "../config/database.php";
require_once "../config/settings_helper.php";
require_once "../config/notifications_helper.php";

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

function parseNonNegativeNutrition($value, string $fieldLabel): float
{
    if ($value === null || trim((string) $value) === "") {
        return 0.0000;
    }
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
        throw new Exception($fieldLabel . " must be zero or greater.");
    }
    return round((float) $value, 4);
}

// Process form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        die("Invalid request. Please refresh the page and try again.");
    }

    $action = $_POST["action"] ?? "";

    try {
        // ADD INGREDIENT
        if ($action === "add") {
            $name = trim($_POST["ingredient_name"]);
            $description = trim($_POST["description"]);
            $unit = $_POST["base_unit"];
            $minimum_stock = $_POST["minimum_stock"];
            $allowedUnits = ["grams", "millilitres", "pieces"];

            if ($name === "") {
                throw new Exception("Ingredient name is required.");
            }
            if (!in_array($unit, $allowedUnits, true)) {
                throw new Exception("Please select a valid measurement unit.");
            }
            if (!is_numeric($minimum_stock) || $minimum_stock < 0) {
                throw new Exception("Minimum stock must be zero or greater.");
            }

            $caloriesPerUnit = parseNonNegativeNutrition($_POST["calories_per_unit"] ?? 0, "Calories per unit");
            $proteinPerUnit  = parseNonNegativeNutrition($_POST["protein_g_per_unit"] ?? 0, "Protein per unit");
            $carbsPerUnit    = parseNonNegativeNutrition($_POST["carbs_g_per_unit"] ?? 0, "Carbs per unit");
            $fatPerUnit      = parseNonNegativeNutrition($_POST["fat_g_per_unit"] ?? 0, "Fat per unit");
            $sugarPerUnit    = parseNonNegativeNutrition($_POST["sugar_g_per_unit"] ?? 0, "Sugar per unit");
            $fiberPerUnit    = parseNonNegativeNutrition($_POST["fiber_g_per_unit"] ?? 0, "Fiber per unit");
            $sodiumPerUnit   = parseNonNegativeNutrition($_POST["sodium_mg_per_unit"] ?? 0, "Sodium per unit");

            $sql = "INSERT INTO ingredients
                    (
                        ingredient_name,
                        description,
                        base_unit,
                        minimum_stock,
                        calories_per_unit,
                        protein_g_per_unit,
                        carbs_g_per_unit,
                        fat_g_per_unit,
                        sugar_g_per_unit,
                        fiber_g_per_unit,
                        sodium_mg_per_unit
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $description,
                $unit,
                $minimum_stock,
                $caloriesPerUnit,
                $proteinPerUnit,
                $carbsPerUnit,
                $fatPerUnit,
                $sugarPerUnit,
                $fiberPerUnit,
                $sodiumPerUnit
            ]);
            syncLowStockNotification($pdo, (int) $pdo->lastInsertId());
            $message = "Ingredient added successfully!";
            $messageType = "success";
        }
        // UPDATE INGREDIENT
        elseif ($action === "update") {
            $ingredient_id = $_POST["ingredient_id"];
            $name = trim($_POST["ingredient_name"]);
            $description = trim($_POST["description"]);
            $unit = $_POST["base_unit"];
            $minimum_stock = $_POST["minimum_stock"];
            $allowedUnits = ["grams", "millilitres", "pieces"];

            if (!ctype_digit((string)$ingredient_id) || (int)$ingredient_id < 1) {
                throw new Exception("Invalid ingredient selected.");
            }
            if ($name === "") {
                throw new Exception("Ingredient name is required.");
            }
            if (!in_array($unit, $allowedUnits, true)) {
                throw new Exception("Please select a valid measurement unit.");
            }
            if (!is_numeric($minimum_stock) || $minimum_stock < 0) {
                throw new Exception("Minimum stock must be zero or greater.");
            }

            $caloriesPerUnit = parseNonNegativeNutrition($_POST["calories_per_unit"] ?? 0, "Calories per unit");
            $proteinPerUnit  = parseNonNegativeNutrition($_POST["protein_g_per_unit"] ?? 0, "Protein per unit");
            $carbsPerUnit    = parseNonNegativeNutrition($_POST["carbs_g_per_unit"] ?? 0, "Carbs per unit");
            $fatPerUnit      = parseNonNegativeNutrition($_POST["fat_g_per_unit"] ?? 0, "Fat per unit");
            $sugarPerUnit    = parseNonNegativeNutrition($_POST["sugar_g_per_unit"] ?? 0, "Sugar per unit");
            $fiberPerUnit    = parseNonNegativeNutrition($_POST["fiber_g_per_unit"] ?? 0, "Fiber per unit");
            $sodiumPerUnit   = parseNonNegativeNutrition($_POST["sodium_mg_per_unit"] ?? 0, "Sodium per unit");

            $sql = "UPDATE ingredients
                    SET ingredient_name = ?,
                        description = ?,
                        base_unit = ?,
                        minimum_stock = ?,
                        calories_per_unit = ?,
                        protein_g_per_unit = ?,
                        carbs_g_per_unit = ?,
                        fat_g_per_unit = ?,
                        sugar_g_per_unit = ?,
                        fiber_g_per_unit = ?,
                        sodium_mg_per_unit = ?
                    WHERE ingredient_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $description,
                $unit,
                $minimum_stock,
                $caloriesPerUnit,
                $proteinPerUnit,
                $carbsPerUnit,
                $fatPerUnit,
                $sugarPerUnit,
                $fiberPerUnit,
                $sodiumPerUnit,
                $ingredient_id
            ]);
            syncLowStockNotification($pdo, (int) $ingredient_id);
            $message = "Ingredient updated successfully!";
            $messageType = "success";
        }
        // ACTIVATE OR DEACTIVATE INGREDIENT
        elseif ($action === "toggle_status") {
            $ingredient_id = $_POST["ingredient_id"];
            if (!ctype_digit((string)$ingredient_id) || (int)$ingredient_id < 1) {
                throw new Exception("Invalid ingredient selected.");
            }
            $sql = "UPDATE ingredients
                    SET is_active = NOT is_active
                    WHERE ingredient_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ingredient_id]);
            syncLowStockNotification($pdo, (int) $ingredient_id);
            $message = "Ingredient status updated successfully!";
            $messageType = "success";
        }
        else {
            throw new Exception("Invalid action.");
        }
    } catch (PDOException $e) {
        $messageType = "error";
        if ($e->getCode() === "23000") {
            $message = "An ingredient with this name already exists.";
        } else {
            $message = "A database error occurred. Please try again.";
        }
    } catch (Exception $e) {
        $messageType = "error";
        $message = $e->getMessage();
    }
}

// Retrieve ingredient being edited
$editIngredient = null;
if (isset($_GET["edit"]) && ctype_digit($_GET["edit"])) {
    $stmt = $pdo->prepare(
        "SELECT * FROM ingredients WHERE ingredient_id = ?"
    );
    $stmt->execute([$_GET["edit"]]);
    $editIngredient = $stmt->fetch();
}

// Retrieve all ingredients
$stmt = $pdo->query(
    "SELECT * FROM ingredients ORDER BY ingredient_name ASC"
);
$ingredients = $stmt->fetchAll();

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingredients | Golden Hour Bakehouse</title>
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
                    <li><a href="ingredients.php" class="active">Ingredients</a></li>
                    <li><a href="suppliers.php">Suppliers</a></li>
                    <li><a href="purchases.php">Purchases</a></li>
                    <li><a href="inventory.php">Inventory</a></li>
                    <li><a href="recipes.php">Recipes</a></li>
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
                <h1>Ingredient Management</h1>
                <p class="topbar-subtitle">Manage bakery ingredients, stock thresholds, and per-unit nutrition.</p>
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

        <!-- INGREDIENT FORM CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2><?php echo $editIngredient ? "Edit Ingredient" : "Add New Ingredient"; ?></h2>
                <span class="card-tag tag-gold"><?php echo $editIngredient ? "Editing #" . (int) $editIngredient["ingredient_id"] : "New Entry"; ?></span>
            </div>

            <form method="POST" class="ingredient-form dark-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="<?php echo $editIngredient ? "update" : "add"; ?>"
                >
                <?php if ($editIngredient): ?>
                    <input
                        type="hidden"
                        name="ingredient_id"
                        value="<?php echo (int) $editIngredient["ingredient_id"]; ?>"
                    >
                <?php endif; ?>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="ingredient_name">Ingredient Name <span class="req-star">*</span></label>
                        <input
                            id="ingredient_name"
                            type="text"
                            name="ingredient_name"
                            placeholder="e.g. Wheat Flour"
                            value="<?php echo htmlspecialchars($editIngredient["ingredient_name"] ?? ""); ?>"
                            required
                        >
                    </div>

                    <div class="form-field">
                        <label for="base_unit">Base Measurement Unit <span class="req-star">*</span></label>
                        <select id="base_unit" name="base_unit" required>
                            <option value="">Select Unit</option>
                            <option value="grams"
                                <?php echo (($editIngredient["base_unit"] ?? "") === "grams") ? "selected" : ""; ?>>
                                Grams (g)
                            </option>
                            <option value="millilitres"
                                <?php echo (($editIngredient["base_unit"] ?? "") === "millilitres") ? "selected" : ""; ?>>
                                Millilitres (ml)
                            </option>
                            <option value="pieces"
                                <?php echo (($editIngredient["base_unit"] ?? "") === "pieces") ? "selected" : ""; ?>>
                                Pieces
                            </option>
                        </select>
                    </div>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="minimum_stock">Minimum Stock Level <span class="req-star">*</span></label>
                        <input
                            id="minimum_stock"
                            type="number"
                            name="minimum_stock"
                            min="0"
                            step="0.001"
                            value="<?php echo htmlspecialchars($editIngredient["minimum_stock"] ?? "0"); ?>"
                            required
                        >
                        <p class="form-hint dark-hint">
                            Used to identify low stock and trigger notifications when stock drops at or below this threshold.
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            placeholder="Enter ingredient description..."
                        ><?php echo htmlspecialchars($editIngredient["description"] ?? ""); ?></textarea>
                    </div>
                </div>

                <div class="panel-header" style="margin-top: 10px;">
                    <h3>Nutrition per 1 Base Unit (g / ml / piece)</h3>
                    <span class="card-tag tag-neutral">Optional • Used by Recipe Nutrition</span>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="calories_per_unit">Calories (kcal / unit)</label>
                        <input
                            id="calories_per_unit"
                            type="number"
                            name="calories_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["calories_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>

                    <div class="form-field">
                        <label for="protein_g_per_unit">Protein (g / unit)</label>
                        <input
                            id="protein_g_per_unit"
                            type="number"
                            name="protein_g_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["protein_g_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="carbs_g_per_unit">Carbohydrates (g / unit)</label>
                        <input
                            id="carbs_g_per_unit"
                            type="number"
                            name="carbs_g_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["carbs_g_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>

                    <div class="form-field">
                        <label for="fat_g_per_unit">Fat (g / unit)</label>
                        <input
                            id="fat_g_per_unit"
                            type="number"
                            name="fat_g_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["fat_g_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="sugar_g_per_unit">Sugar (g / unit)</label>
                        <input
                            id="sugar_g_per_unit"
                            type="number"
                            name="sugar_g_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["sugar_g_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>

                    <div class="form-field">
                        <label for="fiber_g_per_unit">Fiber (g / unit)</label>
                        <input
                            id="fiber_g_per_unit"
                            type="number"
                            name="fiber_g_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["fiber_g_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="sodium_mg_per_unit">Sodium (mg / unit)</label>
                        <input
                            id="sodium_mg_per_unit"
                            type="number"
                            name="sodium_mg_per_unit"
                            min="0"
                            step="0.0001"
                            value="<?php echo htmlspecialchars($editIngredient["sodium_mg_per_unit"] ?? "0.0000"); ?>"
                        >
                    </div>
                    <div class="form-field">
                        <p class="form-hint dark-hint" style="margin-top: 28px;">
                            Example: If 100g of an ingredient has 364 kcal, enter 3.64 per gram.
                        </p>
                    </div>
                </div>

                <div class="form-actions-bar">
                    <button type="submit" class="primary-button dark-submit-btn">
                        <?php echo $editIngredient ? "Update Ingredient" : "Add Ingredient"; ?>
                    </button>
                    <?php if ($editIngredient): ?>
                        <a href="ingredients.php" class="dark-cancel-btn">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <div class="between-cards-search-bar">
            <input
                type="search"
                id="ingredientSearch"
                class="dark-search-input"
                placeholder="Filter registered ingredients..."
                aria-label="Filter registered ingredients"
            >
        </div>

        <!-- INGREDIENT TABLE CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Registered Ingredients</h2>
                <span class="card-tag tag-neutral"><?php echo count($ingredients); ?> Total</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="ingredientsTable">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Measurement Unit</th>
                            <th>Current Stock</th>
                            <th>Minimum Stock</th>
                            <th>Average Cost</th>
                            <th>Nutrition / Unit</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($ingredients) > 0): ?>
                            <?php foreach ($ingredients as $ingredient): ?>
                                <?php
                                    $isLow = (float) $ingredient["current_stock"] <= (float) $ingredient["minimum_stock"];
                                    $kcalU = (float) ($ingredient["calories_per_unit"] ?? 0);
                                    $protU = (float) ($ingredient["protein_g_per_unit"] ?? 0);
                                    $carbU = (float) ($ingredient["carbs_g_per_unit"] ?? 0);
                                    $fatU  = (float) ($ingredient["fat_g_per_unit"] ?? 0);
                                ?>
                                <tr>
                                    <td class="cell-strong">
                                        <?php echo htmlspecialchars($ingredient["ingredient_name"]); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($ingredient["base_unit"]); ?>
                                    </td>
                                    <td class="<?php echo $isLow ? "stock-low-text" : ""; ?>">
                                        <?php echo htmlspecialchars($ingredient["current_stock"]); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($ingredient["minimum_stock"]); ?>
                                    </td>
                                    <td>
                                        <?php echo number_format((float) $ingredient["average_cost_per_unit"], 4); ?>
                                    </td>
                                    <td class="cell-muted">
                                        <?php echo number_format($kcalU, 2); ?> kcal
                                        • P <?php echo number_format($protU, 2); ?>g
                                        • C <?php echo number_format($carbU, 2); ?>g
                                        • F <?php echo number_format($fatU, 2); ?>g
                                    </td>
                                    <td>
                                        <?php if ($ingredient["is_active"]): ?>
                                            <span class="status-pill badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="status-pill badge-neutral">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="table-actions-cell">
                                        <a href="ingredients.php?edit=<?php echo (int) $ingredient["ingredient_id"]; ?>" class="table-edit-link">
                                            Edit
                                        </a>
                                        <form method="POST" class="inline-form">
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle_status"
                                            >
                                            <input
                                                type="hidden"
                                                name="ingredient_id"
                                                value="<?php echo (int) $ingredient["ingredient_id"]; ?>"
                                            >
                                            <button type="submit" class="text-button dark-text-action">
                                                <?php echo $ingredient["is_active"] ? "Deactivate" : "Activate"; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-row">
                                    No ingredients registered yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($ingredients) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="toggleIngredientsBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="ingredientsTable"
                    >
                        Show more (<?php echo count($ingredients) - 5; ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

    </main>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("ingredientSearch");
    const tableBody = document.querySelector("#ingredientsTable tbody");
    const toggleBtn = document.getElementById("toggleIngredientsBtn");
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