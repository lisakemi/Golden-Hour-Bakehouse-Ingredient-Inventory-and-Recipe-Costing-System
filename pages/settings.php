<?php
session_start();
require_once "../config/database.php";
require_once "../config/settings_helper.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

$settings = getSystemSettings($pdo);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "info";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (
            !isset($_POST["csrf_token"]) ||
            !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
        ) {
            throw new Exception("Invalid request. Please refresh the page and try again.");
        }

        $action = $_POST["action"] ?? "save_settings";

        if ($action === "reset_defaults") {
            saveSystemSettings($pdo, getDefaultSystemSettings());
            $message = "System settings restored to defaults.";
            $messageType = "success";
        } else {
            $bakeryName = trim($_POST["bakery_name"] ?? "Golden Hour");
            if ($bakeryName === "") {
                $bakeryName = "Golden Hour";
            }

            $themeMode = $_POST["theme_mode"] ?? "dark-gold";
            $allowedThemes = ["dark-gold", "espresso-gold", "light-bakehouse"];
            if (!in_array($themeMode, $allowedThemes, true)) {
                throw new Exception("Please select a valid theme.");
            }

            $notificationsEnabled = isset($_POST["notifications_enabled"]) ? "1" : "0";

            $spikeThreshold = $_POST["price_spike_threshold_pct"] ?? "10";
            if (!is_numeric($spikeThreshold) || (float) $spikeThreshold < 0 || (float) $spikeThreshold > 500) {
                throw new Exception("Supplier price spike threshold must be between 0% and 500%.");
            }

            $defaultMargin = $_POST["default_profit_margin"] ?? "30";
            if (!is_numeric($defaultMargin) || (float) $defaultMargin < 0 || (float) $defaultMargin >= 100) {
                throw new Exception("Default target profit margin must be between 0% and 99.99%.");
            }

            $tableRows = $_POST["table_initial_rows"] ?? "5";
            if (!ctype_digit((string) $tableRows) || (int) $tableRows < 3 || (int) $tableRows > 50) {
                throw new Exception("Default table rows must be between 3 and 50.");
            }

            $currencyCode = strtoupper(trim($_POST["currency_code"] ?? "TZS"));
            if ($currencyCode === "" || strlen($currencyCode) > 10) {
                throw new Exception("Please enter a valid currency code (e.g. TZS, USD).");
            }

            saveSystemSettings($pdo, [
                "bakery_name"               => $bakeryName,
                "theme_mode"                => $themeMode,
                "notifications_enabled"     => $notificationsEnabled,
                "price_spike_threshold_pct" => round((float) $spikeThreshold, 2),
                "default_profit_margin"     => round((float) $defaultMargin, 2),
                "table_initial_rows"        => (int) $tableRows,
                "currency_code"             => $currencyCode,
            ]);

            $message = "Settings saved successfully!";
            $messageType = "success";
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = "error";
    }
}

// Re-load fresh settings after any POST update
ensureSystemSettingsTable($pdo);
$settings = getDefaultSystemSettings();
foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $settings[$row["setting_key"]] = (string) $row["setting_value"];
}

