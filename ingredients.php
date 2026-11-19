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
            $sql = "INSERT INTO ingredients
                    (ingredient_name, description, base_unit, minimum_stock)
                    VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $description,
                $unit,
                $minimum_stock
            ]);
            $message = "Ingredient added successfully!";
        }
        // UPDATE INGREDIENT
        elseif ($action === "update") {
            $ingredient_id = $_POST["ingredient_id"];
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
            $sql = "UPDATE ingredients
                    SET ingredient_name = ?,
                        description = ?,
                        base_unit = ?,
                        minimum_stock = ?
                    WHERE ingredient_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $description,
                $unit,
                $minimum_stock,
                $ingredient_id
            ]);
            $message = "Ingredient updated successfully!";
        }
        // ACTIVATE OR DEACTIVATE INGREDIENT
        elseif ($action === "toggle_status") {
            $ingredient_id = $_POST["ingredient_id"];
            $sql = "UPDATE ingredients
                    SET is_active = NOT is_active
                    WHERE ingredient_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ingredient_id]);
            $message = "Ingredient status updated successfully!";
        }
        else {
            throw new Exception("Invalid action.");
        }
    } catch (PDOException $e) {
        if ($e->getCode() === "23000") {
            $message = "An ingredient with this name already exists.";
        } else {
            $message = "A database error occurred. Please try again.";
        }
    } catch (Exception $e) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingredients | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Ingredient Management</h1>
            <p>Manage your bakery ingredients and stock thresholds.</p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <?php if ($message !== ""): ?>
        <p class="message">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>
    <!-- INGREDIENT FORM -->
    <section class="content-section">
        <h2>
            <?php echo $editIngredient ? "Edit Ingredient" : "Add New Ingredient"; ?>
        </h2>
        <form method="POST" class="ingredient-form">
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
                    value="<?php echo $editIngredient["ingredient_id"]; ?>"
                >
            <?php endif; ?>
            <label>Ingredient Name</label>
            <input
                type="text"
                name="ingredient_name"
                placeholder="e.g. Wheat Flour"
                value="<?php echo htmlspecialchars($editIngredient["ingredient_name"] ?? ""); ?>"
                required
            >
            <label>Description</label>
            <textarea
                name="description"
                placeholder="Enter ingredient description..."
            ><?php echo htmlspecialchars($editIngredient["description"] ?? ""); ?></textarea>
            <label>Base Measurement Unit</label>
            <select name="base_unit" required>
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
            <label>Minimum Stock Level</label>
            <input
                type="number"
                name="minimum_stock"
                min="0"
                step="0.001"
                value="<?php echo htmlspecialchars($editIngredient["minimum_stock"] ?? "0"); ?>"
                required
            >
            <p class="form-hint">
                The system will use this value to identify ingredients
                that need restocking.
            </p>
            <button type="submit" class="primary-button">
                <?php echo $editIngredient ? "Update Ingredient" : "Add Ingredient"; ?>
            </button>
            <?php if ($editIngredient): ?>
                <a href="ingredients.php" class="cancel-button">
                    Cancel
                </a>
            <?php endif; ?>
        </form>
    </section>
    <!-- INGREDIENT TABLE -->
    <section class="content-section">
        <h2>Registered Ingredients</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Measurement Unit</th>
                        <th>Current Stock</th>
                        <th>Minimum Stock</th>
                        <th>Average Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($ingredients) > 0): ?>
                        <?php foreach ($ingredients as $ingredient): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($ingredient["ingredient_name"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($ingredient["base_unit"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($ingredient["current_stock"]); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($ingredient["minimum_stock"]); ?>
                                </td>
                                <td>
                                    <?php echo number_format((float)$ingredient["average_cost_per_unit"], 4); ?>
                                </td>
                                <td>
                                    <?php if ($ingredient["is_active"]): ?>
                                        <span class="status-active">Active</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="ingredients.php?edit=<?php echo $ingredient["ingredient_id"]; ?>">
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
                                            value="<?php echo $ingredient["ingredient_id"]; ?>"
                                        >
                                        <button type="submit" class="text-button">
                                            <?php echo $ingredient["is_active"] ? "Deactivate" : "Activate"; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                No ingredients registered yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
