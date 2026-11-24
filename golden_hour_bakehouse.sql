-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 24, 2026 at 08:50 AM
-- Server version: 8.0.46
-- PHP Version: 8.3.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `golden_hour_bakehouse`
--

-- --------------------------------------------------------

--
-- Table structure for table `ingredients`
--

CREATE TABLE `ingredients` (
  `ingredient_id` int NOT NULL,
  `ingredient_name` varchar(150) NOT NULL,
  `description` text,
  `base_unit` enum('grams','millilitres','pieces') NOT NULL,
  `current_stock` decimal(12,3) DEFAULT '0.000',
  `average_cost_per_unit` decimal(12,4) DEFAULT '0.0000',
  `calories_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `protein_g_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `carbs_g_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `fat_g_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `sugar_g_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `fiber_g_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `sodium_mg_per_unit` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `minimum_stock` decimal(12,3) DEFAULT '0.000',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `ingredients`
--

INSERT INTO `ingredients` (`ingredient_id`, `ingredient_name`, `description`, `base_unit`, `current_stock`, `average_cost_per_unit`, `calories_per_unit`, `protein_g_per_unit`, `carbs_g_per_unit`, `fat_g_per_unit`, `sugar_g_per_unit`, `fiber_g_per_unit`, `sodium_mg_per_unit`, `minimum_stock`, `is_active`, `created_at`) VALUES
(1, 'Wheat Flour', 'All-purpose baking flour (gluten-free)', 'grams', 53400.000, 2.7116, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 2000.000, 1, '2026-11-07 18:40:55'),
(2, 'Sugar', 'White granulated sugar', 'grams', 3000.000, 4.5000, 4.0000, 0.0000, 0.0000, 0.0000, 1.0000, 0.0000, 0.0000, 1000.000, 1, '2026-11-07 18:41:39'),
(3, 'Milk', 'Whole milk (dairy)', 'millilitres', 4900.000, 8.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 1000.000, 1, '2026-11-16 03:38:16'),
(4, 'Eggs', 'Chicken eggs', 'pieces', 150.000, 4200.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 120.000, 1, '2026-11-16 04:09:16'),
(5, 'Instant Yeast', 'For donuts and cinnamon rolls', 'grams', 0.000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 100.000, 1, '2026-11-21 08:05:43'),
(6, 'Butter', '', 'grams', 1000.000, 18.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 500.000, 1, '2026-11-21 13:37:06'),
(7, 'Chocolate chips', '', 'grams', 1000.000, 510.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 500.000, 1, '2026-11-21 13:37:37'),
(8, 'Vanilla essence', '', 'millilitres', 250.000, 30.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 100.000, 1, '2026-11-21 13:38:08'),
(9, 'Baking powder', '', 'grams', 250.000, 2000.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 100.000, 1, '2026-11-21 13:38:22'),
(10, 'Salt', '', 'grams', 500.000, 500.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 100.000, 1, '2026-11-21 13:38:36'),
(11, 'Icing sugar', '', 'grams', 1000.000, 0.0050, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 500.000, 1, '2026-11-21 13:39:21'),
(13, 'Granulated white sugar', 'Granulated white sugar used for baking, sweetening, and preparing bakery products.', 'grams', 0.000, 0.0000, 4.0000, 0.0000, 0.0000, 0.0000, 1.0000, 0.0000, 1.0000, 5000.000, 1, '2026-11-23 06:36:21');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `transaction_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `transaction_type` enum('purchase','usage','adjustment','wastage') NOT NULL,
  `quantity_change` decimal(12,3) NOT NULL,
  `balance_after` decimal(12,3) NOT NULL,
  `unit_cost` decimal(12,4) DEFAULT '0.0000',
  `reference_id` int DEFAULT NULL,
  `waste_category` varchar(60) DEFAULT NULL,
  `estimated_loss` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `transaction_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `inventory_transactions`
--

INSERT INTO `inventory_transactions` (`transaction_id`, `ingredient_id`, `transaction_type`, `quantity_change`, `balance_after`, `unit_cost`, `reference_id`, `waste_category`, `estimated_loss`, `notes`, `transaction_date`) VALUES
(1, 1, 'purchase', 2000.000, 2000.000, 5.0000, 1, NULL, 0.00, 'Purchase #1', '2026-11-16 03:32:31'),
(2, 1, 'purchase', 1000.000, 3000.000, 6.0000, 2, NULL, 0.00, 'Purchase #2', '2026-11-16 03:33:35'),
(3, 2, 'purchase', 3000.000, 3000.000, 4.5000, 3, NULL, 0.00, 'Purchase #3', '2026-11-16 03:37:26'),
(4, 3, 'purchase', 5000.000, 5000.000, 8.0000, 4, NULL, 0.00, 'Purchase #4', '2026-11-16 03:38:48'),
(5, 1, 'usage', -500.000, 2500.000, 5.3333, NULL, NULL, 0.00, 'Flour used for cookie production', '2026-11-16 03:43:43'),
(6, 1, 'wastage', -100.000, 2400.000, 5.3333, NULL, 'Damaged / Spilled', 533.33, 'Damaged flour', '2026-11-16 03:44:21'),
(7, 1, 'adjustment', -50.000, 2350.000, 5.3333, NULL, NULL, 0.00, NULL, '2026-11-16 03:44:56'),
(8, 1, 'adjustment', 50.000, 2400.000, 5.3333, NULL, NULL, 0.00, NULL, '2026-11-16 03:45:42'),
(9, 4, 'purchase', 60.000, 60.000, 3000.0000, 5, NULL, 0.00, 'Purchase #5', '2026-11-16 04:12:13'),
(10, 1, 'purchase', 1000.000, 3400.000, 7.0000, 6, NULL, 0.00, 'Purchase #6', '2026-11-16 04:31:55'),
(11, 4, 'purchase', 60.000, 120.000, 5000.0000, 7, NULL, 0.00, 'Purchase #7', '2026-11-21 07:26:26'),
(12, 4, 'purchase', 30.000, 150.000, 5000.0000, 8, NULL, 0.00, 'Purchase #8', '2026-11-21 07:27:54'),
(13, 7, 'purchase', 500.000, 500.000, 20.0000, 9, NULL, 0.00, 'Purchase #9', '2026-11-21 13:46:59'),
(14, 8, 'purchase', 250.000, 250.000, 30.0000, 9, NULL, 0.00, 'Purchase #9', '2026-11-21 13:46:59'),
(15, 11, 'purchase', 1000.000, 1000.000, 5000.0000, 9, NULL, 0.00, 'Purchase #9', '2026-11-21 13:46:59'),
(16, 6, 'purchase', 1000.000, 1000.000, 18.0000, 10, NULL, 0.00, 'Purchase #10', '2026-11-21 14:06:28'),
(17, 9, 'purchase', 250.000, 250.000, 2000.0000, 11, NULL, 0.00, 'Purchase #11', '2026-11-21 14:07:13'),
(18, 10, 'purchase', 500.000, 500.000, 500.0000, 12, NULL, 0.00, 'Purchase #12', '2026-11-21 14:07:49'),
(19, 7, 'purchase', 500.000, 1000.000, 1000.0000, 13, NULL, 0.00, 'Purchase #13', '2026-11-21 14:14:48'),
(20, 1, 'purchase', 50000.000, 53400.000, 2.5000, 14, NULL, 0.00, 'Purchase #14', '2026-11-23 06:07:53'),
(21, 3, 'wastage', -100.000, 4900.000, 8.0000, NULL, 'Expired / Past Shelf Life', 800.00, 'Unused for a while', '2026-11-23 06:16:54');

-- --------------------------------------------------------

--
-- Table structure for table `low_stock_notifications`
--

CREATE TABLE `low_stock_notifications` (
  `notification_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `stock_at_alert` decimal(12,3) NOT NULL,
  `minimum_stock_threshold` decimal(12,3) NOT NULL,
  `status` enum('active','acknowledged','resolved') NOT NULL DEFAULT 'active',
  `triggered_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `low_stock_notifications`
--

INSERT INTO `low_stock_notifications` (`notification_id`, `ingredient_id`, `stock_at_alert`, `minimum_stock_threshold`, `status`, `triggered_at`, `acknowledged_at`, `resolved_at`) VALUES
(1, 5, 0.000, 100.000, 'acknowledged', '2026-11-22 19:47:58', '2026-11-23 06:10:10', NULL),
(2, 13, 0.000, 5000.000, 'active', '2026-11-23 06:36:21', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `purchase_id` int NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `purchase_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `total_amount` decimal(12,2) DEFAULT '0.00',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`purchase_id`, `supplier_id`, `purchase_date`, `total_amount`, `notes`, `created_at`) VALUES
(1, 1, '2026-11-16 03:30:00', 10000.00, NULL, '2026-11-16 03:32:31'),
(2, 1, '2026-11-16 03:32:00', 6000.00, NULL, '2026-11-16 03:33:35'),
(3, 3, '2026-11-16 03:37:00', 13500.00, NULL, '2026-11-16 03:37:26'),
(4, 2, '2026-11-16 03:38:00', 40000.00, NULL, '2026-11-16 03:38:48'),
(5, 2, '2026-11-16 04:11:00', 180000.00, NULL, '2026-11-16 04:12:13'),
(6, 1, '2026-11-16 04:31:00', 7000.00, NULL, '2026-11-16 04:31:55'),
(7, 4, '2026-11-21 07:26:00', 300000.00, NULL, '2026-11-21 07:26:26'),
(8, 2, '2026-11-21 07:27:00', 150000.00, NULL, '2026-11-21 07:27:54'),
(9, 6, '2026-11-05 06:40:00', 22500.00, NULL, '2026-11-21 13:46:59'),
(10, 4, '2026-11-06 14:05:00', 18000.00, NULL, '2026-11-21 14:06:28'),
(11, 1, '2026-10-28 14:06:00', 500000.00, NULL, '2026-11-21 14:07:13'),
(12, 1, '2026-11-11 14:07:00', 250000.00, NULL, '2026-11-21 14:07:49'),
(13, 6, '2026-11-19 08:00:00', 500000.00, NULL, '2026-11-21 14:14:48'),
(14, 1, '2026-11-23 06:06:00', 125000.00, NULL, '2026-11-23 06:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `purchase_item_id` int NOT NULL,
  `purchase_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `purchase_quantity` decimal(12,3) NOT NULL,
  `purchase_unit` varchar(30) NOT NULL,
  `conversion_factor` decimal(12,4) NOT NULL,
  `quantity_in_base_unit` decimal(12,3) NOT NULL,
  `total_cost` decimal(12,2) NOT NULL,
  `unit_cost_in_base_unit` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `price_change_pct` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchase_items`
--

INSERT INTO `purchase_items` (`purchase_item_id`, `purchase_id`, `ingredient_id`, `purchase_quantity`, `purchase_unit`, `conversion_factor`, `quantity_in_base_unit`, `total_cost`, `unit_cost_in_base_unit`, `price_change_pct`, `created_at`) VALUES
(1, 1, 1, 2.000, 'kg', 1000.0000, 2000.000, 10000.00, 5.0000, NULL, '2026-11-16 03:32:31'),
(2, 2, 1, 1.000, 'kg', 1000.0000, 1000.000, 6000.00, 6.0000, NULL, '2026-11-16 03:33:35'),
(3, 3, 2, 3.000, 'kg', 1000.0000, 3000.000, 13500.00, 4.5000, NULL, '2026-11-16 03:37:26'),
(4, 4, 3, 5.000, 'litres', 1000.0000, 5000.000, 40000.00, 8.0000, NULL, '2026-11-16 03:38:48'),
(5, 5, 4, 60.000, 'pieces', 1.0000, 60.000, 180000.00, 3000.0000, NULL, '2026-11-16 04:12:13'),
(6, 6, 1, 1.000, 'kg', 1000.0000, 1000.000, 7000.00, 7.0000, NULL, '2026-11-16 04:31:55'),
(7, 7, 4, 60.000, 'pieces', 1.0000, 60.000, 300000.00, 5000.0000, NULL, '2026-11-21 07:26:26'),
(8, 8, 4, 30.000, 'pieces', 1.0000, 30.000, 150000.00, 5000.0000, NULL, '2026-11-21 07:27:54'),
(9, 9, 7, 500.000, 'grams', 1.0000, 500.000, 10000.00, 20.0000, NULL, '2026-11-21 13:46:59'),
(10, 9, 8, 250.000, 'millilitres', 1.0000, 250.000, 7500.00, 30.0000, NULL, '2026-11-21 13:46:59'),
(11, 9, 11, 1.000, 'kg', 1000.0000, 1000.000, 5000.00, 5.0000, NULL, '2026-11-21 13:46:59'),
(12, 10, 6, 1.000, 'kg', 1000.0000, 1000.000, 18000.00, 18.0000, NULL, '2026-11-21 14:06:28'),
(13, 11, 9, 250.000, 'grams', 1.0000, 250.000, 500000.00, 2000.0000, NULL, '2026-11-21 14:07:13'),
(14, 12, 10, 500.000, 'grams', 1.0000, 500.000, 250000.00, 500.0000, NULL, '2026-11-21 14:07:49'),
(15, 13, 7, 500.000, 'grams', 1.0000, 500.000, 500000.00, 1000.0000, NULL, '2026-11-21 14:14:48'),
(16, 14, 1, 50.000, 'kg', 1000.0000, 50000.000, 125000.00, 2.5000, -64.29, '2026-11-23 06:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `recipes`
--

CREATE TABLE `recipes` (
  `recipe_id` int NOT NULL,
  `recipe_name` varchar(150) NOT NULL,
  `description` text,
  `batch_yield` decimal(10,2) NOT NULL,
  `yield_unit` varchar(30) DEFAULT 'pieces',
  `serving_size_label` varchar(60) DEFAULT '1 serving',
  `instructions` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `recipes`
--

INSERT INTO `recipes` (`recipe_id`, `recipe_name`, `description`, `batch_yield`, `yield_unit`, `serving_size_label`, `instructions`, `is_active`, `created_at`) VALUES
(1, 'Classic Homemade Cinnamon Rolls', 'Classic Homemade Cinnamon Rolls are soft, fluffy, and packed with a gooey brown sugar cinnamon filling, all topped with a rich cream cheese icing.', 12.00, 'rolls', '1 serving', '1. Make the Dough\r\n\r\n• Activate the yeast: In a large bowl, combine the warm milk, 1 tablespoon of the granulated sugar, and the yeast. Let it sit for 5–10 minutes until it becomes frothy.\r\n• Mix the wet ingredients: Whisk the remaining sugar, eggs, and melted butter into the yeast mixture.\r\n• Combine and knead: Add 4 cups of flour and the salt. Stir with a wooden spoon until a dough forms. Turn the dough onto a floured surface and knead for 8–10 minutes (or use a stand mixer with a dough hook for 5–7 minutes) until smooth and elastic. If too sticky, add the remaining ½ cup of flour.\r\n• First rise: Place the dough in a greased bowl, cover with a damp cloth, and let rise in a warm place for 1 to 1.5 hours, or until doubled in size.\r\n\r\n2. Assemble the Rolls\r\n\r\n• Roll it out: Punch down the risen dough and turn it out onto a lightly floured surface. Roll it into a large rectangle, roughly 12x18 inches.\r\n• Add the filling: Spread the softened butter evenly over the dough, leaving a small border at the edges. In a small bowl, mix the brown sugar and cinnamon, then sprinkle it evenly over the butter.\r\n• Roll and cut: Tightly roll the dough into a log, starting from the long edge. Cut into 12 equal slices using a sharp serrated knife or unflavored dental floss.\r\n• Second rise: Place the rolls in a greased 9x13-inch baking dish. Cover and let rise again for 30–45 minutes until puffy.\r\n\r\n3. Bake and Ice\r\n\r\n• Bake: Preheat your oven to 350°F (175°C). Bake the rolls for 20–25 minutes until they are lightly golden brown.\r\n• Make the icing: While the rolls bake, beat the cream cheese and butter together until smooth. Add the powdered sugar and vanilla, whisking until creamy.\r\n• Finish: Spread the icing over the rolls while they are still warm so it melts into the cracks.', 1, '2026-11-16 03:53:51'),
(2, 'Classic Glazed Yeast Donuts', 'Classic Glazed Yeast Donuts are light, airy, golden-fried rings coated in a sweet, glossy vanilla glaze.', 12.00, 'pieces', '1 serving', '1. Make the Dough\r\n\r\n• Activate the yeast: Pour the warm milk into a large mixing bowl. Stir in 10 g of the granulated sugar and sprinkle the yeast over the top, letting it sit for 5 to 10 minutes until foamy.\r\n• Mix the wet ingredients: Whisk the remaining 40 g of sugar, the eggs, and the melted butter into the yeast mixture until fully combined.\r\n• Knead the dough: Add the flour and salt, mixing until a rough dough forms. Knead on a lightly floured surface for 8 to 10 minutes until smooth and elastic.\r\n• First rise: Place the dough in a lightly oiled bowl and cover it with a clean towel. Let it rise in a warm spot for 1 to 1.5 hours, or until doubled in size.\r\n\r\n2. Shape the Donuts\r\n\r\n• Roll the dough: Punch down the risen dough to release the air. Roll it out on a floured surface to an even thickness of about 1.25 cm.\r\n• Cut the rings: Use an 8 cm round cutter to cut out circles, then use a 2.5 cm cutter to punch out the centers. Re-roll any scraps gently to cut the remaining donuts.\r\n• Second rise: Place the cut donuts and donut holes onto a parchment-lined baking sheet. Cover loosely and let them rise for 30 to 45 minutes until puffy.\r\n\r\n3. Fry and Glaze\r\n\r\n• Heat the oil: Pour the oil into a heavy-bottomed pot or deep fryer. Heat it over medium heat until a thermometer reads 175°C.\r\n• Fry the donuts: Carefully lower 2 or 3 donuts into the hot oil at a time. Fry for 1 minute per side until golden brown, then transfer them to a wire rack lined with paper towels to drain.\r\n• Make the glaze: Whisk the powdered sugar, milk, and vanilla extract together in a medium bowl until smooth.\r\n• Coat: Dip each warm donut into the glaze, turning to coat both sides. Return the glazed donuts to the wire rack for 10 minutes to let the glaze set before serving.', 1, '2026-11-16 04:17:37'),
(3, 'Chocolate Chip Cookies', 'Soft, golden cookies with chocolate chips.', 24.00, 'pieces', '1 serving', 'Wheat flour	360	g\r\nGranulated sugar	150	g\r\nButter	200	g\r\nEggs	2	pieces\r\nChocolate chips	180	g\r\nVanilla essence	5	ml\r\nBaking powder	6	g\r\nSalt	3	g', 1, '2026-11-21 14:04:35'),
(4, 'Vanilla Cupcakes with Buttercream Frosting', 'Soft vanilla cupcakes topped with homemade buttercream frosting.', 12.00, 'pieces', '1 serving', 'Wheat flour	240	g\r\nGranulated sugar	180	g\r\nButter	300	g\r\nEggs	2	pieces\r\nMilk	150	ml\r\nVanilla essence	7	ml\r\nBaking powder	8	g\r\nSalt	2	g\r\nIcing sugar	200	g', 1, '2026-11-21 14:12:57');

-- --------------------------------------------------------

--
-- Table structure for table `recipe_cost_history`
--

CREATE TABLE `recipe_cost_history` (
  `cost_history_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `ingredient_cost` decimal(12,2) NOT NULL,
  `other_cost` decimal(12,2) DEFAULT '0.00',
  `total_production_cost` decimal(12,2) NOT NULL,
  `cost_per_product` decimal(12,2) NOT NULL,
  `target_profit_margin` decimal(5,2) NOT NULL,
  `suggested_selling_price` decimal(12,2) NOT NULL,
  `calculated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `batch_yield` decimal(10,2) NOT NULL DEFAULT '1.00',
  `batch_multiplier` decimal(10,2) NOT NULL DEFAULT '1.00',
  `scaled_yield_total` decimal(10,2) DEFAULT NULL,
  `yield_unit` varchar(30) NOT NULL DEFAULT 'pieces'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `recipe_cost_history`
--

INSERT INTO `recipe_cost_history` (`cost_history_id`, `recipe_id`, `ingredient_cost`, `other_cost`, `total_production_cost`, `cost_per_product`, `target_profit_margin`, `suggested_selling_price`, `calculated_at`, `batch_yield`, `batch_multiplier`, `scaled_yield_total`, `yield_unit`) VALUES
(1, 1, 3158.32, 4500.00, 7658.32, 638.19, 30.00, 911.70, '2026-11-16 04:02:05', 12.00, 1.00, 12.00, 'rolls'),
(2, 1, 3158.32, 4500.00, 7658.32, 638.19, 20.00, 797.74, '2026-11-16 04:03:33', 12.00, 1.00, 12.00, 'rolls'),
(3, 2, 10704.98, 4400.00, 15104.98, 1258.75, 30.00, 1798.21, '2026-11-16 04:18:05', 12.00, 1.00, 12.00, 'pieces'),
(4, 2, 10940.28, 4400.00, 15340.28, 1278.36, 10.00, 1420.40, '2026-11-16 04:35:08', 12.00, 1.00, 12.00, 'pieces'),
(5, 1, 3354.40, 4500.00, 7854.40, 654.53, 20.00, 818.17, '2026-11-16 05:51:08', 12.00, 1.00, 12.00, 'rolls'),
(6, 3, 32021.46, 6400.00, 38421.46, 1600.89, 30.00, 2286.99, '2026-11-21 14:07:54', 24.00, 1.00, 24.00, 'pieces'),
(7, 4, 34418.64, 3960.00, 38378.64, 3198.22, 30.00, 4568.89, '2026-11-21 14:13:17', 12.00, 1.00, 12.00, 'pieces'),
(8, 3, 297752.94, 16000.00, 313752.94, 5229.22, 30.00, 7470.31, '2026-11-23 06:26:16', 24.00, 2.50, 60.00, 'pieces'),
(9, 3, 297752.94, 16000.00, 313752.94, 5229.22, 30.00, 7470.31, '2026-11-23 06:26:55', 24.00, 2.50, 60.00, 'pieces');

-- --------------------------------------------------------

--
-- Table structure for table `recipe_ingredients`
--

CREATE TABLE `recipe_ingredients` (
  `recipe_ingredient_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `required_quantity` decimal(12,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `recipe_ingredients`
--

INSERT INTO `recipe_ingredients` (`recipe_ingredient_id`, `recipe_id`, `ingredient_id`, `required_quantity`, `created_at`) VALUES
(1, 1, 1, 400.000, '2026-11-16 03:53:51'),
(2, 1, 3, 100.000, '2026-11-16 03:53:51'),
(3, 1, 2, 50.000, '2026-11-16 03:53:51'),
(4, 2, 1, 480.000, '2026-11-16 04:17:37'),
(5, 2, 4, 2.000, '2026-11-16 04:17:37'),
(6, 2, 3, 240.000, '2026-11-16 04:17:37'),
(7, 2, 2, 50.000, '2026-11-16 04:17:37'),
(8, 3, 1, 360.000, '2026-11-21 14:04:35'),
(9, 3, 2, 150.000, '2026-11-21 14:04:35'),
(10, 3, 6, 200.000, '2026-11-21 14:04:35'),
(11, 3, 4, 2.000, '2026-11-21 14:04:35'),
(12, 3, 7, 180.000, '2026-11-21 14:04:35'),
(13, 3, 8, 5.000, '2026-11-21 14:04:35'),
(14, 3, 9, 6.000, '2026-11-21 14:04:35'),
(15, 3, 10, 3.000, '2026-11-21 14:04:35'),
(16, 4, 1, 240.000, '2026-11-21 14:12:57'),
(17, 4, 2, 180.000, '2026-11-21 14:12:57'),
(18, 4, 6, 300.000, '2026-11-21 14:12:57'),
(19, 4, 4, 2.000, '2026-11-21 14:12:57'),
(20, 4, 3, 150.000, '2026-11-21 14:12:57'),
(21, 4, 8, 7.000, '2026-11-21 14:12:57'),
(22, 4, 9, 8.000, '2026-11-21 14:12:57'),
(23, 4, 10, 2.000, '2026-11-21 14:12:57'),
(24, 4, 11, 200.000, '2026-11-21 14:12:57');

-- --------------------------------------------------------

--
-- Table structure for table `recipe_other_costs`
--

CREATE TABLE `recipe_other_costs` (
  `other_cost_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `cost_name` varchar(100) NOT NULL,
  `cost_amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `recipe_other_costs`
--

INSERT INTO `recipe_other_costs` (`other_cost_id`, `recipe_id`, `cost_name`, `cost_amount`, `created_at`) VALUES
(1, 1, 'Package Containers', 2500.00, '2026-11-16 03:53:51'),
(2, 1, 'Electricity', 2000.00, '2026-11-16 03:53:51'),
(3, 2, 'Package Containers', 2400.00, '2026-11-16 04:17:37'),
(4, 2, 'Electricity', 2000.00, '2026-11-16 04:17:37'),
(5, 3, 'Electricity and oven usage', 1500.00, '2026-11-21 14:04:35'),
(6, 3, 'Individual packaging', 2400.00, '2026-11-21 14:04:35'),
(7, 3, 'Labour', 2500.00, '2026-11-21 14:04:35'),
(8, 4, 'Electricity and oven usage', 1200.00, '2026-11-21 14:12:57'),
(9, 4, 'Cupcake liners', 960.00, '2026-11-21 14:12:57'),
(10, 4, 'Labour', 1800.00, '2026-11-21 14:12:57');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `phone`, `email`, `address`, `created_at`, `is_active`) VALUES
(1, 'Morogoro Flour Supplies', 'John', '0712345678', 'supplier@example.com', 'Morogoro, Tanzania', '2026-11-07 19:24:22', 1),
(2, 'Rose Acre Farms', 'MaryJ', '0756781234', 'mary@roseacre.com', 'Morogoro, Tanzania', '2026-11-07 19:25:39', 1),
(3, 'Kagera Sugar Limited', 'Tuheri Philipo Makundi', '0753354756', 'tuheriphilipo@ksl.com', 'Marangu Mashariki, Moshi vijijini', '2026-11-16 03:36:54', 1),
(4, 'Fresh Dairy Supplies', 'Jackline Tuheri Makundi', '0752327455', 'jackline@freshdairy.com', 'Marangu Mashariki, Moshi vijijini', '2026-11-16 04:10:14', 1),
(5, 'Dar Baking Supplies', 'Neema Erastom Mrosso', '0753354756', 'neemamrosso@gmail.com', 'Marangu Mashariki, Moshi vijijini', '2026-11-21 13:31:39', 1),
(6, 'Sweet Essentials', 'Melinda Johnson', '0753554754', 'melinda@sweetessentials.com', 'Masaki, Dar es Salaam', '2026-11-21 13:33:55', 1),
(7, 'Fresh Farm Supplies', 'Aaliyah Ahmed', '0752627445', 'aaliyah@freshfarm.com', 'Marangu, Moshi vijijini', '2026-11-21 13:35:18', 1);

-- --------------------------------------------------------

--
-- Table structure for table `supplier_price_history`
--

CREATE TABLE `supplier_price_history` (
  `price_history_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `purchase_id` int DEFAULT NULL,
  `purchase_unit` varchar(30) NOT NULL,
  `unit_price_paid` decimal(12,2) NOT NULL,
  `price_per_base_unit` decimal(12,4) NOT NULL,
  `previous_price_per_base_unit` decimal(12,4) DEFAULT NULL,
  `price_change_pct` decimal(8,2) DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `supplier_price_history`
--

INSERT INTO `supplier_price_history` (`price_history_id`, `supplier_id`, `ingredient_id`, `purchase_id`, `purchase_unit`, `unit_price_paid`, `price_per_base_unit`, `previous_price_per_base_unit`, `price_change_pct`, `recorded_at`) VALUES
(1, 1, 9, 11, 'grams', 2000.00, 2000.0000, NULL, NULL, '2026-10-28 14:06:00'),
(2, 6, 7, 9, 'grams', 20.00, 20.0000, NULL, NULL, '2026-11-05 06:40:00'),
(3, 6, 8, 9, 'millilitres', 30.00, 30.0000, NULL, NULL, '2026-11-05 06:40:00'),
(4, 6, 11, 9, 'kg', 5000.00, 5.0000, NULL, NULL, '2026-11-05 06:40:00'),
(5, 4, 6, 10, 'kg', 18000.00, 18.0000, NULL, NULL, '2026-11-06 14:05:00'),
(6, 1, 10, 12, 'grams', 500.00, 500.0000, NULL, NULL, '2026-11-11 14:07:00'),
(7, 1, 1, 1, 'kg', 5000.00, 5.0000, NULL, NULL, '2026-11-16 03:30:00'),
(8, 1, 1, 2, 'kg', 6000.00, 6.0000, NULL, NULL, '2026-11-16 03:32:00'),
(9, 3, 2, 3, 'kg', 4500.00, 4.5000, NULL, NULL, '2026-11-16 03:37:00'),
(10, 2, 3, 4, 'litres', 8000.00, 8.0000, NULL, NULL, '2026-11-16 03:38:00'),
(11, 2, 4, 5, 'pieces', 3000.00, 3000.0000, NULL, NULL, '2026-11-16 04:11:00'),
(12, 1, 1, 6, 'kg', 7000.00, 7.0000, NULL, NULL, '2026-11-16 04:31:00'),
(13, 6, 7, 13, 'grams', 1000.00, 1000.0000, NULL, NULL, '2026-11-19 08:00:00'),
(14, 4, 4, 7, 'pieces', 5000.00, 5000.0000, NULL, NULL, '2026-11-21 07:26:00'),
(15, 2, 4, 8, 'pieces', 5000.00, 5000.0000, NULL, NULL, '2026-11-21 07:27:00'),
(16, 1, 1, 14, 'kg', 2500.00, 2.5000, 7.0000, -64.29, '2026-11-23 06:06:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `password_hash`, `created_at`) VALUES
(1, 'Lisa Kemi', 'goldenHour', 'lisarutasitara@gmail.com', '$2y$10$824yYbuJ7Y1Jfj6mTAZjyehtHGdEueF9lNrwlOe7fO9d9DP5NG6M6', '2026-11-07 18:20:08');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD PRIMARY KEY (`ingredient_id`),
  ADD UNIQUE KEY `ingredient_name` (`ingredient_name`);

--
-- Indexes for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `low_stock_notifications`
--
ALTER TABLE `low_stock_notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_lsn_ingredient_status` (`ingredient_id`,`status`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`purchase_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`purchase_item_id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `recipes`
--
ALTER TABLE `recipes`
  ADD PRIMARY KEY (`recipe_id`),
  ADD UNIQUE KEY `recipe_name` (`recipe_name`);

--
-- Indexes for table `recipe_cost_history`
--
ALTER TABLE `recipe_cost_history`
  ADD PRIMARY KEY (`cost_history_id`),
  ADD KEY `recipe_id` (`recipe_id`);

--
-- Indexes for table `recipe_ingredients`
--
ALTER TABLE `recipe_ingredients`
  ADD PRIMARY KEY (`recipe_ingredient_id`),
  ADD UNIQUE KEY `recipe_id` (`recipe_id`,`ingredient_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `recipe_other_costs`
--
ALTER TABLE `recipe_other_costs`
  ADD PRIMARY KEY (`other_cost_id`),
  ADD KEY `recipe_id` (`recipe_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `supplier_price_history`
--
ALTER TABLE `supplier_price_history`
  ADD PRIMARY KEY (`price_history_id`),
  ADD KEY `idx_sph_supplier_ingredient` (`supplier_id`,`ingredient_id`,`recorded_at`),
  ADD KEY `idx_sph_ingredient` (`ingredient_id`),
  ADD KEY `idx_sph_purchase` (`purchase_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `ingredient_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `transaction_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `low_stock_notifications`
--
ALTER TABLE `low_stock_notifications`
  MODIFY `notification_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `purchase_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `purchase_item_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `recipes`
--
ALTER TABLE `recipes`
  MODIFY `recipe_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `recipe_cost_history`
--
ALTER TABLE `recipe_cost_history`
  MODIFY `cost_history_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `recipe_ingredients`
--
ALTER TABLE `recipe_ingredients`
  MODIFY `recipe_ingredient_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `recipe_other_costs`
--
ALTER TABLE `recipe_other_costs`
  MODIFY `other_cost_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `supplier_price_history`
--
ALTER TABLE `supplier_price_history`
  MODIFY `price_history_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `low_stock_notifications`
--
ALTER TABLE `low_stock_notifications`
  ADD CONSTRAINT `fk_lsn_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `recipe_cost_history`
--
ALTER TABLE `recipe_cost_history`
  ADD CONSTRAINT `recipe_cost_history_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`recipe_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `recipe_ingredients`
--
ALTER TABLE `recipe_ingredients`
  ADD CONSTRAINT `recipe_ingredients_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`recipe_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `recipe_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `recipe_other_costs`
--
ALTER TABLE `recipe_other_costs`
  ADD CONSTRAINT `recipe_other_costs_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`recipe_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `supplier_price_history`
--
ALTER TABLE `supplier_price_history`
  ADD CONSTRAINT `fk_sph_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sph_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`purchase_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sph_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
