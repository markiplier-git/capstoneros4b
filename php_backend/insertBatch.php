<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['item'])) {
    require_once "session.php";
    requireRole(['admin', 'production_staff']);

    $item = trim($_POST['item']);
    $unit = trim($_POST['unit'] ?? 'Sacks');
    if ($unit === '') {
        $unit = 'Sacks';
    }
    $receiver = trim($_POST['company'] ?? $_POST['receiver'] ?? '');

    // Batch ID: {Letter}{YYMMDD}_{NNN}{SS}, e.g. A261005_00106.
    // Fixed widths + zero-padding keep alphabetical order == chronological
    // order. NNN is the per-day sequence (001-999); SS is current seconds.
    // The letter rolls A->B->... once 999 sequences for that letter+day
    // are used up. 13 chars: fits inventory.prod_id/history.ref_id (15).
    $batch_id = null;
    $inserted = false;
    for ($attempt = 0; $attempt < 4 && !$inserted; $attempt++) {
        $batch_id = generateBatchId($pdo);

        # INsert into Production db
        $stmt = $pdo->prepare("INSERT INTO production (batch_id, quantity, item, unit, receiver, status, production_date) VALUES (:batch_id, 0, :item, :unit, :receiver, 'Ongoing', CURDATE())");
        $stmt->bindValue(':batch_id', $batch_id);
        $stmt->bindValue(':item', $item);
        $stmt->bindValue(':unit', $unit);
        $stmt->bindValue(':receiver', $receiver);

        try {
            $inserted = $stmt->execute();
        } catch (PDOException $e) {
            if (($e->getCode() ?? '') !== '23000') {
                throw $e;
            }
            // Duplicate race (same second, same seq): retry with a fresh ID.
            $inserted = false;
        }
    }

    if ($inserted) {
        $hist = $pdo->prepare("INSERT INTO history (user, user_role, action, ref_id, product, quantity, unit) VALUES (:user, :user_role, 'Created Batch', :ref, :prod, 0, :unit)");
        $hist->execute([':user' => $_SESSION['user_name'] ?? '', ':user_role' => $_SESSION['user_role'] ?? '', ':ref' => $batch_id, ':prod' => $item, ':unit' => $unit]);
        header("Location: ../production.php");
        exit;
    } else {echo "Cannot add into production.";}

    echo "Invalid Data";
}

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['status_id'])) {
    require_once "session.php";
    requireRole(['admin', 'production_staff']);
    $id = $_GET['id'];
    $batch = $_GET['batch'];
    $date = $_GET['date'];
    $item = $_GET['item'];
    $quantity = $_GET['quantity'];
    $unit = $_GET['unit'];
    $status = $_GET['status_id'];
    
# Auto insert if completed instead. 
# prod_id	product	quantity	unit	status	description	created_at	updated_at	
    if ($status == 'Completed') {
        // Independent Item ID (never the production/batch id); batch link
        // goes in description, same as updateBatch.php.
        $inv_prod = null;
        for ($t = 0; $t < 10; $t++) {
            $cand = (string)random_int(1000000, 9999999);
            $stmt_id = $pdo->prepare("SELECT prod_id FROM inventory WHERE prod_id = :id");
            $stmt_id->bindValue(':id', $cand);
            $stmt_id->execute();
            if (!$stmt_id->fetchColumn()) {
                $inv_prod = $cand;
                break;
            }
        }
        if ($inv_prod === null) {
            $inv_prod = 'I' . date('ymdHis') . sprintf('%02d', random_int(0, 99));
        }
        $stmt_inventory = $pdo->prepare("INSERT INTO inventory (prod_id, product, quantity, unit, description) VALUES (:prod_id, :product, :quantity, :unit, :description)");
        $stmt_inventory->bindValue(':prod_id', $inv_prod);
        $stmt_inventory->bindValue(':product', $item);
        $stmt_inventory->bindValue(':quantity', $quantity);
        $stmt_inventory->bindValue(':unit', $unit);
        $stmt_inventory->bindValue(':description', 'Batch: ' . $batch);
        if ($stmt_inventory->execute()) {
            echo "Added in inventory successfully";
        }
    }

    $stmt_status = $pdo->prepare("UPDATE production SET status = :status, updated_at = NOW() WHERE batch_id = :batch_id");
    $stmt_status->bindValue(':batch_id', $_GET['batch']);
    $stmt_status->bindValue(':status', $status);
    $stmt_status->execute();

    if ($status == 'Completed') {
        $hist = $pdo->prepare("INSERT INTO history (user, user_role, action, ref_id, product, quantity, unit) VALUES (:user, :user_role, 'Completed Batch', :ref, :prod, :quan, :unit)");
        $hist->execute([':user' => $_SESSION['user_name'] ?? '', ':user_role' => $_SESSION['user_role'] ?? '', ':ref' => $_GET['batch'], ':prod' => $item, ':quan' => $quantity, ':unit' => $unit]);
    }

    header("Location: ../production.php");
}

// Batch ID generator: {Letter}{YYMMDD}_{NNN}{SS} (13 chars).
// Letter rolls A->B->... after 999 sequences for that letter+day.
function generateBatchId($pdo) {
    $datePart = date('ymd');
    for ($l = ord('A'); $l <= ord('Z'); $l++) {
        $prefix = chr($l) . $datePart;
        // SUBSTRING (not LIKE) so the '_' separator needs no escaping,
        // and legacy 7-digit numeric IDs can never match a letter prefix.
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(batch_id, 9, 3) AS UNSIGNED)) FROM production WHERE SUBSTRING(batch_id, 1, 7) = :prefix");
        $stmt->execute([':prefix' => $prefix]);
        $max = $stmt->fetchColumn();
        $seq = ($max === null || $max === false) ? 1 : ((int)$max + 1);
        if ($seq > 999) {
            continue;
        }
        for (; $seq <= 999; $seq++) {
            $candidate = $prefix . '_' . sprintf('%03d', $seq) . date('s');
            $chk = $pdo->prepare("SELECT 1 FROM production WHERE batch_id = :id");
            $chk->execute([':id' => $candidate]);
            if (!$chk->fetchColumn()) {
                return $candidate;
            }
        }
    }
    throw new RuntimeException('Batch ID space exhausted for today.');
}
?>