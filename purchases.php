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
$messageType = "";

// Retrieve active suppliers
$suppliers = $pdo->query(
    "SELECT supplier_id, supplier_name
     FROM suppliers
     WHERE is_active = 1
     ORDER BY supplier_name"
)->fetchAll();

// Retrieve active ingredients
$ingredients = $pdo->query(
    "SELECT ingredient_id, ingredient_name, base_unit
     FROM ingredients
     WHERE is_active = 1
     ORDER BY ingredient_name"
)->fetchAll();

$ingredientUnits = [];
foreach ($ingredients as $ingredient) {
    $ingredientUnits[$ingredient["ingredient_id"]] =
        $ingredient["base_unit"];
}

// Process purchase form
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        // Verify CSRF token
        if (
            !isset($_POST["csrf_token"]) ||
            !hash_equals(
                $_SESSION["csrf_token"],
                $_POST["csrf_token"]
            )
        ) {
            throw new Exception(
                "Invalid request. Please refresh the page."
            );
        }

        $supplierId = $_POST["supplier_id"] ?? "";
        $purchaseDate = $_POST["purchase_date"] ?? "";
        $notes = trim($_POST["notes"] ?? "");
        $ingredientIds = $_POST["ingredient_id"] ?? [];
        $quantities = $_POST["purchase_quantity"] ?? [];
        $units = $_POST["purchase_unit"] ?? [];
        $unitCosts = $_POST["unit_cost"] ?? [];

        // Validate supplier
        if (
            !ctype_digit((string)$supplierId) ||
            (int)$supplierId < 1
        ) {
            throw new Exception("Please select a valid supplier.");
        }

        // Validate purchase date
        $dateObject = DateTime::createFromFormat(
            "Y-m-d\TH:i",
            $purchaseDate
        );
        if (
            !$dateObject ||
            $dateObject->format("Y-m-d\TH:i") !== $purchaseDate
        ) {
            throw new Exception("Please enter a valid purchase date.");
        }

        // Validate purchase items
        if (
            !is_array($ingredientIds) ||
            count($ingredientIds) === 0 ||
            count($ingredientIds) !== count($quantities) ||
            count($ingredientIds) !== count($units) ||
            count($ingredientIds) !== count($unitCosts)
        ) {
            throw new Exception("Please add at least one valid purchase item.");
        }

        $allowedConversions = [
            "grams" => [
                "grams" => 1,
                "kg" => 1000
            ],
            "millilitres" => [
                "millilitres" => 1,
                "litres" => 1000
            ],
            "pieces" => [
                "pieces" => 1,
                "dozen" => 12
            ]
        ];

        $purchaseItems = [];
        $seenIngredients = [];

        foreach ($ingredientIds as $index => $ingredientId) {
            if (
                !ctype_digit((string)$ingredientId) ||
                (int)$ingredientId < 1
            ) {
                throw new Exception("Invalid ingredient selected.");
            }

            $ingredientId = (int)$ingredientId;

            // Do not allow the same ingredient twice
            if (isset($seenIngredients[$ingredientId])) {
                throw new Exception(
                    "Each ingredient should appear only once per purchase."
                );
            }
            $seenIngredients[$ingredientId] = true;

            $quantity = $quantities[$index] ?? "";
            $unit = $units[$index] ?? "";
            $unitCost = $unitCosts[$index] ?? "";

            if (
                !is_numeric($quantity) ||
                !is_finite((float)$quantity) ||
                (float)$quantity <= 0
            ) {
                throw new Exception("Purchase quantities must be greater than zero.");
            }

            if (
                !is_numeric($unitCost) ||
                !is_finite((float)$unitCost) ||
                (float)$unitCost < 0
            ) {
                throw new Exception("Please enter a valid purchase unit cost.");
            }

            $purchaseItems[] = [
                "ingredient_id" => $ingredientId,
                "quantity" => (float)$quantity,
                "unit" => $unit,
                "unit_cost" => (float)$unitCost
            ];
        }

        // Begin atomic database transaction
        $pdo->beginTransaction();

        // Confirm supplier is still active
        $stmt = $pdo->prepare(
            "SELECT supplier_id
             FROM suppliers
             WHERE supplier_id = ?
             AND is_active = 1
             FOR UPDATE"
        );
        $stmt->execute([$supplierId]);
        if (!$stmt->fetch()) {
            throw new Exception("The selected supplier is inactive or unavailable.");
        }

        // Create purchase record
        $stmt = $pdo->prepare(
            "INSERT INTO purchases
             (supplier_id, purchase_date, total_amount, notes)
             VALUES (?, ?, 0, ?)"
        );
        $stmt->execute([
            $supplierId,
            $dateObject->format("Y-m-d H:i:s"),
            $notes ?: null
        ]);
        $purchaseId = $pdo->lastInsertId();

        $purchaseTotal = 0;
        $priceAlerts = [];

        foreach ($purchaseItems as $item) {
            // Lock ingredient record during stock calculation
            $stmt = $pdo->prepare(
                "SELECT ingredient_id,
                        ingredient_name,
                        base_unit,
                        current_stock,
                        average_cost_per_unit
                 FROM ingredients
                 WHERE ingredient_id = ?
                 AND is_active = 1
                 FOR UPDATE"
            );
            $stmt->execute([$item["ingredient_id"]]);
            $ingredient = $stmt->fetch();

            if (!$ingredient) {
                throw new Exception(
                    "An ingredient is inactive or unavailable."
                );
            }

            $baseUnit = $ingredient["base_unit"];

            // Check unit compatibility
            if (
                !isset($allowedConversions[$baseUnit][$item["unit"]])
            ) {
                throw new Exception(
                    "The selected purchase unit is incompatible with " .
                    $ingredient["ingredient_name"] . "."
                );
            }

            $conversionFactor =
                $allowedConversions[$baseUnit][$item["unit"]];

            // Convert purchase quantity into base units
            $baseQuantity = round(
                $item["quantity"] * $conversionFactor,
                3
            );

            if ($baseQuantity <= 0) {
                throw new Exception("Converted purchase quantity is invalid.");
            }

            // Calculate purchase item cost
            $itemTotal = round(
                $item["quantity"] * $item["unit_cost"],
                2
            );

            // Calculate cost per base unit
            $newUnitCost = $itemTotal / $baseQuantity;

            // Get existing ingredient stock and cost
            $oldStock = (float)$ingredient["current_stock"];
            $oldAverageCost =
                (float)$ingredient["average_cost_per_unit"];

            $newStock = round(
                $oldStock + $baseQuantity,
                3
            );

            // Calculate weighted average cost
            if ($newStock > 0) {
                $newAverageCost = (
                    ($oldStock * $oldAverageCost) +
                    $itemTotal
                ) / $newStock;
            } else {
                $newAverageCost = 0;
            }
            $newAverageCost = round($newAverageCost, 4);

            // Fetch supplier's previous price per base unit for this ingredient
            $prevPriceStmt = $pdo->prepare(
                "SELECT price_per_base_unit
                 FROM supplier_price_history
                 WHERE supplier_id = ?
                   AND ingredient_id = ?
                 ORDER BY recorded_at DESC, price_history_id DESC
                 LIMIT 1"
            );
            $prevPriceStmt->execute([$supplierId, $item["ingredient_id"]]);
            $prevPriceRow = $prevPriceStmt->fetch();

            $roundedBaseUnitCost = round($newUnitCost, 4);
            $previousBaseCost = $prevPriceRow ? (float) $prevPriceRow["price_per_base_unit"] : null;
            $priceChangePct = null;

            if ($previousBaseCost !== null && $previousBaseCost > 0) {
                $priceChangePct = round(
                    (($roundedBaseUnitCost - $previousBaseCost) / $previousBaseCost) * 100,
                    2
                );
                if (abs($priceChangePct) >= 0.01) {
                    $direction = $priceChangePct > 0 ? "increased by +" : "decreased by ";
                    $priceAlerts[] = $ingredient["ingredient_name"] . " " .
                        $direction . number_format($priceChangePct, 2) . "% (TZS " .
                        number_format($previousBaseCost, 4) . " → " .
                        number_format($roundedBaseUnitCost, 4) . "/" . $baseUnit . ")";
                }
            }

            // Insert purchase item with unit_cost_in_base_unit & price_change_pct
            $stmt = $pdo->prepare(
                "INSERT INTO purchase_items
                 (
                    purchase_id,
                    ingredient_id,
                    purchase_quantity,
                    purchase_unit,
                    conversion_factor,
                    quantity_in_base_unit,
                    total_cost,
                    unit_cost_in_base_unit,
                    price_change_pct
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $purchaseId,
                $item["ingredient_id"],
                $item["quantity"],
                $item["unit"],
                $conversionFactor,
                $baseQuantity,
                $itemTotal,
                $roundedBaseUnitCost,
                $priceChangePct
            ]);

            // Record automated supplier price history entry
            $stmt = $pdo->prepare(
                "INSERT INTO supplier_price_history
                 (
                    supplier_id,
                    ingredient_id,
                    purchase_id,
                    purchase_unit,
                    unit_price_paid,
                    price_per_base_unit,
                    previous_price_per_base_unit,
                    price_change_pct,
                    recorded_at
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $supplierId,
                $item["ingredient_id"],
                $purchaseId,
                $item["unit"],
                round($item["unit_cost"], 2),
                $roundedBaseUnitCost,
                $previousBaseCost,
                $priceChangePct,
                $dateObject->format("Y-m-d H:i:s")
            ]);

            // Update ingredient stock and average cost
            $stmt = $pdo->prepare(
                "UPDATE ingredients
                 SET current_stock = ?,
                     average_cost_per_unit = ?
                 WHERE ingredient_id = ?"
            );
            $stmt->execute([
                $newStock,
                $newAverageCost,
                $item["ingredient_id"]
            ]);

            // Resolve low-stock notification if restocked above minimum_stock
            syncLowStockNotification($pdo, (int) $item["ingredient_id"]);

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
                    notes
                 )
                 VALUES (?, 'purchase', ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $item["ingredient_id"],
                $baseQuantity,
                $newStock,
                round($newUnitCost, 4),
                $purchaseId,
                "Purchase #" . $purchaseId
            ]);

            $purchaseTotal += $itemTotal;
        }

        // Update purchase total
        $stmt = $pdo->prepare(
            "UPDATE purchases
             SET total_amount = ?
             WHERE purchase_id = ?"
        );
        $stmt->execute([
            round($purchaseTotal, 2),
            $purchaseId
        ]);

        // Commit all database changes
        $pdo->commit();

        $message = "Purchase #" . $purchaseId . " recorded successfully!";
        if (!empty($priceAlerts)) {
            $message .= " Price change detected: " . implode("; ", $priceAlerts) . ".";
        }
        $messageType = "success";
    } catch (Throwable $e) {
        // Roll back if anything failed
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            $message = "A database error occurred. The purchase was not saved.";
        } else {
            $message = $e->getMessage();
        }
        $messageType = "error";
    }
}

