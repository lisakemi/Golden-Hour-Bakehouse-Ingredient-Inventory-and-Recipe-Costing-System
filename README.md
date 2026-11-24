# Golden Hour Bakehouse Management System

## Description

**Golden Hour Bakehouse Management System** is a web-based bakery management platform designed to streamline daily bakehouse operations. Originally focused on **core ingredient management and recipe costing**, the system has been expanded with modern operational capabilities—including automated supplier price tracking, waste and spoilage logging, low-stock notifications, batch-sizing calculators, configurable system settings, and **automated nutritional analysis as an additional feature**.

## Features

* **Core Ingredient Management & Recipe Costing**: Manage raw ingredients with base/purchase units (`g`, `ml`, `pcs`), minimum stock thresholds, and weighted average unit costs. Build multi-ingredient recipes with overhead costs (packaging, gas/electricity, labor) to compute unit production costs, target profit margins, and suggested selling prices.
* **Automated Supplier Price Tracking**: Records unit price history on every purchase, calculates price-change percentages against prior records, flags sharp supplier price spikes using a configurable threshold, and displays price trends alongside supplier management.
* **Waste and Spoilage Logging**: Records ingredient wastage and spoilage in inventory adjustments with categorized reason codes and notes, updates stock balances automatically, calculates estimated financial loss (in TZS), and surfaces totals in reports.
* **Low-Stock Notifications**: Monitors ingredient stock levels against minimum thresholds, triggers or resolves alerts automatically, supports alert acknowledgment (`Ack`) directly from the dashboard, and can be toggled on or off in system settings.
* **Batch-Sizing Calculator**: Synchronizes batch multipliers and target output yields in real time, scales ingredient quantities and production costs, checks live stock availability and shortfalls before baking, and saves scaled costing snapshots.
* **Interactive Bakery Dashboard**: Displays live KPIs (total ingredients, active recipes, suppliers, and unread low-stock alerts), a 7-day purchase overview bar chart, a recipe cost breakdown donut chart, recent inventory activities, and quick navigation actions.
* **Reports & Analytics**: Date-range reporting covering low-stock ingredients, inventory movement logs, waste and spoilage financial loss summaries, purchase history, and recipe costing records with live search filtering.
* **System Settings & Theme Customization**: Configure interface themes (*Midnight Dark & Gold*, *Warm Espresso & Gold*, and *Light Bakehouse Cream*), bakery display name, low-stock notification toggles, supplier price-spike thresholds, default profit margins, initial visible table row limits, and currency labels.
* **Automated Nutritional Analysis *(Additional Feature)***: Stores per-base-unit nutritional metadata on ingredients (`calories_per_unit`, `protein_g_per_unit`, `carbs_g_per_unit`, `fat_g_per_unit`, `sugar_g_per_unit`, `fiber_g_per_unit`, `sodium_mg_per_unit`) and automatically calculates full-batch and per-yield-unit nutritional breakdowns on recipes.

## Technologies Used

* **Frontend**: HTML5, CSS3 (Custom Dark, Espresso, and Light Theme Overrides & Responsive Grid Layouts), Vanilla JavaScript (ES6+)
* **Backend**: PHP 8+ (PDO with Prepared Statements & Transactions, CSRF Token Protection, Session Authentication)
* **Database**: MySQL / MariaDB (`InnoDB`, `utf8mb4`)
* **Local Development Environment**: Laragon / Apache / phpMyAdmin

## Data Models

* **Core Operational & Costing Entities**:
  * `users`: Stores administrator authentication credentials and profile details.
  * `ingredients`: Stores ingredient metadata, base/purchase units, `current_stock`, `minimum_stock`, `average_cost_per_unit`, active status, and per-base-unit nutritional columns.
  * `suppliers`, `purchases`, & `purchase_items`: Track vendor details, purchase dates, line-item quantities, and unit costs.
  * `recipes`, `recipe_ingredients`, `recipe_other_costs`, & `recipe_cost_history`: Model bakery formulas, dynamic ingredient quantity mappings (`quantity_in_base_unit` / `quantity_required`), additional overhead costs, serving labels, and saved batch costing snapshots.
  * `inventory_adjustments`: Logs stock movements including purchases, manual adjustments, and wastage/spoilage entries with estimated financial loss impact.
* **Analytics, Alerts & Configuration Entities**:
  * `supplier_price_history`: Stores historical supplier unit prices and percentage price-change comparisons across purchases.
  * `low_stock_notifications`: Tracks active, acknowledged, and resolved low-stock alerts with stock snapshots, user acknowledgments, and timestamps.
  * `system_settings`: Stores key-value system preferences (`bakery_name`, `theme_mode`, `notifications_enabled`, `price_spike_threshold_pct`, `default_profit_margin`, `table_initial_rows`, `currency_code`).

## Project Structure

