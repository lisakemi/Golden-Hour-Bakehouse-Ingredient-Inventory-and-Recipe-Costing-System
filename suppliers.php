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
        }
        // UPDATE SUPPLIER
        elseif ($action === "update") {
            $supplier_id = $_POST["supplier_id"];
            $name = trim($_POST["supplier_name"]);
            $contact = trim($_POST["contact_person"]);
            $phone = trim($_POST["phone"]);
            $email = trim($_POST["email"]);
            $address = trim($_POST["address"]);
            if (!ctype_digit($supplier_id) || $name === "") {
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
        }
        // ACTIVATE OR DEACTIVATE SUPPLIER
        elseif ($action === "toggle_status") {
            $supplier_id = $_POST["supplier_id"];
            if (!ctype_digit($supplier_id)) {
                throw new Exception("Invalid supplier selected.");
            }
            $sql = "UPDATE suppliers
                    SET is_active = NOT is_active
                    WHERE supplier_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplier_id]);
            $message = "Supplier status updated successfully!";
        }
        else {
            throw new Exception("Invalid action.");
        }
    } catch (PDOException $e) {
        $message = "A database error occurred. Please try again.";
    } catch (Exception $e) {
        $message = $e->getMessage();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers | Golden Hour Bakehouse</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-content">
    <div class="topbar">
        <div>
            <h1>Supplier Management</h1>
            <p>Manage your bakery's ingredient suppliers.</p>
        </div>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>
    <?php if ($message !== ""): ?>
        <p class="message">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>
    <!-- SUPPLIER FORM -->
    <section class="content-section">
        <h2>
            <?php echo $editSupplier ? "Edit Supplier" : "Register New Supplier"; ?>
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
                value="<?php echo $editSupplier ? "update" : "add"; ?>"
            >
            <?php if ($editSupplier): ?>
                <input
                    type="hidden"
                    name="supplier_id"
                    value="<?php echo $editSupplier["supplier_id"]; ?>"
                >
            <?php endif; ?>
            <label>Supplier Name</label>
            <input
                type="text"
                name="supplier_name"
                placeholder="e.g. ABC Food Supplies"
                value="<?php echo htmlspecialchars($editSupplier["supplier_name"] ?? ""); ?>"
                required
            >
            <label>Contact Person</label>
            <input
                type="text"
                name="contact_person"
                placeholder="Enter contact person's name"
                value="<?php echo htmlspecialchars($editSupplier["contact_person"] ?? ""); ?>"
            >
            <label>Phone Number</label>
            <input
                type="tel"
                name="phone"
                placeholder="Enter phone number"
                value="<?php echo htmlspecialchars($editSupplier["phone"] ?? ""); ?>"
            >
            <label>Email Address</label>
            <input
                type="email"
                name="email"
                placeholder="Enter email address"
                value="<?php echo htmlspecialchars($editSupplier["email"] ?? ""); ?>"
            >
            <label>Physical Address</label>
            <textarea
                name="address"
                placeholder="Enter supplier's address..."
            ><?php echo htmlspecialchars($editSupplier["address"] ?? ""); ?></textarea>
            <button type="submit" class="primary-button">
                <?php echo $editSupplier ? "Update Supplier" : "Add Supplier"; ?>
            </button>
            <?php if ($editSupplier): ?>
                <a href="suppliers.php" class="cancel-button">
                    Cancel
                </a>
            <?php endif; ?>
        </form>
    </section>
    <!-- SUPPLIER TABLE -->
    <section class="content-section">
        <h2>Registered Suppliers</h2>
        <div class="table-container">
            <table>
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
                                <td>
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
                                        <span class="status-active">Active</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="suppliers.php?edit=<?php echo $supplier["supplier_id"]; ?>">
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
                                            value="<?php echo $supplier["supplier_id"]; ?>"
                                        >
                                        <button type="submit" class="text-button">
                                            <?php echo $supplier["is_active"] ? "Deactivate" : "Activate"; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                No suppliers registered yet.
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
