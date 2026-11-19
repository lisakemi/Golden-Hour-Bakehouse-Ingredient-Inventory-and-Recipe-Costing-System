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
$message = "";
$error = "";
$editRecipe = null;
$editIngredients = [];
$editCosts = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request. Please refresh the page and try again.");
        }
        $action = $_POST['action'] ?? '';
        // SAVE OR UPDATE A RECIPE
        if ($action === 'save') {
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            $recipeName = trim($_POST['recipe_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $instructions = trim($_POST['instructions'] ?? '');
            $batchYield = filter_var(
                $_POST['batch_yield'] ?? null,
                FILTER_VALIDATE_FLOAT
            );
            $yieldUnit = trim($_POST['yield_unit'] ?? 'pieces');
            if ($recipeName === '' || strlen($recipeName) > 150) {
                throw new Exception("Enter a recipe name (maximum 150 characters).");
            }
            if ($batchYield === false || $batchYield <= 0) {
                throw new Exception("Batch yield must be greater than zero.");
            }
            if ($yieldUnit === '' || strlen($yieldUnit) > 30) {
                throw new Exception("Enter a valid yield unit (maximum 30 characters).");
            }
            $ingredientIds = $_POST['ingredient_id'] ?? [];
            $quantities = $_POST['required_quantity'] ?? [];
            if (!is_array($ingredientIds) || !is_array($quantities)) {
                throw new Exception("Invalid ingredient information.");
            }
            if (count($ingredientIds) !== count($quantities)) {
                throw new Exception("Ingredient information is incomplete.");
            }
            $ingredientsToSave = [];
            $usedIngredientIds = [];
            foreach ($ingredientIds as $index => $ingredientId) {
                $ingredientId = (int)$ingredientId;
                $quantity = filter_var(
                    $quantities[$index] ?? null,
                    FILTER_VALIDATE_FLOAT
                );
                // Ignore a completely empty row.
                if ($ingredientId === 0 && ($quantities[$index] ?? '') === '') {
                    continue;
                }
                if ($ingredientId <= 0 || $quantity === false || $quantity <= 0) {
                    throw new Exception("Complete every ingredient row with a valid quantity.");
                }
                if (isset($usedIngredientIds[$ingredientId])) {
                    throw new Exception("An ingredient cannot be added more than once.");
                }
                $usedIngredientIds[$ingredientId] = true;
                $ingredientsToSave[] = [
                    'ingredient_id' => $ingredientId,
                    'quantity' => $quantity
                ];
            }
            if (count($ingredientsToSave) === 0) {
                throw new Exception("Add at least one ingredient to the recipe.");
            }
            // Collect additional production costs.
            $costNames = $_POST['cost_name'] ?? [];
            $costAmounts = $_POST['cost_amount'] ?? [];
            if (!is_array($costNames) || !is_array($costAmounts)) {
                throw new Exception("Invalid production cost information.");
            }
            if (count($costNames) !== count($costAmounts)) {
                throw new Exception("Production cost information is incomplete.");
            }
            $costsToSave = [];
            foreach ($costNames as $index => $costName) {
                $costName = trim($costName);
                $amountInput = trim((string)($costAmounts[$index] ?? ''));
                // Ignore a completely empty row.
                if ($costName === '' && $amountInput === '') {
                    continue;
                }
                $amount = filter_var($amountInput, FILTER_VALIDATE_FLOAT);
                if (
                    $costName === '' ||
                    strlen($costName) > 100 ||
                    $amount === false ||
                    $amount <= 0
                ) {
                    throw new Exception("Complete each additional cost with a name and an amount greater than zero.");
                }
                $costsToSave[] = [
                    'name' => $costName,
                    'amount' => $amount
                ];
            }
            $pdo->beginTransaction();
            // Confirm that every selected ingredient is active.
            $ingredientCheck = $pdo->prepare(
                "SELECT ingredient_id
                 FROM ingredients
                 WHERE ingredient_id = ? AND is_active = 1
                 FOR UPDATE"
            );
            foreach ($ingredientsToSave as $ingredient) {
                $ingredientCheck->execute([$ingredient['ingredient_id']]);
                if (!$ingredientCheck->fetch()) {
                    throw new Exception("One of the selected ingredients is unavailable or inactive.");
                }
            }
            if ($recipeId > 0) {
                // Update an existing recipe.
                $update = $pdo->prepare(
                    "UPDATE recipes
                     SET recipe_name = ?,
                         description = ?,
                         batch_yield = ?,
                         yield_unit = ?,
                         instructions = ?
                     WHERE recipe_id = ?"
                );
                $update->execute([
                    $recipeName,
                    $description,
                    $batchYield,
                    $yieldUnit,
                    $instructions,
                    $recipeId
                ]);
                if ($update->rowCount() === 0) {
                    $check = $pdo->prepare(
                        "SELECT recipe_id FROM recipes WHERE recipe_id = ?"
                    );
                    $check->execute([$recipeId]);
                    if (!$check->fetch()) {
                        throw new Exception("The recipe could not be found.");
                    }
                }
                // Replace the recipe's ingredient and cost details.
                $deleteIngredients = $pdo->prepare(
                    "DELETE FROM recipe_ingredients WHERE recipe_id = ?"
                );
                $deleteIngredients->execute([$recipeId]);
                $deleteCosts = $pdo->prepare(
                    "DELETE FROM recipe_other_costs WHERE recipe_id = ?"
                );
                $deleteCosts->execute([$recipeId]);
            } else {
                // Create a new recipe.
                $insert = $pdo->prepare(
                    "INSERT INTO recipes
                     (recipe_name, description, batch_yield, yield_unit, instructions)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $insert->execute([
                    $recipeName,
                    $description,
                    $batchYield,
                    $yieldUnit,
                    $instructions
                ]);
                $recipeId = (int)$pdo->lastInsertId();
            }
            // Save recipe ingredients.
            $insertIngredient = $pdo->prepare(
                "INSERT INTO recipe_ingredients
                 (recipe_id, ingredient_id, required_quantity)
                 VALUES (?, ?, ?)"
            );
            foreach ($ingredientsToSave as $ingredient) {
                $insertIngredient->execute([
                    $recipeId,
                    $ingredient['ingredient_id'],
                    $ingredient['quantity']
                ]);
            }
            // Save additional production costs.
            $insertCost = $pdo->prepare(
                "INSERT INTO recipe_other_costs
                 (recipe_id, cost_name, cost_amount)
                 VALUES (?, ?, ?)"
            );
            foreach ($costsToSave as $cost) {
                $insertCost->execute([
                    $recipeId,
                    $cost['name'],
                    $cost['amount']
                ]);
            }
            $pdo->commit();
            header("Location: recipes.php?saved=1");
            exit;
        }
        // ACTIVATE OR DEACTIVATE A RECIPE.
        if ($action === 'toggle') {
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            if ($recipeId <= 0) {
                throw new Exception("Invalid recipe selected.");
            }
            $toggle = $pdo->prepare(
                "UPDATE recipes
                 SET is_active = IF(is_active = 1, 0, 1)
                 WHERE recipe_id = ?"
            );
            $toggle->execute([$recipeId]);
            header("Location: recipes.php?status=updated");
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
// SUCCESS MESSAGES
if (isset($_GET['saved'])) {
    $message = "Recipe saved successfully.";
}
if (isset($_GET['status'])) {
    $message = "Recipe status updated successfully.";
}
// LOAD A RECIPE FOR EDITING
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $recipeQuery = $pdo->prepare(
        "SELECT * FROM recipes WHERE recipe_id = ?"
    );
    $recipeQuery->execute([$editId]);
    $editRecipe = $recipeQuery->fetch();
    if ($editRecipe) {
        $ingredientQuery = $pdo->prepare(
            "SELECT ingredient_id, required_quantity
             FROM recipe_ingredients
             WHERE recipe_id = ?"
        );
        $ingredientQuery->execute([$editId]);
        $editIngredients = $ingredientQuery->fetchAll();
        $costQuery = $pdo->prepare(
            "SELECT cost_name, cost_amount
             FROM recipe_other_costs
             WHERE recipe_id = ?"
        );
        $costQuery->execute([$editId]);
        $editCosts = $costQuery->fetchAll();
    } else {
        $error = "The selected recipe could not be found.";
    }
}
// LOAD ACTIVE INGREDIENTS
$ingredientOptions = $pdo->query(
    "SELECT ingredient_id, ingredient_name, base_unit
     FROM ingredients
     WHERE is_active = 1
     ORDER BY ingredient_name"
)->fetchAll();
// LOAD ALL RECIPES
$recipes = $pdo->query(
    "SELECT r.*,
        (SELECT COUNT(*)
         FROM recipe_ingredients ri
         WHERE ri.recipe_id = r.recipe_id) AS ingredient_count,
        (SELECT COUNT(*)
         FROM recipe_other_costs roc
         WHERE roc.recipe_id = r.recipe_id) AS cost_count
     FROM recipes r
     ORDER BY r.created_at DESC"
)->fetchAll();
// Prepare empty rows when creating a new recipe.
if (!$editRecipe) {
    $editIngredients = [
        ['ingredient_id' => '', 'required_quantity' => '']
    ];
    $editCosts = [
        ['cost_name' => '', 'cost_amount' => '']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipe Management | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Recipe Management</h1>
            <p>Create and manage bakery recipes and their production requirements.</p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <?php if ($message): ?>
        <div class="success-message"><?= h($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-message"><?= h($error) ?></div>
    <?php endif; ?>
    <section class="content-section">
        <h2><?= $editRecipe ? 'Edit Recipe' : 'Add New Recipe' ?></h2>
        <form method="POST" class="ingredient-form">
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="recipe_id"
                   value="<?= h($editRecipe['recipe_id'] ?? 0) ?>">
            <label>Recipe Name</label>
            <input type="text" name="recipe_name" maxlength="150"
                   value="<?= h($editRecipe['recipe_name'] ?? '') ?>"
                   required>
            <label>Description</label>
            <textarea name="description" rows="3"><?= h($editRecipe['description'] ?? '') ?></textarea>
            <div class="recipe-grid">
                <div>
                    <label>Batch Yield</label>
                    <input type="number" name="batch_yield"
                           min="0.01" step="0.01"
                           value="<?= h($editRecipe['batch_yield'] ?? '') ?>"
                           required>
                </div>
                <div>
                    <label>Yield Unit</label>
                    <input type="text" name="yield_unit" maxlength="30"
                           value="<?= h($editRecipe['yield_unit'] ?? 'pieces') ?>"
                           required>
                </div>
            </div>
            <label>Preparation Instructions</label>
            <textarea name="instructions" rows="5"><?= h($editRecipe['instructions'] ?? '') ?></textarea>
            <hr>
            <h3>Recipe Ingredients</h3>
            <p>Select an ingredient and enter the quantity needed for one batch.</p>
            <div id="ingredientRows">
                <?php foreach ($editIngredients as $item): ?>
                    <div class="recipe-row">
                        <select name="ingredient_id[]" required>
                            <option value="">Select ingredient</option>
                            <?php foreach ($ingredientOptions as $option): ?>
                                <option value="<?= h($option['ingredient_id']) ?>"
                                    <?= (string)$item['ingredient_id'] === (string)$option['ingredient_id'] ? 'selected' : '' ?>>
                                    <?= h($option['ingredient_name']) ?>
                                    (<?= h($option['base_unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="required_quantity[]"
                               min="0.001" step="0.001"
                               placeholder="Quantity"
                               value="<?= h($item['required_quantity']) ?>"
                               required>
                        <button type="button" class="remove-row"
                                onclick="removeRow(this)">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary-button"
                    onclick="addIngredientRow()">
                + Add Ingredient
            </button>
            <hr>
            <h3>Additional Production Costs</h3>
            <p>Examples: packaging, labour and electricity. These are costs for the entire batch.</p>
            <div id="costRows">
                <?php foreach ($editCosts as $cost): ?>
                    <div class="recipe-row">
                        <input type="text" name="cost_name[]"
                               placeholder="Cost name"
                               value="<?= h($cost['cost_name']) ?>">
                        <input type="number" name="cost_amount[]"
                               min="0.01" step="0.01"
                               placeholder="Amount (TZS)"
                               value="<?= h($cost['cost_amount']) ?>">
                        <button type="button" class="remove-row"
                                onclick="removeRow(this)">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary-button"
                    onclick="addCostRow()">
                + Add Other Cost
            </button>
            <br><br>
            <button type="submit" class="primary-button">
                <?= $editRecipe ? 'Update Recipe' : 'Save Recipe' ?>
            </button>
            <?php if ($editRecipe): ?>
                <a href="recipes.php" class="cancel-link">Cancel Editing</a>
            <?php endif; ?>
        </form>
    </section>
    <section class="content-section">
        <h2>Registered Recipes</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Recipe</th>
                        <th>Batch Yield</th>
                        <th>Ingredients</th>
                        <th>Other Costs</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($recipes) === 0): ?>
                    <tr>
                        <td colspan="6">No recipes have been registered yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($recipes as $recipe): ?>
                    <tr>
                        <td><?= h($recipe['recipe_name']) ?></td>
                        <td>
                            <?= h($recipe['batch_yield']) ?>
                            <?= h($recipe['yield_unit']) ?>
                        </td>
                        <td><?= h($recipe['ingredient_count']) ?></td>
                        <td><?= h($recipe['cost_count']) ?></td>
                        <td>
                            <?= $recipe['is_active'] ? 'Active' : 'Inactive' ?>
                        </td>
                        <td>
                            <a href="recipes.php?edit=<?= h($recipe['recipe_id']) ?>">
                                Edit
                            </a>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="csrf_token"
                                       value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="recipe_id"
                                       value="<?= h($recipe['recipe_id']) ?>">
                                <button type="submit" class="table-action"
                                        onclick="return confirm('Change this recipe status?')">
                                    <?= $recipe['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
const ingredientOptions = <?= json_encode(
    $ingredientOptions,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;
function addIngredientRow() {
    const container = document.getElementById('ingredientRows');
    const row = document.createElement('div');
    row.className = 'recipe-row';
    const select = document.createElement('select');
    select.name = 'ingredient_id[]';
    select.required = true;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select ingredient';
    select.appendChild(placeholder);
    ingredientOptions.forEach(item => {
        const option = document.createElement('option');
        option.value = item.ingredient_id;
        option.textContent = item.ingredient_name + ' (' + item.base_unit + ')';
        select.appendChild(option);
    });
    const quantity = document.createElement('input');
    quantity.type = 'number';
    quantity.name = 'required_quantity[]';
    quantity.min = '0.001';
    quantity.step = '0.001';
    quantity.placeholder = 'Quantity';
    quantity.required = true;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'remove-row';
    remove.textContent = 'Remove';
    remove.onclick = function () {
        removeRow(remove);
    };
    row.append(select, quantity, remove);
    container.appendChild(row);
}
function addCostRow() {
    const container = document.getElementById('costRows');
    const row = document.createElement('div');
    row.className = 'recipe-row';
    const name = document.createElement('input');
    name.type = 'text';
    name.name = 'cost_name[]';
    name.placeholder = 'Cost name';
    const amount = document.createElement('input');
    amount.type = 'number';
    amount.name = 'cost_amount[]';
    amount.min = '0.01';
    amount.step = '0.01';
    amount.placeholder = 'Amount (TZS)';
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'remove-row';
    remove.textContent = 'Remove';
    remove.onclick = function () {
        removeRow(remove);
    };
    row.append(name, amount, remove);
    container.appendChild(row);
}
function removeRow(button) {
    const row = button.closest('.recipe-row');
    const container = row.parentElement;
    if (container.children.length > 1) {
        row.remove();
    } else {
        row.querySelectorAll('input').forEach(input => input.value = '');
        row.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
    }
}
</script>
</body>
</html>
