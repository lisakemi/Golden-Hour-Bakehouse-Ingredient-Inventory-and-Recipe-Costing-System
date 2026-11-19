# Golden Hour Bakehouse Ingredient Inventory and Recipe Costing System

## Project description

A PHP web application for Golden Hour Bakehouse that manages ingredients, suppliers, purchases, inventory movements, recipes, recipe costs, and operational reports. It updates ingredient costs using weighted-average costing and suggests selling prices from a target profit margin.

## Overview

The system was developed for the proposed Golden Hour Bakehouse. Its documented scope is administration of bakery ingredients and recipe costing; it does not include customer ordering or online payments. The documented application uses PHP with HTML5, CSS3, and JavaScript, MySQL 8.4.3, and PDO for database access.

## Features

- Administrator login and session-based access.
- Ingredient records, stock quantities, low-stock information, and weighted-average costs.
- Supplier records and purchase recording, including purchase items and totals.
- Conversion of supported purchase units to ingredient base units.
- Purchase processing that updates stock and weighted-average ingredient costs.
- Inventory transactions for purchases, usage, wastage, and stock adjustments.
- Recipe records, ingredient quantities, batch yields, and additional production costs.
- Recipe costing for ingredient costs, additional costs, batch totals, and cost per product.
- Suggested selling price based on a target profit margin, with costing history.
- Reports for purchases, inventory movements, low-stock information, and recipe costing, with date filtering documented for reports.

## Technology

| Area | Technology |
| --- | --- |
| Server-side application | PHP |
| User interface | HTML5, CSS3, JavaScript |
| Database | MySQL 8.4.3 |
| Database access | PHP Data Objects (PDO) |

The project report also identifies Laragon as the local development environment used during development. Confirm the runtime and versions used for the current deployment before relying on that environment detail.

## Data model

The project report documents these ten MySQL tables:

| Table | Purpose |
| --- | --- |
| `users` | Administrator account information. |
| `suppliers` | Supplier contact and identification details. |
| `ingredients` | Ingredient details, stock quantities, and weighted-average costs. |
| `purchases` | Purchase records and supplier association, where provided. |
| `purchase_items` | Ingredients and quantities included in each purchase. |
| `inventory_transactions` | Records purchases, usage, wastage, and stock adjustments. |
| `recipes` | Recipe descriptions, instructions, and batch yields. |
| `recipe_ingredients` | Ingredient requirements and quantities for recipes. |
| `recipe_other_costs` | Additional recipe production costs, such as packaging or electricity. |
| `recipe_cost_history` | Saved recipe costing results, including batch yield and suggested selling price. |

This table list is based on the project report. The database schema or migration files were not present in the available project files, so confirm table names and fields against the deployed database before making schema changes.

## Costing behavior

Purchases recalculate an ingredient's weighted-average cost using the existing stock and the newly purchased quantity and cost. Recipe costing uses the current ingredient costs together with configured additional recipe costs. It calculates batch cost and cost per product and can suggest a selling price using a target profit margin. Previous costing results are retained in recipe costing history.

Inventory transactions track stock movements. Recipe costing estimates production costs; the available project documentation does not establish that costing a recipe automatically consumes ingredient stock.

## Setup and configuration

The available project materials do not include application source files, a database export or migration, or deployment instructions. As a result, installation commands, database creation/import steps, environment variables, configuration file locations, web-server settings, and initial administrator account creation cannot be stated accurately here.

Before this README can include runnable setup instructions, confirm and provide:

1. The application source repository or deployment package and its actual entry point.
2. The schema creation/import procedure and any seed data.
3. The supported PHP version and required PHP extensions.
4. The actual database connection configuration method and required settings.
5. The web-server or local-runtime configuration used to serve the application.
6. The administrator account provisioning or password-reset process. No default credentials are documented here.

Do not place real database passwords or administrator credentials in this README.

## Verification status

The project report states that module and integration checks were completed successfully. This repository mirror did not contain executable application files or test instructions, so those results could not be independently rerun while preparing this README.

## Scope notes

The documented system has one administrator role. Customer accounts, customer ordering, online payments, and sales/order integration are outside the implemented scope described in the project report. Forecasting, mobile-specific enhancements, and automated stock deduction from recipe costing are not presented here as implemented features.
