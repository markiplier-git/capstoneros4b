<?php
require_once "session.php";
require_once "db.php";

requireRole(['admin', 'inventory_staff']);
csrf_check();

// Month-paired historical sales import. File shape (JSON array, alternating
// month and quantity, ascending, closed months only, max 60 pairs):
//   ["2023-10", 25, "2023-11", 20, "2023-12", 50]
// Coverage is oldest-vs-latest: months missing inside the span make the file
// incomplete and block the import. A month with 0 counts but is flagged.
// Flow is two-step: upload -> preview (stored in session) -> confirm.

// Cancel a pending preview.
if (isset($_GET['cancel_import'])) {
    unset($_SESSION['import_preview']);
    header("Location: ../sales.php");
    exit;
}

// Step 2: confirmed preview -> write rows.
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['confirm_import'])) {
    confirmImport($pdo);
    exit; // confirmImport always redirects.
}

if ($_SERVER['REQUEST_METHOD'] != "POST" || !isset($_FILES['historyFile'])) {
    fail("No file uploaded.");
}

if ($_FILES['historyFile']['error'] !== UPLOAD_ERR_OK) {
    fail("Upload failed. Try again.");
}

$raw = file_get_contents($_FILES['historyFile']['tmp_name']);
$values = json_decode($raw, true);

if (!is_array($values) || empty($values)) {
    fail("Invalid file! Must be a JSON array of month/quantity pairs: [\"2023-10\", 25, \"2023-11\", 20].");
}

$pairs = parseMonthPairs($values);
$report = analyzeCoverage($pairs);

// Stash for the preview step on sales.php.
$_SESSION['import_preview'] = [
    'pairs' => $pairs,
    'oldest' => $report['oldest'],
    'latest' => $report['latest'],
    'complete' => $report['complete'],
    'missing' => $report['missing'],
    'zeros' => $report['zeros'],
    'conflicts' => countConflicts($pdo, $report['oldest'], $report['latest']),
];
header("Location: ../sales.php#preview");
exit;

function fail($msg) {
    unset($_SESSION['import_preview']);
    header("Location: ../sales.php?import_error=" . urlencode($msg));
    exit;
}

// Validate the alternating ["YYYY-MM", qty, ...] shape. Returns list of
// ['month' => 'YYYY-MM', 'qty' => float] in ascending order.
function parseMonthPairs($values) {
    $n = count($values);
    if ($n % 2 !== 0) {
        fail("Invalid file! Month/quantity pairs are unbalanced (odd element count). Expected [\"YYYY-MM\", qty, ...].");
    }
    $count = $n / 2;
    if ($count > 60) {
        fail("Too many months! Max 60 pairs. Got $count.");
    }
    $curMonth = date('Y-m');
    $pairs = [];
    $seen = [];
    for ($i = 0; $i < $n; $i += 2) {
        $m = $values[$i];
        $q = $values[$i + 1] ?? null;
        if (!is_string($m) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            fail("Invalid month at index $i! Use \"YYYY-MM\" (e.g. \"2023-10\").");
        }
        if (!is_numeric($q) || (float)$q < 0) {
            fail("Invalid quantity for $m! Must be a number 0 or higher.");
        }
        if (isset($seen[$m])) {
            fail("Duplicate month $m! Each month may appear once.");
        }
        $seen[$m] = true;
        if (!empty($pairs) && $m <= $pairs[count($pairs) - 1]['month']) {
            fail("Months must be in ascending order! $m is out of order.");
        }
        if ($m >= $curMonth) {
            fail("Month $m is not closed yet! Only fully ended months can be imported.");
        }
        $pairs[] = ['month' => $m, 'qty' => round((float)$q, 2)];
    }
    if (empty($pairs)) {
        fail("Invalid file! No month/quantity pairs found.");
    }
    return $pairs;
}

