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

$wasteCategories = [
    "Expired / Past Shelf Life",
    "Damaged / Spilled",
    "Burnt / Baking Error",
    "Contaminated / Pest",
    "Other"
];

// Process inventory operations
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (
            !isset($_POST["csrf_token"]) ||
            !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
        ) {
            throw new Exception("Invalid request. Please refresh the page.");
        }

        $ingredientId = $_POST["ingredient_id"] ?? "";
        $action = $_POST["action"] ?? "";
        $quantity = $_POST["quantity"] ?? "";
        $wasteCategory = trim($_POST["waste_category"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if (
            !ctype_digit((string)$ingredientId) ||
            (int)$ingredientId < 1
        ) {
            throw new Exception("Please select a valid ingredient.");
        }

        if (
            !is_numeric($quantity) ||
            !is_finite((float)$quantity) ||
            (float)$quantity < 0
        ) {
            throw new Exception("Please enter a valid quantity.");
        }

        if (!in_array(
            $action,
            ["usage", "wastage", "adjustment"],
            true
        )) {
            throw new Exception("Invalid inventory operation.");
        }

        if ($action === "wastage") {
            if (!in_array($wasteCategory, $wasteCategories, true)) {
                throw new Exception("Please select a valid waste or spoilage reason.");
            }
        } else {
            $wasteCategory = null;
        }

        $quantity = round((float)$quantity, 3);

        if ($quantity <= 0 && $action !== "adjustment") {
            throw new Exception("Quantity must be greater than zero.");
        }

        // Start database transaction
        $pdo->beginTransaction();

        // Lock ingredient record
        $stmt = $pdo->prepare(
            "SELECT ingredient_id,
                    ingredient_name,
                    current_stock,
                    average_cost_per_unit
             FROM ingredients
             WHERE ingredient_id = ?
             AND is_active = 1
             FOR UPDATE"
        );
        $stmt->execute([$ingredientId]);
        $ingredient = $stmt->fetch();

        if (!$ingredient) {
            throw new Exception("Ingredient is inactive or unavailable.");
        }

        $oldStock = (float)$ingredient["current_stock"];
        $averageCost = (float)$ingredient["average_cost_per_unit"];
        $estimatedLoss = 0.00;

        // Determine the stock change
        if ($action === "usage") {
            if ($quantity > $oldStock) {
                throw new Exception("Insufficient stock for this usage.");
            }
            $quantityChange = -$quantity;
        } elseif ($action === "wastage") {
            if ($quantity > $oldStock) {
                throw new Exception("Wastage cannot exceed available stock.");
            }
            $quantityChange = -$quantity;
            $estimatedLoss = round($quantity * $averageCost, 2);
        } else {
            // Adjustment means the entered quantity is the
            // actual physical stock count.
            $newPhysicalStock = $quantity;
            $quantityChange = round(
                $newPhysicalStock - $oldStock,
                3
            );

            if ($quantityChange == 0) {
                throw new Exception("Physical stock matches recorded stock. No adjustment is needed.");
            }
        }

        $newStock = round(
            $oldStock + $quantityChange,
            3
        );

        if ($newStock < 0) {
            throw new Exception("Stock cannot be negative.");
        }

        // Update ingredient stock
        $stmt = $pdo->prepare(
            "UPDATE ingredients
             SET current_stock = ?
             WHERE ingredient_id = ?"
        );
        $stmt->execute([
            $newStock,
            $ingredientId
        ]);

        // Synchronize low-stock notifications
        syncLowStockNotification($pdo, (int)$ingredientId);

        // Record inventory transaction
        $stmt = $pdo->prepare(
            "INSERT INTO inventory_transactions
             (
                ingredient_id,
                transaction_type,
                quantity_change,
                balance_after,
                unit_cost,
                reference_id,
                waste_category,
                estimated_loss,
                notes
             )
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)"
        );
        $stmt->execute([
            $ingredientId,
            $action,
            $quantityChange,
            $newStock,
            $averageCost,
            $wasteCategory,
            $estimatedLoss,
            $notes ?: null
        ]);

        // Commit changes
        $pdo->commit();

        $message = "Inventory updated successfully for " .
                   $ingredient["ingredient_name"] . ".";
        if ($action === "wastage") {
            $message .= " Spoilage logged (" . $wasteCategory . ", Est. Loss: TZS " .
                        number_format($estimatedLoss, 2) . ").";
        }
        $messageType = "success";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            $message = "A database error occurred. Please try again.";
        } else {
            $message = $e->getMessage();
        }
        $messageType = "error";
    }
}

