<?php
session_start();
require_once "../config/database.php";
// Protect the page
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}
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
            // Insert purchase item
            $stmt = $pdo->prepare(
                "INSERT INTO purchase_items
                 (
                    purchase_id,
                    ingredient_id,
                    purchase_quantity,
                    purchase_unit,
                    conversion_factor,
                    quantity_in_base_unit,
                    total_cost
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $purchaseId,
                $item["ingredient_id"],
                $item["quantity"],
                $item["unit"],
                $conversionFactor,
                $baseQuantity,
                $itemTotal
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
        $message = "Purchase #" . $purchaseId .
                   " recorded successfully!";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchases | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Purchase Management</h1>
            <p>Record ingredient purchases and update inventory.</p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <?php if ($message !== ""): ?>
        <p class="message">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>
    <section class="content-section">
        <h2>Record New Purchase</h2>
        <?php if (count($suppliers) === 0): ?>
            <p>Please register an active supplier before recording a purchase.</p>
        <?php elseif (count($ingredients) === 0): ?>
            <p>Please register an active ingredient before recording a purchase.</p>
        <?php else: ?>
            <form method="POST" id="purchaseForm">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >
                <label>Supplier</label>
                <select name="supplier_id" required>
                    <option value="">Select Supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier["supplier_id"]; ?>">
                            <?php echo htmlspecialchars($supplier["supplier_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>Purchase Date</label>
                <input
                    type="datetime-local"
                    name="purchase_date"
                    value="<?php echo date("Y-m-d\TH:i"); ?>"
                    required
                >
                <h3>Purchase Items</h3>
                <div id="purchaseItems"></div>
                <button type="button" id="addItemButton">
                    + Add Ingredient
                </button>
                <p class="form-hint">
                    Enter the purchase quantity and cost per selected
                    purchase unit. For example, if you buy 2 kg of flour
                    for TZS 5,000 per kg, enter quantity 2 and unit cost 5000.
                </p>
                <label>Notes</label>
                <textarea
                    name="notes"
                    placeholder="Optional purchase notes..."
                ></textarea>
                <button type="submit" class="primary-button">
                    Save Purchase
                </button>
            </form>
        <?php endif; ?>
    </section>
    <section class="content-section">
        <h2>Recent Purchases</h2>
        <div class="table-container">
            <table>
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
                                <td>
                                    #<?php echo $purchase["purchase_id"]; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars(
                                        $purchase["supplier_name"] ?? "Unknown supplier"
                                    ); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($purchase["purchase_date"]); ?>
                                </td>
                                <td>
                                    TZS <?php echo number_format(
                                        (float)$purchase["total_amount"],
                                        2
                                    ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">No purchases recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
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
            value="<?php echo $ingredient["ingredient_id"]; ?>"
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
    const row = document.createElement("div");
    row.className = "purchase-item-row";
    row.innerHTML = `
        <div>
            <label>Ingredient</label>
            <select name="ingredient_id[]" class="ingredient-select" required>
                ${ingredientOptions}
            </select>
        </div>
        <div>
            <label>Quantity</label>
            <input
                type="number"
                name="purchase_quantity[]"
                min="0.001"
                step="0.001"
                required
            >
        </div>
        <div>
            <label>Purchase Unit</label>
            <select name="purchase_unit[]" class="unit-select" required>
                <option value="">Select ingredient first</option>
            </select>
        </div>
        <div>
            <label>Cost per Unit (TZS)</label>
            <input
                type="number"
                name="unit_cost[]"
                min="0"
                step="0.01"
                required
            >
        </div>
        <button type="button" class="remove-item-button">
            Remove
        </button>
        <hr>
    `;
    const ingredientSelect =
        row.querySelector(".ingredient-select");
    const unitSelect =
        row.querySelector(".unit-select");
    ingredientSelect.addEventListener("change", function() {
        const ingredientId = this.value;
        const baseUnit = ingredientUnits[ingredientId];
        unitSelect.innerHTML = "";
        let options = [];
        if (baseUnit === "grams") {
            options = [
                ["grams", "Grams (g)"],
                ["kg", "Kilograms (kg)"]
            ];
        }
        else if (baseUnit === "millilitres") {
            options = [
                ["millilitres", "Millilitres (ml)"],
                ["litres", "Litres (L)"]
            ];
        }
        else if (baseUnit === "pieces") {
            options = [
                ["pieces", "Pieces"],
                ["dozen", "Dozen"]
            ];
        }
        unitSelect.add(new Option("Select Unit", ""));
        options.forEach(function(option) {
            unitSelect.add(
                new Option(option[1], option[0])
            );
        });
    });
    row.querySelector(".remove-item-button")
        .addEventListener("click", function() {
            row.remove();
        });
    purchaseItems.appendChild(row);
}
addItemButton.addEventListener("click", addPurchaseItem);
// Add the first purchase row automatically
addPurchaseItem();
</script>
</body>
</html>
