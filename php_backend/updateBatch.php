<?php 
require_once "session.php";
requireRole(['admin', 'production_staff']);

if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['status'])) {
    $status = $_POST['status'] ?? '';
    $id = $_POST['id'] ?? '';
    $quantity = $_POST['quantityIn'] ?? 0;

    if (!in_array($status, ['Ongoing', 'Completed', 'Cancel'], true) || $id === '') {
        header("Location: ../production.php?error=1");
        exit;
    }

    // Cancel: delete the batch (only Ongoing batches reach this dialog).
    // Completed batches already moved stock to inventory/total and must not be deletable here.
    if ($status === "Cancel") {
        $stmt_fetch = $pdo->prepare("SELECT batch_id, item, quantity, unit, status FROM production WHERE production_id = :id");
        $stmt_fetch->execute([":id" => $id]);
        $row_fetch = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
        if (!$row_fetch || $row_fetch['status'] !== 'Ongoing') {
            header("Location: ../production.php?error=1");
            exit;
        }
        $stmt_del = $pdo->prepare("DELETE FROM production WHERE production_id = :id");
        $stmt_del->execute([":id" => $id]);

        $hist = $pdo->prepare("INSERT INTO history (user, user_role, action, ref_id, product, quantity, unit) VALUES (:user, :user_role, 'Cancelled Batch', :ref, :prod, :quan, :unit)");
        $hist->execute([':user' => $_SESSION['user_name'] ?? '', ':user_role' => $_SESSION['user_role'] ?? '', ':ref' => $row_fetch['batch_id'], ':prod' => $row_fetch['item'], ':quan' => $row_fetch['quantity'], ':unit' => $row_fetch['unit']]);

        header("Location: ../production.php?updated=1");
        exit;
    }

    // production
    $stmt = $pdo->prepare("UPDATE production SET status = :status, updated_at = NOW() WHERE production_id = :id");
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':id', $id);    

    // to inventory. Inventory gets its OWN random Item ID (prod_id) — never
    // the production batch_id. The batch link lives in description
    // ('Batch: <batch_id>') instead.

    if($status == "Completed") {
    $stmt_fetch = $pdo->prepare("SELECT batch_id, item, quantity, unit, status, receiver FROM production WHERE production_id = :id");
    $stmt_fetch->execute(["id"=>$id]);
    $row_fetch = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

    // Only append once: skip if already Completed (re-submit would double-count).
    if ($row_fetch && $row_fetch['status'] != 'Completed') {
        // Independent Item ID: random 7-digit, retry on PK clash.
        // Never derived from the batch_id.
        $inv_id = null;
        for ($t = 0; $t < 10; $t++) {
            $cand = (string)random_int(1000000, 9999999);
            $dup = $pdo->prepare("SELECT prod_id FROM inventory WHERE prod_id = :id");
            $dup->bindValue(':id', $cand);
            $dup->execute();
            if (!$dup->fetchColumn()) {
                $inv_id = $cand;
                break;
            }
        }
        if ($inv_id === null) {
            // Practically unreachable: time-based fallback, still batch-free.
            $inv_id = 'I' . date('ymdHis') . sprintf('%02d', random_int(0, 99));
        }
        // Stamp the produced amount on the batch so Production History shows it
        // (batches are created with quantity 0 until completed).
        $updQ = $pdo->prepare("UPDATE production SET quantity = :q WHERE production_id = :id");
        $updQ->bindValue(':q', $quantity);
        $updQ->bindValue(':id', $id);
        $updQ->execute();

        $stmt2 = $pdo->prepare("INSERT INTO inventory (prod_id, product, quantity, unit, description, stock_in, created_at) VALUES (:prod_id, :product, :quantity, :unit, :description, :stock_in, NOW())");
        $stmt2->bindValue(":prod_id", $inv_id);
        $stmt2->bindValue(":product", $row_fetch['item']);
        $stmt2->bindValue(":quantity", $quantity); // Stock In
        $stmt2->bindValue(":unit", $row_fetch['unit']);
        $stmt2->bindValue(":description", 'Batch: ' . $row_fetch['batch_id']);
        $stmt2->bindValue(':stock_in', $quantity);
        $stmt2->execute();

        $hist = $pdo->prepare("INSERT INTO history (user, user_role, action, ref_id, product, quantity, unit) VALUES (:user, :user_role, 'Completed Batch', :ref, :prod, :quan, :unit)");
        $hist->execute([':user' => $_SESSION['user_name'] ?? '', ':user_role' => $_SESSION['user_role'] ?? '', ':ref' => $row_fetch['batch_id'], ':prod' => $row_fetch['item'], ':quan' => $quantity, ':unit' => $row_fetch['unit']]);

        // Optional ledger table: skip silently when it does not exist.
        // Live stock is always SUM(quantity) from inventory.
        try {
            $stmtTotal = $pdo->prepare("SELECT total_stock FROM total LIMIT 1");
            $stmtTotal->execute();
            $row = $stmtTotal->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $newTotal = round((float)$row['total_stock'] + (float)$quantity, 2);
                $updateTotal = $pdo->prepare("UPDATE total SET total_stock = :total");
                $updateTotal->execute([':total' => $newTotal]);
            } else {
                $insertTotal = $pdo->prepare("INSERT INTO total (total_stock) VALUES (:total)");
                $insertTotal->execute([':total' => round((float)$quantity, 2)]);
            }
        } catch (Exception $e) {
            // no total table — nothing to update
        }
    }
    }

    // Best-effort snapshot: skip silently if the helper wasn't deployed.
    if (file_exists(__DIR__ . "/stock_snapshot.php")) {
        require_once "stock_snapshot.php";
        recordStockSnapshot($pdo);
    }

    if($stmt->execute()) {
        header("Location: ../production.php?updated=1");
        exit;
    } else {
        header("Location: ../production.php?error=1");
        exit;
    }
}
?>