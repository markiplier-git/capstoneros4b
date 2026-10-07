<?php
require_once "session.php";
requireRole(['admin', 'inventory_staff']);
csrf_check();

// Update/Edit Total Stock: adjust the single-row `total` ledger.
// direction=deduct removes the entered amount (floored at 0);
// direction=add increases it. Inventory rows are fixed
// production-tracking records and are never touched here.
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['quantity'])) {
    $unit = $_POST['unit'] ?? '';
    if (!in_array($unit, ['KG', 'Sacks'], true)) {
        header("Location: ../inventory.php?error=1");
        exit;
    }
    $entered = round((float)($_POST['quantity'] ?? 0), 2);
    if($unit == 'KG') $entered = round($entered / 50, 2);
    $receiver = trim($_POST['description'] ?? '');
    $direction = $_POST['direction'] ?? 'deduct';
    if ($entered <= 0 || !in_array($direction, ['deduct', 'add'], true)) {
        header("Location: ../inventory.php?error=1");
        exit;
    }

    $stmtTotal = $pdo->prepare("SELECT total_stock FROM total LIMIT 1");
    $stmtTotal->execute();
    $totalRow = $stmtTotal->fetch(PDO::FETCH_ASSOC);
    $current = (float)($totalRow['total_stock'] ?? 0);

    if ($direction === 'add') {
        $add = $entered;
        if ($totalRow) {
            $updT = $pdo->prepare("UPDATE total SET total_stock = :total");
            $updT->execute([':total' => round($current + $add, 2)]);
        } else {
            $insT = $pdo->prepare("INSERT INTO total (total_stock) VALUES (:total)");
            $insT->execute([':total' => $add]);
        }
        $action = 'Stock Added';
        $amount = $add;
    } else {
        $deduct = round(min($entered, $current), 2);
        if ($deduct <= 0) {
            header("Location: ../inventory.php?error=1");
            exit;
        }

        $updT = $pdo->prepare("UPDATE total SET total_stock = :total");
        $updT->execute([':total' => round($current - $deduct, 2)]);
        $action = 'Stock Deducted';
        $amount = $deduct;
    }

    $hist = $pdo->prepare("INSERT INTO history (user, user_role, action, ref_id, product, quantity, unit, receiver) VALUES (:user, :user_role, :action, 'TOTAL', :prod, :quan, :unit, :receiver)");
    $hist->execute([
        ':user' => $_SESSION['user_name'] ?? '',
        ':user_role' => $_SESSION['user_role'] ?? '',
        ':action' => $action,
        ':prod' => 'Vermicast',
        ':quan' => $amount,
        ':unit' => 'Sacks',
        ':receiver' => $receiver !== '' ? substr($receiver, 0, 50) : null
    ]);

    // Best-effort snapshot: skip silently if the helper wasn't deployed.
    if (file_exists(__DIR__ . "/stock_snapshot.php")) {
        require_once "stock_snapshot.php";
        recordStockSnapshot($pdo);
    }

    header("Location: ../inventory.php?success=1");
    exit;
}

header("Location: ../inventory.php?error=1");
exit;
?>
