<?php
// Daily stock snapshot ledger (zero-setup trend history).
//
// Every mutation of the single-row `total` ledger plus each dashboard visit
// calls recordStockSnapshot(), which upserts today's row with the current
// total. The dashboard trend chart then plots RECORDED values instead of
// guessing past balances. A day with no snapshot means no ledger mutation
// was possible without a logged-in writer (all writers snapshot), so
// readers carry the last known value forward; days before the first
// snapshot stay missing and render as a gap.
//
// The table may not exist on old DBs yet: failures are swallowed so a
// missing table can never fatal a stock operation. Run the CREATE from
// README.txt / database_query once in phpMyAdmin.
function recordStockSnapshot($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT total_stock FROM total LIMIT 1");
        $stmt->execute();
        $total = round((float)($stmt->fetchColumn() ?? 0), 2);
        $up = $pdo->prepare("INSERT INTO stock_daily (day, total_stock) VALUES (:day, :total) ON DUPLICATE KEY UPDATE total_stock = :total2");
        $up->execute([':day' => date('Y-m-d'), ':total' => $total, ':total2' => $total]);
    } catch (Exception $e) {
        // No stock_daily table yet - nothing to record.
    }
}
?>
