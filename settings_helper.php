<?php

function getDefaultSystemSettings(): array
{
    return [
        "bakery_name"               => "Golden Hour",
        "theme_mode"                => "dark-gold",
        "notifications_enabled"     => "1",
        "price_spike_threshold_pct" => "10",
        "default_profit_margin"     => "30",
        "table_initial_rows"        => "5",
        "currency_code"             => "TZS",
    ];
}

function ensureSystemSettingsTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $defaults = getDefaultSystemSettings();
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)"
    );
    foreach ($defaults as $key => $val) {
        $stmt->execute([$key, $val]);
    }

    $checked = true;
}

function getSystemSettings(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    ensureSystemSettingsTable($pdo);
    $settings = getDefaultSystemSettings();

    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $settings[$row["setting_key"]] = (string) $row["setting_value"];
    }

    $cached = $settings;
    return $cached;
}

function saveSystemSettings(PDO $pdo, array $updates): void
{
    ensureSystemSettingsTable($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ($updates as $key => $value) {
        $stmt->execute([$key, (string) $value]);
    }
}

function renderThemeOverrides(array $settings): string
{
    $theme = $settings["theme_mode"] ?? "dark-gold";

    $css = "
    <style>
        .between-cards-search-bar {
            width: 100%;
            margin: 18px 0;
            display: block;
            box-sizing: border-box;
        }
        .between-cards-search-bar .dark-search-input {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            display: block;
            box-sizing: border-box;
        }
    ";

    if ($theme === "espresso-gold") {
        $css .= "
        body.dark-dashboard-body,
        .dark-dashboard,
        .dark-main {
            background-color: #120e0b !important;
            color: #f5efe6 !important;
        }
        .dark-sidebar {
            background-color: #19130e !important;
            border-right: 1px solid rgba(218, 175, 72, 0.2) !important;
        }
        .dark-topbar {
            background: transparent !important;
            background-color: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }
        .dark-topbar > div,
        .dark-topbar h1,
        .dark-topbar .topbar-subtitle {
            background: transparent !important;
            background-color: transparent !important;
        }
        .dark-panel,
        .kpi-card,
        .stat-card,
        .dark-kpi-card,
        .report-kpi-card,
        .summary-card,
        .cost-metric-box {
            background-color: #1c1611 !important;
            border: 1px solid rgba(218, 175, 72, 0.22) !important;
            color: #f5efe6 !important;
        }
        .purchase-item-row,
        .recipe-ingredient-row,
        .recipe-cost-row,
        .dark-recipe-row,
        .item-row {
            background-color: #16110d !important;
            border: 1px solid rgba(218, 175, 72, 0.18) !important;
            color: #f5efe6 !important;
        }
        .dark-table th {
            background-color: #17120e !important;
            color: #c8b8a4 !important;
        }
        .dark-table td {
            border-bottom-color: rgba(218, 175, 72, 0.14) !important;
            color: #f5efe6 !important;
        }
        .dark-table tbody tr:hover {
            background-color: rgba(218, 175, 72, 0.08) !important;
        }
        .dark-search-input,
        .dark-form input,
        .dark-form select,
        .dark-form textarea,
        .dark-nutrition-select {
            background-color: #15100c !important;
            border: 1px solid rgba(218, 175, 72, 0.28) !important;
            color: #f5efe6 !important;
        }
        .dark-secondary-btn,
        .dark-action-btn,
        .admin-badge {
            background-color: #241c15 !important;
            border: 1px solid rgba(218, 175, 72, 0.28) !important;
            color: #f5efe6 !important;
        }
        ";
    } elseif ($theme === "light-bakehouse") {
        $css .= "
        body.dark-dashboard-body,
        .dark-dashboard,
        .dark-main {
            background-color: #f4f1ea !important;
            color: #1e2229 !important;
        }
        .dark-sidebar {
            background-color: #ffffff !important;
            border-right: 1px solid #e0d9ca !important;
            color: #1e2229 !important;
        }
        .dark-topbar {
            background: transparent !important;
            background-color: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }
        .dark-topbar > div,
        .dark-topbar h1,
        .dark-topbar .topbar-subtitle {
            background: transparent !important;
            background-color: transparent !important;
        }
        .dark-sidebar .logo h2,
        .dark-topbar h1,
        .dark-panel h2,
        .dark-panel h3,
        .panel-header h2,
        .panel-header h3,
        .cell-strong,
        .cost-metric-box strong,
        .kpi-card strong,
        .kpi-value,
        .stat-value,
        .report-kpi-card strong,
        .report-kpi-card .kpi-number,
        .donut-center strong,
        .donut-total,
        .legend-item,
        .legend-label,
        .alert-item,
        .alert-name {
            color: #1e2229 !important;
        }
        .dark-sidebar .logo p,
        .topbar-subtitle,
        .dark-hint,
        .form-hint,
        .cell-muted,
        .cost-metric-box span,
        .kpi-card span,
        .kpi-label,
        .stat-label,
        .report-kpi-card span,
        .report-kpi-card .kpi-label,
        .donut-center span,
        .chart-axis,
        .axis-label {
            color: #5a616d !important;
        }
        .dark-form label,
        .form-field label,
        .purchase-items-header h3,
        .purchase-item-row label,
        .recipe-ingredient-row label,
        .recipe-cost-row label {
            color: #262b35 !important;
        }
        .dark-sidebar nav a {
            color: #3c424d !important;
        }
        .dark-sidebar nav a.active,
        .dark-sidebar nav a:hover {
            background-color: #fbf3dc !important;
            color: #8c6211 !important;
        }
        .dark-panel,
        .kpi-card,
        .stat-card,
        .dark-kpi-card,
        .report-kpi-card,
        .summary-card,
        .cost-metric-box {
            background-color: #ffffff !important;
            border: 1px solid #e0d9ca !important;
            color: #1e2229 !important;
            box-shadow: 0 2px 8px rgba(30, 34, 41, 0.04) !important;
        }
        .purchase-item-row,
        .recipe-ingredient-row,
        .recipe-cost-row,
        .dark-recipe-row,
        .item-row {
            background-color: #f8f5ee !important;
            border: 1px solid #ded7c8 !important;
            color: #1e2229 !important;
        }
        .cost-metric-highlight {
            background-color: #fdf8ea !important;
            border-color: #d4af37 !important;
        }
        .dark-table th {
            background-color: #f6f2e8 !important;
            color: #4f5663 !important;
            border-bottom: 1px solid #dfd8ca !important;
        }
        .dark-table td {
            border-bottom: 1px solid #ece6d9 !important;
            color: #1e2229 !important;
        }
        .dark-table tbody tr:hover {
            background-color: #faf6ec !important;
        }
        .cell-highlight {
            color: #94680e !important;
            font-weight: 700 !important;
        }
        .dark-search-input,
        .dark-form input,
        .dark-form select,
        .dark-form textarea,
        .dark-nutrition-select,
        .purchase-item-row input,
        .purchase-item-row select,
        .recipe-ingredient-row input,
        .recipe-ingredient-row select,
        .recipe-cost-row input,
        .recipe-cost-row select {
            background-color: #ffffff !important;
            border: 1px solid #cfc6b4 !important;
            color: #1e2229 !important;
        }
        .dark-search-input::placeholder,
        .dark-form input::placeholder,
        .dark-form textarea::placeholder {
            color: #858c98 !important;
        }
        .dark-secondary-btn,
        .dark-action-btn,
        .admin-badge,
        .card-tag.tag-neutral,
        .badge-neutral {
            background-color: #f3efe5 !important;
            border: 1px solid #d5ccb9 !important;
            color: #262b35 !important;
        }
        .card-tag.tag-gold {
            background-color: #fbf1d4 !important;
            color: #8c6211 !important;
            border: 1px solid #e4c670 !important;
        }
        .dark-alert.alert-success {
            background-color: #e8f6ee !important;
            border-color: #9ad3b3 !important;
            color: #175c36 !important;
        }
        .dark-alert.alert-error {
            background-color: #fdecec !important;
            border-color: #ebb0b0 !important;
            color: #8f1d1d !important;
        }
        .sidebar-footer {
            border-top-color: #e0d9ca !important;
        }
        ";
    }

    $css .= "</style>";
    return $css;
}