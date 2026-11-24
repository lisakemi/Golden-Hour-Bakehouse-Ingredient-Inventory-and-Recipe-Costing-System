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

// Process form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        die("Invalid request. Please refresh the page.");
    }

    $action = $_POST["action"] ?? "";

    try {
        // ADD SUPPLIER
        if ($action === "add") {
            $name = trim($_POST["supplier_name"]);
            $contact = trim($_POST["contact_person"]);
            $phone = trim($_POST["phone"]);
            $email = trim($_POST["email"]);
            $address = trim($_POST["address"]);

            if ($name === "") {
                throw new Exception("Supplier name is required.");
            }
            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }

            $sql = "INSERT INTO suppliers
                    (supplier_name, contact_person, phone, email, address)
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $contact ?: null,
                $phone ?: null,
                $email ?: null,
                $address ?: null
            ]);
            $message = "Supplier added successfully!";
            $messageType = "success";
        }
        // UPDATE SUPPLIER
        elseif ($action === "update") {
            $supplier_id = $_POST["supplier_id"];
            $name = trim($_POST["supplier_name"]);
            $contact = trim($_POST["contact_person"]);
            $phone = trim($_POST["phone"]);
            $email = trim($_POST["email"]);
            $address = trim($_POST["address"]);

            if (!ctype_digit((string)$supplier_id) || $name === "") {
                throw new Exception("Please provide valid supplier details.");
            }
            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }

            $sql = "UPDATE suppliers
                    SET supplier_name = ?,
                        contact_person = ?,
                        phone = ?,
                        email = ?,
                        address = ?
                    WHERE supplier_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $contact ?: null,
                $phone ?: null,
                $email ?: null,
                $address ?: null,
                $supplier_id
            ]);
            $message = "Supplier updated successfully!";
            $messageType = "success";
        }
        // ACTIVATE OR DEACTIVATE SUPPLIER
        elseif ($action === "toggle_status") {
            $supplier_id = $_POST["supplier_id"];
            if (!ctype_digit((string)$supplier_id)) {
                throw new Exception("Invalid supplier selected.");
            }

            $sql = "UPDATE suppliers
                    SET is_active = NOT is_active
                    WHERE supplier_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplier_id]);
            $message = "Supplier status updated successfully!";
            $messageType = "success";
        }
        else {
            throw new Exception("Invalid action.");
        }
    } catch (PDOException $e) {
        $message = "A database error occurred. Please try again.";
        $messageType = "error";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "error";
    }
}

// Retrieve supplier being edited
$editSupplier = null;
if (isset($_GET["edit"]) && ctype_digit($_GET["edit"])) {
    $stmt = $pdo->prepare(
        "SELECT * FROM suppliers WHERE supplier_id = ?"
    );
    $stmt->execute([$_GET["edit"]]);
    $editSupplier = $stmt->fetch();
}

// Retrieve all suppliers
$stmt = $pdo->query(
    "SELECT * FROM suppliers ORDER BY supplier_name ASC"
);
$suppliers = $stmt->fetchAll();