// Oldest-vs-latest coverage: every month in the span must be present.
// Returns oldest/latest/complete count/missing list/zero list.
function analyzeCoverage($pairs) {
    $have = [];
    foreach ($pairs as $p) {
        $have[$p['month']] = $p['qty'];
    }
    $oldest = $pairs[0]['month'];
    $latest = end($pairs)['month'];
    $missing = [];
    $zeros = [];
    $k = $oldest;
    while ($k <= $latest) {
        if (!array_key_exists($k, $have)) {
            $missing[] = $k;
        } elseif ($have[$k] == 0) {
            $zeros[] = $k;
        }
        $k = date('Y-m', strtotime($k . '-01 +1 month'));
    }
    return [
        'oldest' => $oldest,
        'latest' => $latest,
        'complete' => count($pairs) - count($zeros),
        'missing' => $missing,
        'zeros' => $zeros,
    ];
}

// Existing Completed rows in range (re-import would double-count).
function countConflicts($pdo, $oldest, $latest) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE status = 'Completed' AND DATE(updated_at) BETWEEN :oldest AND :latest");
    $stmt->execute([':oldest' => $oldest . '-01', ':latest' => date('Y-m-t', strtotime($latest . '-01'))]);
    return (int)$stmt->fetchColumn();
}

function confirmImport($pdo) {
    $prev = $_SESSION['import_preview'] ?? null;
    if (!$prev || empty($prev['pairs'])) {
        fail("Nothing to import. Upload a file first.");
    }
    if (!empty($prev['missing'])) {
        fail("Import blocked! Missing month(s): " . implode(', ', $prev['missing']) . ". Add them to the file and upload again.");
    }
    $oldest = $prev['oldest'];
    $latest = $prev['latest'];
    $overwrite = ($_POST['confirm_overwrite'] ?? 'no') === 'yes';
    if (!$overwrite) {
        $existing = countConflicts($pdo, $oldest, $latest);
        if ($existing > 0) {
            fail("Found $existing Completed record(s) from $oldest to $latest. Re-importing would double-count. Tick overwrite on the preview to replace Historical import rows in this range.");
        }
    } else {
        $stmt = $pdo->prepare("DELETE FROM inventory WHERE status = 'Completed' AND description LIKE '%Historical import%' AND DATE(updated_at) BETWEEN :oldest AND :latest");
        $stmt->execute([':oldest' => $oldest . '-01', ':latest' => date('Y-m-t', strtotime($latest . '-01'))]);
    }

    try {
        $pdo->beginTransaction();
        $stmt_id = $pdo->prepare("SELECT prod_id FROM inventory WHERE prod_id = :id");
        $stmt_ins = $pdo->prepare("INSERT INTO inventory (prod_id, product, quantity, unit, status, description, created_at, updated_at) VALUES (:id, 'Vermicast', :quan, 'Sacks', 'Completed', 'Historical import', :created, :updated)");
        $inserted = 0;
        $totalQty = 0;
        foreach ($prev['pairs'] as $p) {
            $ts = $p['month'] . '-15 12:00:00';
            do {
                $id_num = substr(str_shuffle("0123456789"), 0, 7);
                $stmt_id->execute([':id' => $id_num]);
            } while ($stmt_id->fetchColumn());
            $stmt_ins->execute([':id' => $id_num, ':quan' => $p['qty'], ':created' => $ts, ':updated' => $ts]);
            $inserted++;
            $totalQty += $p['qty'];
        }
        $pdo->commit();
        $hist = $pdo->prepare("INSERT INTO history (user, user_role, action, product, quantity, unit) VALUES (:user, :user_role, :action, 'Vermicast', :quan, 'Sacks')");
        $hist->execute([':user' => $_SESSION['user_name'] ?? '', ':user_role' => $_SESSION['user_role'] ?? '', ':action' => "Imported $inserted month(s)", ':quan' => $totalQty]);
        unset($_SESSION['import_preview']);
        $msg = "Imported $inserted month(s): {$oldest} to {$latest}.";
        if (!empty($prev['zeros'])) {
            $msg .= " Zero month(s) counted with warning: " . implode(', ', $prev['zeros']) . ".";
        }
        header("Location: ../sales.php?import_success=" . urlencode($msg));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail("Import failed: " . $e->getMessage());
    }
}
?>
