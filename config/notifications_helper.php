<?php
require_once __DIR__ . "/settings_helper.php";

function syncLowStockNotification(PDO $pdo, int $ingredientId): void
{
    $settings = getSystemSettings($pdo);
    if (($settings["notifications_enabled"] ?? "1") !== "1") {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT ingredient_id, ingredient_name, current_stock, minimum_stock, is_active
         FROM ingredients
         WHERE ingredient_id = ?"
    );
    $stmt->execute([$ingredientId]);
    $ing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ing) {
        return;
    }

    $isLow = (int) $ing["is_active"] === 1
        && (float) $ing["current_stock"] <= (float) $ing["minimum_stock"];

    if ($isLow) {
        $existingStmt = $pdo->prepare(
            "SELECT notification_id
             FROM low_stock_notifications
             WHERE ingredient_id = ?
               AND status IN ('active', 'acknowledged')
             LIMIT 1"
        );
        $existingStmt->execute([$ingredientId]);

        if (!$existingStmt->fetch()) {
            $ins = $pdo->prepare(
                "INSERT INTO low_stock_notifications
                 (ingredient_id, current_stock_snapshot, minimum_stock_snapshot, status)
                 VALUES (?, ?, ?, 'active')"
            );
            $ins->execute([
                $ingredientId,
                $ing["current_stock"],
                $ing["minimum_stock"]
            ]);
        }
    } else {
        $res = $pdo->prepare(
            "UPDATE low_stock_notifications
             SET status = 'resolved', resolved_at = NOW()
             WHERE ingredient_id = ?
               AND status IN ('active', 'acknowledged')"
        );
        $res->execute([$ingredientId]);
    }
}