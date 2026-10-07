<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['item'])) {
    require_once "session.php";
    requireRole(['admin', 'production_staff']);
    csrf_check();

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

// NOTE: a legacy GET batch-completer lived here (state change by URL,
// no double-Completed guard, no quantity validation, skipped the total
// ledger, matched WHERE batch_id). Removed - the UI never called it and
// updateBatch.php is the single completion path. Do not reintroduce
// state-changing GET endpoints.

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