// Retrieve active ingredients
$ingredients = $pdo->query(
    "SELECT ingredient_id,
            ingredient_name,
            base_unit,
            current_stock
     FROM ingredients
     WHERE is_active = 1
     ORDER BY ingredient_name"
)->fetchAll();

// Retrieve recent inventory transactions
$stmt = $pdo->query(
    "SELECT
        t.transaction_id,
        t.transaction_type,
        t.quantity_change,
        t.balance_after,
        t.unit_cost,
        t.waste_category,
        t.estimated_loss,
        t.notes,
        t.transaction_date,
        i.ingredient_name,
        i.base_unit
     FROM inventory_transactions t
     INNER JOIN ingredients i
        ON t.ingredient_id = i.ingredient_id
     ORDER BY t.transaction_date DESC, t.transaction_id DESC
     LIMIT 30"
);
$transactions = $stmt->fetchAll();

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | Golden Hour Bakehouse</title>
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
                    <li><a href="inventory.php" class="active">Inventory</a></li>
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
                <h1>Inventory Management</h1>
                <p class="topbar-subtitle">Record ingredient usage, waste/spoilage and stock adjustments.</p>
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

        <!-- RECORD INVENTORY ACTIVITY CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Record Inventory Activity</h2>
                <span class="card-tag tag-gold">Stock & Spoilage Ledger</span>
            </div>

            <?php if (count($ingredients) > 0): ?>
                <form method="POST" class="ingredient-form dark-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                    >

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="ingredientSelect">Ingredient <span class="req-star">*</span></label>
                            <select name="ingredient_id" id="ingredientSelect" required>
                                <option value="">Select Ingredient</option>
                                <?php foreach ($ingredients as $ingredient): ?>
                                    <option
                                        value="<?php echo (int)$ingredient["ingredient_id"]; ?>"
                                        data-stock="<?php echo htmlspecialchars($ingredient["current_stock"]); ?>"
                                        data-unit="<?php echo htmlspecialchars($ingredient["base_unit"]); ?>"
                                    >
                                        <?php echo htmlspecialchars($ingredient["ingredient_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p id="stockInformation" class="form-hint dark-hint">
                                Select an ingredient to view its current stock.
                            </p>
                        </div>

                        <div class="form-field">
                            <label for="actionSelect">Inventory Operation <span class="req-star">*</span></label>
                            <select name="action" id="actionSelect" required>
                                <option value="">Select Operation</option>
                                <option value="usage">Ingredient Usage</option>
                                <option value="wastage">Wastage / Spoilage</option>
                                <option value="adjustment">Stock Adjustment</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="quantityInput" id="quantityLabel">Quantity <span class="req-star">*</span></label>
                            <input
                                type="number"
                                name="quantity"
                                id="quantityInput"
                                min="0"
                                step="0.001"
                                placeholder="0.000"
                                required
                            >
                            <p id="quantityHint" class="form-hint dark-hint">
                                Enter the quantity for the selected operation.
                            </p>
                        </div>

                        <div class="form-field" id="wasteCategoryField" style="display: none;">
                            <label for="wasteCategorySelect">Waste / Spoilage Reason <span class="req-star">*</span></label>
                            <select name="waste_category" id="wasteCategorySelect">
                                <option value="">Select Reason</option>
                                <?php foreach ($wasteCategories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint dark-hint">
                                Estimated TZS loss is calculated automatically from average unit cost.
                            </p>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="inventoryNotes">Notes</label>
                        <textarea
                            id="inventoryNotes"
                            name="notes"
                            placeholder="Explain the reason for this activity or spoilage..."
                        ></textarea>
                    </div>

                    <div class="form-actions-bar">
                        <button type="submit" class="primary-button dark-submit-btn">
                            Save Inventory Activity
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p class="empty-note">No active ingredients are available.</p>
            <?php endif; ?>
        </section>

        <div class="between-cards-search-bar">
            <input
                type="search"
                id="inventorySearch"
                class="dark-search-input"
                placeholder="Filter transaction history..."
                aria-label="Filter transaction history"
            >
        </div>

        <!-- INVENTORY TRANSACTION HISTORY CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Inventory Transaction History</h2>
                <span class="card-tag tag-neutral"><?php echo count($transactions); ?> Shown</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Transaction</th>
                            <th>Quantity Change</th>
                            <th>Remaining Stock</th>
                            <th>Unit Cost / Loss</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($transactions) > 0): ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                    $typeRaw = strtolower((string)$transaction["transaction_type"]);
                                    $badgeClass = "badge-neutral";
                                    if ($typeRaw === "purchase") {
                                        $badgeClass = "badge-success";
                                    } elseif ($typeRaw === "wastage" || $typeRaw === "usage") {
                                        $badgeClass = "badge-danger";
                                    } elseif ($typeRaw === "adjustment") {
                                        $badgeClass = "badge-warning";
                                    }
                                ?>
                                <tr>
                                    <td class="cell-strong">
                                        <?php echo htmlspecialchars($transaction["ingredient_name"]); ?>
                                        <?php if ($typeRaw === "wastage" && !empty($transaction["waste_category"])): ?>
                                            <br><span class="cell-muted"><?php echo htmlspecialchars($transaction["waste_category"]); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-pill <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars($typeRaw === "wastage" ? "Wastage" : ucfirst($transaction["transaction_type"])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo number_format(
                                            (float)$transaction["quantity_change"],
                                            3
                                        ); ?>
                                        <?php echo htmlspecialchars($transaction["base_unit"]); ?>
                                    </td>
                                    <td>
                                        <?php echo number_format(
                                            (float)$transaction["balance_after"],
                                            3
                                        ); ?>
                                        <?php echo htmlspecialchars($transaction["base_unit"]); ?>
                                    </td>
                                    <td>
                                        TZS <?php echo number_format(
                                            (float)$transaction["unit_cost"],
                                            4
                                        ); ?>
                                        <?php if ($typeRaw === "wastage" && (float)$transaction["estimated_loss"] > 0): ?>
                                            <br><span class="stock-low-text">Loss: TZS <?php echo number_format((float)$transaction["estimated_loss"], 2); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-muted">
                                        <?php echo htmlspecialchars($transaction["transaction_date"]); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-row">No inventory transactions recorded.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($transactions) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="toggleInventoryBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="inventoryTable"
                    >
                        Show more (<?php echo count($transactions) - 5; ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

    </main>

</div>

<script>
const ingredientSelect = document.getElementById("ingredientSelect");
const actionSelect = document.getElementById("actionSelect");
const quantityInput = document.getElementById("quantityInput");
const quantityLabel = document.getElementById("quantityLabel");
const quantityHint = document.getElementById("quantityHint");
const stockInformation = document.getElementById("stockInformation");
const wasteCategoryField = document.getElementById("wasteCategoryField");
const wasteCategorySelect = document.getElementById("wasteCategorySelect");

function updateInventoryInformation() {
    if (!ingredientSelect || !actionSelect || !stockInformation) return;

    const selectedOption = ingredientSelect.options[ingredientSelect.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
        stockInformation.textContent = "Select an ingredient to view its current stock.";
    } else {
        const stock = selectedOption.dataset.stock;
        const unit = selectedOption.dataset.unit;
        stockInformation.textContent = "Current stock: " + stock + " " + unit;
    }

    const isWastage = actionSelect.value === "wastage";
    if (wasteCategoryField && wasteCategorySelect) {
        wasteCategoryField.style.display = isWastage ? "flex" : "none";
        wasteCategorySelect.required = isWastage;
        if (!isWastage) {
            wasteCategorySelect.value = "";
        }
    }

    const unit = (selectedOption && selectedOption.dataset.unit) ? selectedOption.dataset.unit : "units";
    if (actionSelect.value === "adjustment") {
        quantityLabel.innerHTML = 'Actual Physical Stock <span class="req-star">*</span>';
        quantityHint.textContent = "Enter the total quantity physically counted. The system will calculate the difference.";
    } else if (isWastage) {
        quantityLabel.innerHTML = 'Spoiled / Wasted Quantity <span class="req-star">*</span>';
        quantityHint.textContent = "Enter the spoiled or wasted amount in " + unit + ".";
    } else {
        quantityLabel.innerHTML = 'Quantity to Remove <span class="req-star">*</span>';
        quantityHint.textContent = "Enter the amount used in " + unit + ".";
    }
}

if (ingredientSelect && actionSelect) {
    ingredientSelect.addEventListener("change", updateInventoryInformation);
    actionSelect.addEventListener("change", updateInventoryInformation);
}

document.addEventListener("DOMContentLoaded", function () {
    updateInventoryInformation();

    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("inventorySearch");
    const tableBody = document.querySelector("#inventoryTable tbody");
    const toggleBtn = document.getElementById("toggleInventoryBtn");
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