$adminName = htmlspecialchars($_SESSION["full_name"] ?? "Administrator");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | <?php echo htmlspecialchars($settings["bakery_name"]); ?> Bakehouse</title>
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
                    <h2><?php echo htmlspecialchars($settings["bakery_name"]); ?></h2>
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
                    <li><a href="recipes.php">Recipes</a></li>
                    <li><a href="recipe_costing.php">Recipe Costing</a></li>
                    <li><a href="reports.php">Reports</a></li>
                    <li><a href="settings.php" class="active">Settings</a></li>
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
                <h1>System Settings</h1>
                <p class="topbar-subtitle">Configure workspace appearance, low-stock alerts, costing defaults, and table display preferences.</p>
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

        <!-- GENERAL & APPEARANCE SETTINGS -->
        <section class="content-section dark-panel subpage-card">
            <div class="panel-header">
                <h2>Appearance & Workspace Preferences</h2>
                <span class="card-tag tag-gold">Personalization</span>
            </div>

            <form method="POST" class="dark-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >
                <input type="hidden" name="action" value="save_settings">

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="theme_mode">Interface Theme</label>
                        <select id="theme_mode" name="theme_mode">
                            <option value="dark-gold" <?php echo $settings["theme_mode"] === "dark-gold" ? "selected" : ""; ?>>
                                Midnight Dark & Gold (Default)
                            </option>
                            <option value="espresso-gold" <?php echo $settings["theme_mode"] === "espresso-gold" ? "selected" : ""; ?>>
                                Warm Espresso & Gold
                            </option>
                            <option value="light-bakehouse" <?php echo $settings["theme_mode"] === "light-bakehouse" ? "selected" : ""; ?>>
                                Light Bakehouse Cream
                            </option>
                        </select>
                        <p class="form-hint dark-hint">
                            Applies across the dashboard and all management pages.
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="bakery_name">Bakehouse Display Name</label>
                        <input
                            id="bakery_name"
                            type="text"
                            name="bakery_name"
                            value="<?php echo htmlspecialchars($settings["bakery_name"]); ?>"
                            required
                        >
                        <p class="form-hint dark-hint">
                            Shown in the sidebar header and page titles.
                        </p>
                    </div>
                </div>

                <div class="panel-header" style="margin-top: 14px;">
                    <h3>Notifications & Alert Thresholds</h3>
                    <span class="card-tag tag-neutral">Automation</span>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="notifications_enabled" style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input
                                id="notifications_enabled"
                                type="checkbox"
                                name="notifications_enabled"
                                value="1"
                                <?php echo $settings["notifications_enabled"] === "1" ? "checked" : ""; ?>
                                style="width: auto;"
                            >
                            Enable Low-Stock Notifications
                        </label>
                        <p class="form-hint dark-hint">
                            When enabled, alerts are generated automatically whenever an ingredient drops at or below its minimum stock level.
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="price_spike_threshold_pct">Supplier Price-Spike Warning Threshold (%)</label>
                        <input
                            id="price_spike_threshold_pct"
                            type="number"
                            name="price_spike_threshold_pct"
                            min="0"
                            max="500"
                            step="0.5"
                            value="<?php echo htmlspecialchars($settings["price_spike_threshold_pct"]); ?>"
                            required
                        >
                        <p class="form-hint dark-hint">
                            Flags supplier price jumps exceeding this percentage in the price tracker.
                        </p>
                    </div>
                </div>

                <div class="panel-header" style="margin-top: 14px;">
                    <h3>Costing & Table Defaults</h3>
                    <span class="card-tag tag-neutral">Workflow</span>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="default_profit_margin">Default Target Profit Margin (%)</label>
                        <input
                            id="default_profit_margin"
                            type="number"
                            name="default_profit_margin"
                            min="0"
                            max="99.99"
                            step="0.5"
                            value="<?php echo htmlspecialchars($settings["default_profit_margin"]); ?>"
                            required
                        >
                        <p class="form-hint dark-hint">
                            Pre-fills the target margin on the Recipe Costing & Batch Calculator page.
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="table_initial_rows">Initial Visible Rows per Table</label>
                        <input
                            id="table_initial_rows"
                            type="number"
                            name="table_initial_rows"
                            min="3"
                            max="50"
                            step="1"
                            value="<?php echo htmlspecialchars($settings["table_initial_rows"]); ?>"
                            required
                        >
                        <p class="form-hint dark-hint">
                            Number of rows displayed before the "Show more" disclosure toggle appears.
                        </p>
                    </div>
                </div>

                <div class="form-two-col">
                    <div class="form-field">
                        <label for="currency_code">Currency Label</label>
                        <input
                            id="currency_code"
                            type="text"
                            name="currency_code"
                            maxlength="10"
                            value="<?php echo htmlspecialchars($settings["currency_code"]); ?>"
                            required
                        >
                    </div>
                    <div class="form-field"></div>
                </div>

                <div class="form-actions-bar">
                    <button type="submit" class="primary-button dark-submit-btn">
                        Save Settings
                    </button>
                </div>
            </form>

            <form method="POST" style="margin-top: 12px;">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >
                <input type="hidden" name="action" value="reset_defaults">
                <button type="submit" class="dark-secondary-btn">
                    Restore Default Settings
                </button>
            </form>
        </section>

    </main>

</div>

</body>
</html>