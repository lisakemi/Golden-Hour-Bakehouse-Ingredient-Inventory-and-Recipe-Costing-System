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
             VALUES (?, ?, ?, ?, ?, NULL, ?)"
        );
        $stmt->execute([
            $ingredientId,
            $action,
            $quantityChange,
            $newStock,
            $averageCost,
            $notes ?: null
        ]);
        // Commit changes
        $pdo->commit();
        $message = "Inventory updated successfully for " .
                   $ingredient["ingredient_name"] . ".";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            $message = "A database error occurred. Please try again.";
        } else {
            $message = $e->getMessage();
        }
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
        t.notes,
        t.transaction_date,
        i.ingredient_name,
        i.base_unit
     FROM inventory_transactions t
     INNER JOIN ingredients i
        ON t.ingredient_id = i.ingredient_id
     ORDER BY t.transaction_date DESC
     LIMIT 30"
);
$transactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Inventory Management</h1>
            <p>Record ingredient usage, wastage and stock adjustments.</p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <?php if ($message !== ""): ?>
        <p class="message">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>
    <section class="content-section">
        <h2>Record Inventory Activity</h2>
        <?php if (count($ingredients) > 0): ?>
            <form method="POST" class="ingredient-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >
                <label>Ingredient</label>
                <select name="ingredient_id" id="ingredientSelect" required>
                    <option value="">Select Ingredient</option>
                    <?php foreach ($ingredients as $ingredient): ?>
                        <option
                            value="<?php echo $ingredient["ingredient_id"]; ?>"
                            data-stock="<?php echo $ingredient["current_stock"]; ?>"
                            data-unit="<?php echo htmlspecialchars($ingredient["base_unit"]); ?>"
                        >
                            <?php echo htmlspecialchars($ingredient["ingredient_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p id="stockInformation" class="form-hint">
                    Select an ingredient to view its current stock.
                </p>
                <label>Inventory Operation</label>
                <select name="action" id="actionSelect" required>
                    <option value="">Select Operation</option>
                    <option value="usage">Ingredient Usage</option>
                    <option value="wastage">Wastage</option>
                    <option value="adjustment">Stock Adjustment</option>
                </select>
                <label id="quantityLabel">Quantity</label>
                <input
                    type="number"
                    name="quantity"
                    id="quantityInput"
                    min="0"
                    step="0.001"
                    required
                >
                <p id="quantityHint" class="form-hint">
                    Enter the quantity for the selected operation.
                </p>
                <label>Notes</label>
                <textarea
                    name="notes"
                    placeholder="Explain the reason for this activity..."
                ></textarea>
                <button type="submit" class="primary-button">
                    Save Inventory Activity
                </button>
            </form>
        <?php else: ?>
            <p>No active ingredients are available.</p>
        <?php endif; ?>
    </section>
    <section class="content-section">
        <h2>Inventory Transaction History</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Transaction</th>
                        <th>Quantity Change</th>
                        <th>Remaining Stock</th>
                        <th>Unit Cost</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) > 0): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($transaction["ingredient_name"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars(ucfirst($transaction["transaction_type"])); ?>
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
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction["transaction_date"]); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">No inventory transactions recorded.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
const ingredientSelect =
    document.getElementById("ingredientSelect");
const actionSelect =
    document.getElementById("actionSelect");
const quantityInput =
    document.getElementById("quantityInput");
const quantityLabel =
    document.getElementById("quantityLabel");
const quantityHint =
    document.getElementById("quantityHint");
const stockInformation =
    document.getElementById("stockInformation");
function updateInventoryInformation() {
    const selectedOption =
        ingredientSelect.options[ingredientSelect.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
        stockInformation.textContent =
            "Select an ingredient to view its current stock.";
        return;
    }
    const stock = selectedOption.dataset.stock;
    const unit = selectedOption.dataset.unit;
    stockInformation.textContent =
        "Current stock: " + stock + " " + unit;
    if (actionSelect.value === "adjustment") {
        quantityLabel.textContent =
            "Actual Physical Stock";
        quantityHint.textContent =
            "Enter the total quantity physically counted. The system will calculate the difference.";
    } else {
        quantityLabel.textContent =
            "Quantity to Remove";
        quantityHint.textContent =
            "Enter the amount used or wasted in " + unit + ".";
    }
}
ingredientSelect.addEventListener(
    "change",
    updateInventoryInformation
);
actionSelect.addEventListener(
    "change",
    updateInventoryInformation
);
</script>
</body>
</html>