// Retrieve supplier price tracking history
$priceLogs = $pdo->query(
    "SELECT
        sph.price_history_id,
        s.supplier_name,
        i.ingredient_name,
        i.base_unit,
        sph.purchase_unit,
        sph.unit_price_paid,
        sph.price_per_base_unit,
        sph.previous_price_per_base_unit,
        sph.price_change_pct,
        sph.recorded_at
     FROM supplier_price_history sph
     INNER JOIN suppliers s ON sph.supplier_id = s.supplier_id
     INNER JOIN ingredients i ON sph.ingredient_id = i.ingredient_id
     ORDER BY sph.recorded_at DESC, sph.price_history_id DESC
     LIMIT 30"
)->fetchAll();

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers | Golden Hour Bakehouse</title>
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
                    <li><a href="suppliers.php" class="active">Suppliers</a></li>
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
                <h1>Supplier Management</h1>
                <p class="topbar-subtitle">Manage your bakery's ingredient suppliers and track unit price trends.</p>
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

        <!-- SUPPLIER FORM CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2><?php echo $editSupplier ? "Edit Supplier" : "Register New Supplier"; ?></h2>
                <span class="card-tag tag-gold"><?php echo $editSupplier ? "Editing #" . (int) $editSupplier["supplier_id"] : "New Entry"; ?></span>
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
                    value="<?php echo $editSupplier ? "update" : "add"; ?>"
                >
                <?php if ($editSupplier): ?>
                    <input
                        type="hidden"
                        name="supplier_id"
                        value="<?php echo (int) $editSupplier["supplier_id"]; ?>"
                    >
                <?php endif; ?>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="supplier_name">Supplier Name <span class="req-star">*</span></label>
                        <input
                            id="supplier_name"
                            type="text"
                            name="supplier_name"
                            placeholder="e.g. ABC Food Supplies"
                            value="<?php echo htmlspecialchars($editSupplier["supplier_name"] ?? ""); ?>"
                            required
                        >
                    </div>

                    <div class="form-field">
                        <label for="contact_person">Contact Person</label>
                        <input
                            id="contact_person"
                            type="text"
                            name="contact_person"
                            placeholder="Enter contact person's name"
                            value="<?php echo htmlspecialchars($editSupplier["contact_person"] ?? ""); ?>"
                        >
                    </div>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="phone">Phone Number</label>
                        <input
                            id="phone"
                            type="tel"
                            name="phone"
                            placeholder="Enter phone number"
                            value="<?php echo htmlspecialchars($editSupplier["phone"] ?? ""); ?>"
                        >
                    </div>

                    <div class="form-field">
                        <label for="email">Email Address</label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            placeholder="Enter email address"
                            value="<?php echo htmlspecialchars($editSupplier["email"] ?? ""); ?>"
                        >
                    </div>
                </div>

                <div class="form-field">
                    <label for="address">Physical Address</label>
                    <textarea
                        id="address"
                        name="address"
                        placeholder="Enter supplier's address..."
                    ><?php echo htmlspecialchars($editSupplier["address"] ?? ""); ?></textarea>
                </div>

                <div class="form-actions-bar">
                    <button type="submit" class="primary-button dark-submit-btn">
                        <?php echo $editSupplier ? "Update Supplier" : "Add Supplier"; ?>
                    </button>
                    <?php if ($editSupplier): ?>
                        <a href="suppliers.php" class="dark-cancel-btn">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <div class="between-cards-search-bar">
            <input
                type="search"
                id="supplierSearch"
                class="dark-search-input"
                placeholder="Filter suppliers & price history..."
                aria-label="Filter suppliers and price history"
            >
        </div>

        <!-- SUPPLIER TABLE CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Registered Suppliers</h2>
                <span class="card-tag tag-neutral"><?php echo count($suppliers); ?> Total</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="suppliersTable">
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($suppliers) > 0): ?>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td class="cell-strong">
                                        <?php echo htmlspecialchars($supplier["supplier_name"]); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($supplier["contact_person"] ?? "—"); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($supplier["phone"] ?? "—"); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($supplier["email"] ?? "—"); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($supplier["address"] ?? "—"); ?>
                                    </td>
                                    <td>
                                        <?php if ($supplier["is_active"]): ?>
                                            <span class="status-pill badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="status-pill badge-neutral">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="table-actions-cell">
                                        <a href="suppliers.php?edit=<?php echo (int) $supplier["supplier_id"]; ?>" class="table-edit-link">
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
                                                name="supplier_id"
                                                value="<?php echo (int) $supplier["supplier_id"]; ?>"
                                            >
                                            <button type="submit" class="text-button dark-text-action">
                                                <?php echo $supplier["is_active"] ? "Deactivate" : "Activate"; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="empty-row">
                                    No suppliers registered yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($suppliers) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="toggleSuppliersBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="suppliersTable"
                    >
                        Show more (<?php echo count($suppliers) - 5; ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

        <!-- AUTOMATED SUPPLIER PRICE TRACKER CARD -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <div>
                    <h2>Automated Supplier Price Tracker</h2>
                    <p class="form-hint dark-hint">Tracks unit prices per supplier automatically whenever purchases are recorded.</p>
                </div>
                <span class="card-tag tag-gold"><?php echo count($priceLogs); ?> Records</span>
            </div>

            <div class="table-container">
                <table class="dark-table" id="supplierPricesTable">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Ingredient</th>
                            <th>Purchase Rate</th>
                            <th>Base Unit Cost</th>
                            <th>Price Change</th>
                            <th>Recorded Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($priceLogs) > 0): ?>
                            <?php foreach ($priceLogs as $log): ?>
                                <?php
                                    $pct = $log["price_change_pct"];
                                    $pctBadge = "badge-neutral";
                                    $pctLabel = "Baseline";
                                    if ($pct !== null) {
                                        $pctVal = (float) $pct;
                                        if ($pctVal > 0) {
                                            $pctBadge = "badge-danger";
                                            $pctLabel = "+" . number_format($pctVal, 2) . "%";
                                        } elseif ($pctVal < 0) {
                                            $pctBadge = "badge-success";
                                            $pctLabel = number_format($pctVal, 2) . "%";
                                        } else {
                                            $pctLabel = "0.00%";
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="cell-strong"><?php echo htmlspecialchars($log["supplier_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($log["ingredient_name"]); ?></td>
                                    <td>
                                        TZS <?php echo number_format((float) $log["unit_price_paid"], 2); ?>
                                        / <?php echo htmlspecialchars($log["purchase_unit"]); ?>
                                    </td>
                                    <td>
                                        TZS <?php echo number_format((float) $log["price_per_base_unit"], 4); ?>
                                        / <?php echo htmlspecialchars($log["base_unit"]); ?>
                                    </td>
                                    <td>
                                        <span class="status-pill <?php echo $pctBadge; ?>">
                                            <?php echo $pctLabel; ?>
                                        </span>
                                    </td>
                                    <td class="cell-muted"><?php echo htmlspecialchars($log["recorded_at"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-row">No supplier price history recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($priceLogs) > 5): ?>
                <div class="table-disclosure-bar">
                    <button
                        type="button"
                        id="toggleSupplierPricesBtn"
                        class="dark-secondary-btn disclosure-toggle-btn"
                        aria-expanded="false"
                        aria-controls="supplierPricesTable"
                    >
                        Show more (<?php echo count($priceLogs) - 5; ?> more)
                    </button>
                </div>
            <?php endif; ?>
        </section>

    </main>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const INITIAL_VISIBLE_ROWS = 5;
    const searchInput = document.getElementById("supplierSearch");

    function bindDisclosureTable(tableSelector, toggleSelector) {
        const tableBody = document.querySelector(tableSelector + " tbody");
        const toggleBtn = document.querySelector(toggleSelector);
        if (!tableBody) return null;

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

        function apply() {
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
                apply();
            });
        }

        return { apply };
    }

    const controllers = [
        bindDisclosureTable("#suppliersTable", "#toggleSuppliersBtn"),
        bindDisclosureTable("#supplierPricesTable", "#toggleSupplierPricesBtn")
    ].filter(Boolean);

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            controllers.forEach(ctrl => ctrl.apply());
        });
    }

    controllers.forEach(ctrl => ctrl.apply());
});
</script>

</body>
</html>