```text
golden-hour-bakehouse/
├── assets/
│   ├── css/
│   │   └── style.css                # Core application stylesheet and dark-and-gold UI components
│   └── screenshots/                 # Application preview screenshots for documentation
├── config/
│   ├── database.php                 # PDO MySQL connection configuration
│   ├── notifications_helper.php     # Automated low-stock notification sync helper
│   └── settings_helper.php          # System settings persistence & dynamic theme CSS overrides
├── pages/
│   ├── ingredients.php              # Ingredient catalog, stock thresholds & per-unit nutrition
│   ├── suppliers.php                # Supplier management & automated supplier price tracker
│   ├── purchases.php                # Multi-item purchase entry, cost averaging & price alerts
│   ├── inventory.php                # Stock overview, manual adjustments & waste/spoilage logging
│   ├── recipes.php                  # Recipe formula builder & automated nutritional analysis
│   ├── recipe_costing.php           # Two-way batch-sizing calculator & profit margin pricing
│   ├── reports.php                  # Date-filtered operational, financial & wastage reports
│   └── settings.php                 # Theme switcher, notification toggles & system defaults
├── dashboard.php                    # Main executive dashboard with charts & low-stock alerts
├── login.php                        # Administrator authentication page
├── logout.php                       # Session termination script
├── golden_hour_bakehouse.sql        # Database schema and initial seed data
└── README.md                        # Project documentation
```

## Installation

1. **Download or Clone the Repository**:
   Place the `golden-hour-bakehouse` folder inside your web server's document root (for example, `C:\laragon\www\golden-hour-bakehouse` on Laragon or `htdocs` on XAMPP).
2. **Start Web & Database Services**:
   Launch Laragon (or Apache and MySQL) and verify that PHP 8.0+ and MySQL/MariaDB are running.
3. **Verify File Permissions**:
   Ensure PHP has read access to `config/` and `pages/` and session storage is enabled in `php.ini`.

## Database Setup

1. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) or MySQL Workbench.
2. Create and select the `golden_hour_bakehouse` database with collation `utf8mb4_unicode_ci`:
   ```sql
   CREATE DATABASE IF NOT EXISTS golden_hour_bakehouse
   CHARACTER SET utf8mb4
   COLLATE utf8mb4_unicode_ci;

   USE golden_hour_bakehouse;
   ```
3. Import `golden_hour_bakehouse.sql` to create all core and expanded tables (`users`, `ingredients`, `suppliers`, `purchases`, `purchase_items`, `supplier_price_history`, `inventory_adjustments`, `low_stock_notifications`, `recipes`, `recipe_ingredients`, `recipe_other_costs`, `recipe_cost_history`, and `system_settings`).
4. Open `config/database.php` and confirm your MySQL credentials match your local environment:
   ```php
   $host = "localhost";
   $dbname = "golden_hour_bakehouse";
   $username = "root";
   $password = "";
   ```

## Usage

1. Navigate to `http://localhost/golden-hour-bakehouse/login.php` (or `http://golden-hour-bakehouse.test/login.php`) in your browser and sign in with your administrator credentials.
2. **Manage Ingredients**: Go to **Ingredients** to register raw materials, configure minimum stock thresholds, and optionally enter per-base-unit nutrition values.
3. **Register Suppliers & Record Purchases**: Add vendors under **Suppliers**, then record incoming stock under **Purchases** to automatically update stock balances, weighted average unit costs, supplier price history, and low-stock alerts.
4. **Monitor Stock & Log Wastage**: Use **Inventory** to track real-time stock status and log spoilage or wastage with automatic financial loss calculation.
5. **Build Recipes & View Nutrition**: Create formulas and overhead costs in **Recipes**, and inspect automated per-unit and full-batch nutritional breakdowns.
6. **Scale Batches & Price Products**: Open **Recipe Costing** to scale recipes via batch multiplier or target output yield, check ingredient shortfalls, and calculate suggested selling prices.
7. **Review Reports & Customize Settings**: Generate period reports in **Reports** and configure themes, alert toggles, and default profit margins in **Settings**.

## Screenshots

* `assets/screenshots/dashboard.png` — Bakery Dashboard overview, charts, KPI cards, and low-stock alerts.
* `assets/screenshots/ingredients.png` — Ingredient management form, search/filter bar, and stock/nutrition table.
* `assets/screenshots/purchases.png` — Multi-item purchase entry form and supplier price tracking updates.
* `assets/screenshots/recipe-costing.png` — Two-way batch-sizing calculator, stock check, and scaled costing history.
* `assets/screenshots/reports.png` — Reporting period controls, summary metrics, and waste/spoilage report.
* `assets/screenshots/settings.png` — System settings for theme switching, notification alerts, and default thresholds.

## Author

* **Lisa Kemi** — [lisakemi](https://github.com/lisakemi)