// Retrieve purchase history
$purchaseHistory = $pdo->query(
    "SELECT
        p.purchase_id,
        p.purchase_date,
        p.total_amount,
        s.supplier_name
     FROM purchases p
     LEFT JOIN suppliers s
        ON p.supplier_id = s.supplier_id
     ORDER BY p.purchase_date DESC
     LIMIT 20"
)->fetchAll();

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchases | Golden Hour Bakehouse</title>
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
                    <li><a href="purchases.php" class="active">Purchases</a></li>
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
                <h1>Purchase Management</h1>
                <p class="topbar-subtitle">Record ingredient purchases and update inventory.</p>
            </div>

            <div class="topbar-controls">
                <span class="admin-badge">Admin: <?php echo $adminName; ?></span>
            </div>
        </header>

        <?php if ($message !== ""): ?>
            <div class="dark-alert alert-<?php echo htmlspecialchars($messageType ?: "info"); ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- RECORD NEW PURCHASE CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Record New Purchase</h2>
                <span class="card-tag tag-gold">Stock & Cost Sync</span>
            </div>

            <?php if (count($suppliers) === 0): ?>
                <p class="empty-note">Please register an active supplier before recording a purchase.</p>
            <?php elseif (count($ingredients) === 0): ?>
                <p class="empty-note">Please register an active ingredient before recording a purchase.</p>
            <?php else: ?>
                <form method="POST" id="purchaseForm" class="dark-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                    >

                    <div class="form-two-col">
                        <div class="form-field">
                            <label for="supplier_id">Supplier <span class="req-star">*</span></label>
                            <select id="supplier_id" name="supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int)$supplier["supplier_id"]; ?>">
                                        <?php echo htmlspecialchars($supplier["supplier_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="purchase_date">Purchase Date <span class="req-star">*</span></label>
                            <input
                                id="purchase_date"
                                type="datetime-local"
                                name="purchase_date"
                                value="<?php echo date("Y-m-d\TH:i"); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="purchase-items-header">
                        <h3>Purchase Items</h3>
                        <button type="button" id="addItemButton" class="dark-secondary-btn">
                            + Add Ingredient
                        </button>
                    </div>

                    <div id="purchaseItems"></div>

                    <p class="form-hint dark-hint">
                        Enter the purchase quantity and cost per selected purchase unit. For example, if you buy 2 kg of flour for TZS 5,000 per kg, enter quantity 2 and unit cost 5000.
                    </p>

                    <div class="form-field">
                        <label for="notes">Notes</label>
                        <textarea
                            id="notes"
                            name="notes"
                            placeholder="Optional purchase notes..."
                        ></textarea>
                    </div>

                    <div class="form-actions-bar">
                        <button type="submit" class="primary-button dark-submit-btn">
                            Save Purchase
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <div class="between-cards-search-bar">
            <input
                type="search"
                id="purchaseSearch"
                class="dark-search-input"
                placeholder="Filter recent purchases..."
                aria-label="Filter recent purchases"
            >
        </div>

        <!-- RECENT PURCHASES CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Recent Purchases</h2>
                <span class="card-tag tag-neutral"><?php echo count($purchaseHistory); ?> Shown</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="purchasesTable">
                    <thead>
                        <tr>
                            <th>Purchase ID</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($purchaseHistory) > 0): ?>
                            <?php foreach ($purchaseHistory as $purchase): ?>
                                <tr>
                                    <td class="cell-strong">
                                        #<?php echo (int)$purchase["purchase_id"]; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $purchase["supplier_name"] ?? "Unknown supplier"
                                        ); ?>
                                    </td>
                                    <td class="cell-muted">
                                        <?php echo htmlspecialchars($purchase["purchase_date"]); ?>
                                    </td>
                                    <td class="cell-strong">
                                        TZS <?php echo number_format(
                                            (float)$purchase["total_amount"],
                                            2
                                        ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="empty-row">No purchases recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($purchaseHistory) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="togglePurchasesBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="purchasesTable"
                    >
                        Show more (<?php echo count($purchaseHistory) - 5; ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

    </main>

</div>

<script>
const ingredientUnits = <?php
    echo json_encode(
        $ingredientUnits,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );
?>;

const ingredientOptions = `
    <option value="">Select Ingredient</option>
    <?php foreach ($ingredients as $ingredient): ?>
        <option
            value="<?php echo (int)$ingredient["ingredient_id"]; ?>"
        >
            <?php echo htmlspecialchars(
                $ingredient["ingredient_name"],
                ENT_QUOTES
            ); ?>
        </option>
    <?php endforeach; ?>
`;

const purchaseItems = document.getElementById("purchaseItems");
const addItemButton = document.getElementById("addItemButton");

function addPurchaseItem() {
    if (!purchaseItems) return;

    const row = document.createElement("div");
    row.className = "purchase-item-row dark-purchase-row";
    row.innerHTML = `
        <div class="form-field">
            <label>Ingredient</label>
            <select name="ingredient_id[]" class="ingredient-select" required>
                ${ingredientOptions}
            </select>
        </div>
        <div class="form-field">
            <label>Quantity</label>
            <input
                type="number"
                name="purchase_quantity[]"
                min="0.001"
                step="0.001"
                placeholder="0.000"
                required
            >
        </div>
        <div class="form-field">
            <label>Purchase Unit</label>
            <select name="purchase_unit[]" class="unit-select" required>
                <option value="">Select ingredient first</option>
            </select>
        </div>
        <div class="form-field">
            <label>Cost per Unit (TZS)</label>
            <input
                type="number"
                name="unit_cost[]"
                min="0"
                step="0.01"
                placeholder="0.00"
                required
            >
        </div>
        <button type="button" class="remove-item-button dark-remove-btn">
            Remove
        </button>
    `;

    const ingredientSelect = row.querySelector(".ingredient-select");
    const unitSelect = row.querySelector(".unit-select");

    ingredientSelect.addEventListener("change", function () {
        const ingredientId = this.value;
        const baseUnit = ingredientUnits[ingredientId];
        unitSelect.innerHTML = "";
        let options = [];

        if (baseUnit === "grams") {
            options = [
                ["grams", "Grams (g)"],
                ["kg", "Kilograms (kg)"]
            ];
        } else if (baseUnit === "millilitres") {
            options = [
                ["millilitres", "Millilitres (ml)"],
                ["litres", "Litres (L)"]
            ];
        } else if (baseUnit === "pieces") {
            options = [
                ["pieces", "Pieces"],
                ["dozen", "Dozen"]
            ];
        }

        unitSelect.add(new Option("Select Unit", ""));
        options.forEach(function (option) {
            unitSelect.add(new Option(option[1], option[0]));
        });
    });

    row.querySelector(".remove-item-button").addEventListener("click", function () {
        row.remove();
    });

    purchaseItems.appendChild(row);
}

if (addItemButton && purchaseItems) {
    addItemButton.addEventListener("click", addPurchaseItem);
    // Add the first purchase row automatically
    addPurchaseItem();
}

document.addEventListener("DOMContentLoaded", function () {
    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("purchaseSearch");
    const tableBody = document.querySelector("#purchasesTable tbody");
    const toggleBtn = document.getElementById("togglePurchasesBtn");
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