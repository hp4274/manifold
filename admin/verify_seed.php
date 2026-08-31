<?php
require_once __DIR__ . '/lib.php';
$pdo = db();

echo "Distributors: " . $pdo->query('SELECT COUNT(*) FROM distributors')->fetchColumn() . "\n";
echo "Dealers: " . $pdo->query('SELECT COUNT(*) FROM dealers')->fetchColumn() . "\n";
echo "  - Linked to Distributor: " . $pdo->query('SELECT COUNT(*) FROM dealers WHERE distributor_id IS NOT NULL')->fetchColumn() . "\n";
echo "  - Independent Dealers: " . $pdo->query('SELECT COUNT(*) FROM dealers WHERE distributor_id IS NULL')->fetchColumn() . "\n";
echo "Applications (Clients): " . $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn() . "\n";
echo "Payments: " . $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() . "\n";

echo "\n--- Connection Types Breakdown ---\n";
echo "1. Distributor -> Dealer -> Client: " . $pdo->query('SELECT COUNT(*) FROM applications WHERE dealer_id IS NOT NULL AND distributor_id IS NOT NULL')->fetchColumn() . "\n";
echo "2. Independent Dealer -> Client: " . $pdo->query('SELECT COUNT(*) FROM applications WHERE dealer_id IS NOT NULL AND distributor_id IS NULL')->fetchColumn() . "\n";
echo "3. Direct Distributor -> Client: " . $pdo->query('SELECT COUNT(*) FROM applications WHERE dealer_id IS NULL AND distributor_id IS NOT NULL')->fetchColumn() . "\n";
echo "4. Customer Referral: " . $pdo->query('SELECT COUNT(*) FROM applications WHERE referred_by_id IS NOT NULL')->fetchColumn() . "\n";
echo "5. Organic Direct: " . $pdo->query('SELECT COUNT(*) FROM applications WHERE dealer_id IS NULL AND distributor_id IS NULL AND referred_by_id IS NULL')->fetchColumn() . "\n";

echo "\n--- Status Breakdown ---\n";
$stmt = $pdo->query('SELECT status, COUNT(*) as cnt FROM applications GROUP BY status');
while ($row = $stmt->fetch()) {
    echo "  - " . $row['status'] . ": " . $row['cnt'] . "\n";
}
