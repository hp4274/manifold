<?php
/**
 * Manifold Clean Energy — Database Seeding Script.
 *
 * Populates dummy entries:
 *   - 30 Distributors (MX...)
 *   - 100 Dealers (MD...: 80 with parent distributor, 20 independent)
 *   - 500 Clients / Applications (MF... reference and referral codes)
 *     Connection types:
 *       1. Distributor -> Dealer -> Client (~220)
 *       2. Independent Dealer -> Client (~80)
 *       3. Direct Distributor -> Client (~80)
 *       4. Customer Referral Client (~60)
 *       5. Organic Direct Client (~60)
 *   - Payments & Receipts records for realistic dashboard financial metrics.
 *
 * Usage: php admin/seed.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
}

require_once __DIR__ . '/lib.php';

$pdo = db();

echo "Starting database seeding...\n";

// Disable foreign key checks for clean truncation/re-seeding
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$pdo->exec("TRUNCATE TABLE payments;");
$pdo->exec("TRUNCATE TABLE applications;");
$pdo->exec("TRUNCATE TABLE dealers;");
$pdo->exec("TRUNCATE TABLE distributors;");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

echo "Cleared existing distributors, dealers, applications, and payments.\n";

// Sample Data Providers
$cities = ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Bhavnagar', 'Jamnagar', 'Gandhinagar', 'Anand', 'Vapi', 'Junagadh', 'Mehsana', 'Navsari', 'Morbi'];
$firstNames = ['Rajesh', 'Amit', 'Priya', 'Sunil', 'Vijay', 'Deepak', 'Sanjay', 'Rahul', 'Anil', 'Ramesh', 'Manish', 'Neha', 'Pooja', 'Kavita', 'Hardik', 'Bhavin', 'Jignesh', 'Chirag', 'Tushar', 'Ketan', 'Nilesh', 'Dharmesh', 'Parag', 'Paresh', 'Gaurav', 'Nikhil', 'Kiran', 'Suresh', 'Dinesh', 'Ashok'];
$lastNames = ['Patel', 'Shah', 'Sharma', 'Joshi', 'Mehta', 'Parikh', 'Desai', 'Trivedi', 'Solanki', 'Chaudhary', 'Gondaliya', 'Prajapati', 'Vaghela', 'Gajjar', 'Bhatt', 'Pandya', 'Thakar', 'Chauhan', 'Rathod', 'Zala'];
$companySuffixes = ['Energy Solutions', 'Clean Tech', 'Renewables', 'Enterprise', 'Traders', 'Motors', 'Services', 'Agencies', 'Corporation', 'Distributors'];
$banks = ['State Bank of India', 'HDFC Bank', 'ICICI Bank', 'Bank of Baroda', 'Axis Bank', 'Kotak Mahindra Bank', 'Punjab National Bank'];
$streets = ['CG Road', 'SG Highway', 'Ashram Road', 'Ring Road', 'Station Road', 'MG Road', 'GIDC Estate', 'Main Bazaar', 'College Road', 'Vip Road'];

function getRandomElement(array $arr) {
    return $arr[array_rand($arr)];
}

function getRandomName(): string {
    global $firstNames, $lastNames;
    return getRandomElement($firstNames) . ' ' . getRandomElement($lastNames);
}

function getRandomPhone(): string {
    return '+91 ' . rand(70000, 99999) . ' ' . rand(10000, 99999);
}

function getRandomPan(): string {
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $pan = '';
    for ($i = 0; $i < 5; $i++) $pan .= $letters[rand(0, 25)];
    $pan .= sprintf('%04d', rand(1000, 9999));
    $pan .= $letters[rand(0, 25)];
    return $pan;
}

function getRandomGst(string $pan): string {
    return '24' . $pan . '1Z' . rand(1, 9);
}

function getRandomAccount(): string {
    return (string) rand(100000000000, 999999999999);
}

function getRandomIfsc(): string {
    $codes = ['SBIN000', 'HDFC000', 'ICIC000', 'BARB000', 'UTIB000'];
    return getRandomElement($codes) . rand(1000, 9999);
}

// --------------------------------------------------------------------------
// 1. Seed 30 Distributors
// --------------------------------------------------------------------------
echo "Seeding 30 Distributors...\n";
$distributorIds = [];
$distributorCodes = [];

$stmtDist = $pdo->prepare("
    INSERT INTO distributors (
        distributor_code, full_name, company, email, mobile_number, alt_mobile_number,
        address, city, state, pin_code, pan_number, gst_number,
        bank_name, bank_account, bank_ifsc, upi_id, is_active, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, 'Gujarat', ?, ?, ?,
        ?, ?, ?, ?, 1, ?, ?
    )
");

for ($i = 1; $i <= 30; $i++) {
    $code = sprintf('MX%06d', $i);
    $name = getRandomName();
    $company = explode(' ', $name)[1] . ' ' . getRandomElement($companySuffixes);
    $email = strtolower(str_replace(' ', '.', $name)) . $i . '@distributor.com';
    $phone = getRandomPhone();
    $altPhone = getRandomPhone();
    $city = getRandomElement($cities);
    $address = rand(10, 200) . ', ' . getRandomElement($streets);
    $pin = '38' . rand(1000, 9999);
    $pan = getRandomPan();
    $gst = getRandomGst($pan);
    $bank = getRandomElement($banks);
    $acc = getRandomAccount();
    $ifsc = getRandomIfsc();
    $upi = strtolower(explode(' ', $name)[0]) . $i . '@upi';
    
    $daysAgo = rand(60, 180);
    $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));

    $stmtDist->execute([
        $code, $name, $company, $email, $phone, $altPhone,
        $address, $city, $pin, $pan, $gst,
        $bank, $acc, $ifsc, $upi, $createdAt, $createdAt
    ]);

    $distId = (int) $pdo->lastInsertId();
    $distributorIds[] = $distId;
    $distributorCodes[$distId] = $code;
}

echo "Created 30 Distributors.\n";

// --------------------------------------------------------------------------
// 2. Seed 100 Dealers (80 linked to distributors, 20 independent)
// --------------------------------------------------------------------------
echo "Seeding 100 Dealers (80 linked to distributors, 20 independent)...\n";
$dealerIds = [];
$dealerCodes = [];
$dealerDistributorMap = []; // dealer_id => distributor_id (or null)

$stmtDealer = $pdo->prepare("
    INSERT INTO dealers (
        dealer_code, distributor_id, full_name, company, email, mobile_number, alt_mobile_number,
        address, city, state, pin_code, pan_number, gst_number,
        bank_name, bank_account, bank_ifsc, upi_id, is_active, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?,
        ?, ?, 'Gujarat', ?, ?, ?,
        ?, ?, ?, ?, 1, ?, ?
    )
");

for ($i = 1; $i <= 100; $i++) {
    $code = sprintf('MD%06d', $i);
    // 1..80 are linked to distributors round-robin/random; 81..100 are independent (NULL)
    $distId = ($i <= 80) ? $distributorIds[($i - 1) % count($distributorIds)] : null;
    
    $name = getRandomName();
    $company = explode(' ', $name)[1] . ' Clean Solutions';
    $email = strtolower(str_replace(' ', '.', $name)) . $i . '@dealer.com';
    $phone = getRandomPhone();
    $altPhone = getRandomPhone();
    $city = getRandomElement($cities);
    $address = rand(1, 150) . ', ' . getRandomElement($streets);
    $pin = '38' . rand(1000, 9999);
    $pan = getRandomPan();
    $gst = getRandomGst($pan);
    $bank = getRandomElement($banks);
    $acc = getRandomAccount();
    $ifsc = getRandomIfsc();
    $upi = strtolower(explode(' ', $name)[0]) . $i . '@dealerupi';
    
    $daysAgo = rand(30, 90);
    $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));

    $stmtDealer->execute([
        $code, $distId, $name, $company, $email, $phone, $altPhone,
        $address, $city, $pin, $pan, $gst,
        $bank, $acc, $ifsc, $upi, $createdAt, $createdAt
    ]);

    $dealerId = (int) $pdo->lastInsertId();
    $dealerIds[] = $dealerId;
    $dealerCodes[$dealerId] = $code;
    $dealerDistributorMap[$dealerId] = $distId;
}

echo "Created 100 Dealers.\n";

// --------------------------------------------------------------------------
// 3. Seed 500 Clients / Applications
// --------------------------------------------------------------------------
echo "Seeding 500 Clients / Applications across connection types...\n";

// Connection types breakdown:
// 1. Distributor -> Dealer -> Client: 220
// 2. Independent Dealer -> Client: 80
// 3. Direct Distributor -> Client: 80
// 4. Customer Referral Client: 60
// 5. Organic Direct Client: 60

$connectionPlans = [];
for ($c = 0; $c < 220; $c++) $connectionPlans[] = 'distributor_dealer_client';
for ($c = 0; $c < 80; $c++)  $connectionPlans[] = 'independent_dealer_client';
for ($c = 0; $c < 80; $c++)  $connectionPlans[] = 'direct_distributor_client';
for ($c = 0; $c < 60; $c++)  $connectionPlans[] = 'customer_referral';
for ($c = 0; $c < 60; $c++)  $connectionPlans[] = 'organic';

shuffle($connectionPlans);

// Status distribution breakdown:
// complete: 200, delivery_pending: 100, delivery_review: 50, booking_review: 50, booking_pending: 75, rejected: 25
$statuses = [];
for ($s = 0; $s < 200; $s++) $statuses[] = 'complete';
for ($s = 0; $s < 100; $s++) $statuses[] = 'delivery_pending';
for ($s = 0; $s < 50; $s++)  $statuses[] = 'delivery_review';
for ($s = 0; $s < 50; $s++)  $statuses[] = 'booking_review';
for ($s = 0; $s < 75; $s++)  $statuses[] = 'booking_pending';
for ($s = 0; $s < 25; $s++)  $statuses[] = 'rejected';

shuffle($statuses);

$stmtApp = $pdo->prepare("
    INSERT INTO applications (
        product, status, reference_code, referral_code, referred_by_code, referred_by_id,
        dealer_id, dealer_commission, distributor_id, distributor_commission,
        referral_reward, referral_reward_status,
        full_name, date_of_birth, nationality, gender, occupation, mobile_number, alt_mobile_number, email,
        id_number, house_number, street, city, state, country, pin_code,
        property_type, ownership_status, household_members, existing_fuel, units_required, intended_usage,
        declaration_accepted, testimonial_consent, terms_accepted,
        payment_amount, booking_amount, delivery_amount,
        booking_paid_at, delivery_paid_at, payment_reference, confirmed_at, completed_at,
        ip_address, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        ?, ?, 'Indian', ?, ?, ?, ?, ?,
        ?, ?, ?, ?, 'Gujarat', 'India', ?,
        'House', 'Owned', ?, 'LPG', 1, 'Domestic',
        1, 1, 1,
        ?, ?, ?,
        ?, ?, ?, ?, ?,
        '127.0.0.1', ?, ?
    )
");

$stmtPayment = $pdo->prepare("
    INSERT INTO payments (
        application_id, stage, amount, reference, status, receipt_no, reject_reason, uploaded_at, decided_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
");

$insertedApps = []; // Stores inserted apps for customer referrals

// Separate dealer lists for convenience
$linkedDealerIds = array_slice($dealerIds, 0, 80);
$indepDealerIds  = array_slice($dealerIds, 80, 20);

for ($i = 1; $i <= 500; $i++) {
    $connType = $connectionPlans[$i - 1];
    $status   = $statuses[$i - 1];
    $product  = ($i % 2 === 0) ? 'tuktuk' : 'stove';
    
    $plan = payment_plan($product);
    $bookingAmount  = (float) $plan['booking'];
    $deliveryAmount = (float) $plan['delivery'];
    $saleValue      = $bookingAmount + $deliveryAmount;
    
    $dealerId              = null;
    $distributorId         = null;
    $dealerCommission      = 0.00;
    $distributorCommission = 0.00;
    $referredByCode        = null;
    $referredById          = null;
    $referralReward        = 0.00;
    $referralRewardStatus  = 'none';

    if ($connType === 'distributor_dealer_client') {
        $dealerId              = getRandomElement($linkedDealerIds);
        $distributorId         = $dealerDistributorMap[$dealerId];
        $referredByCode        = $dealerCodes[$dealerId];
        $dealerCommission      = round($saleValue * 0.15, 2);
        $distributorCommission = round($saleValue * 0.05, 2);
    } elseif ($connType === 'independent_dealer_client') {
        $dealerId              = getRandomElement($indepDealerIds);
        $distributorId         = null;
        $referredByCode        = $dealerCodes[$dealerId];
        $dealerCommission      = round($saleValue * 0.15, 2);
        $distributorCommission = 0.00;
    } elseif ($connType === 'direct_distributor_client') {
        $dealerId              = null;
        $distributorId         = getRandomElement($distributorIds);
        $referredByCode        = $distributorCodes[$distributorId];
        $dealerCommission      = 0.00;
        $distributorCommission = round($saleValue * 0.15, 2);
    } elseif ($connType === 'customer_referral' && !empty($insertedApps)) {
        $referrer              = getRandomElement($insertedApps);
        $referredByCode        = $referrer['referral_code'];
        $referredById          = $referrer['id'];
        $referralReward        = 500.00;
        $referralRewardStatus  = ($status === 'complete') ? 'sent' : 'pending';
    } else {
        // Organic Direct Client
        // (Default values remain null / 0.00)
    }

    $refCode      = sprintf('MF-%08d', $i);
    $referralCode = sprintf('MF%06d', $i);

    $name       = getRandomName();
    $dob        = date('Y-m-d', strtotime('-' . rand(22, 60) . ' years'));
    $gender     = (rand(0, 1) === 1) ? 'Male' : 'Female';
    $occupation = getRandomElement(['Business', 'Salaried', 'Self-Employed', 'Farmer', 'Teacher', 'Engineer']);
    $phone      = getRandomPhone();
    $altPhone   = getRandomPhone();
    $email      = strtolower(str_replace(' ', '.', $name)) . $i . '@client.com';
    $idNum      = rand(1000, 9999) . ' ' . rand(1000, 9999) . ' ' . rand(1000, 9999);
    $houseNo    = (string) rand(1, 100);
    $street     = getRandomElement($streets);
    $city       = getRandomElement($cities);
    $pin        = '38' . rand(1000, 9999);
    $members    = rand(2, 6);

    $daysAgo   = rand(1, 60);
    $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));

    $bookingPaidAt  = null;
    $deliveryPaidAt = null;
    $confirmedAt    = null;
    $completedAt    = null;
    $paymentRef     = 'UPI/' . rand(100000000000, 999999999999);

    if (in_array($status, ['booking_review', 'delivery_pending', 'delivery_review', 'complete'], true)) {
        $bookingPaidAt = date('Y-m-d H:i:s', strtotime($createdAt . ' + 2 hours'));
    }
    if (in_array($status, ['delivery_pending', 'delivery_review', 'complete'], true)) {
        $confirmedAt = date('Y-m-d H:i:s', strtotime($createdAt . ' + 1 day'));
    }
    if (in_array($status, ['delivery_review', 'complete'], true)) {
        $deliveryPaidAt = date('Y-m-d H:i:s', strtotime($createdAt . ' + 3 days'));
    }
    if ($status === 'complete') {
        $completedAt = date('Y-m-d H:i:s', strtotime($createdAt . ' + 4 days'));
    }

    $stmtApp->execute([
        $product, $status, $refCode, $referralCode, $referredByCode, $referredById,
        $dealerId, $dealerCommission, $distributorId, $distributorCommission,
        $referralReward, $referralRewardStatus,
        $name, $dob, $gender, $occupation, $phone, $altPhone, $email,
        $idNum, $houseNo, $street, $city, $pin,
        $members,
        $bookingAmount, $bookingAmount, $deliveryAmount,
        $bookingPaidAt, $deliveryPaidAt, $paymentRef, $confirmedAt, $completedAt,
        $createdAt, $createdAt
    ]);

    $appId = (int) $pdo->lastInsertId();
    $insertedApps[] = ['id' => $appId, 'referral_code' => $referralCode];

    // Seed matching payments records
    if ($status === 'booking_pending') {
        // No payment record yet
    } elseif ($status === 'booking_review') {
        $stmtPayment->execute([
            $appId, 'booking', $bookingAmount, $paymentRef, 'pending', null, null, $bookingPaidAt, null
        ]);
    } elseif ($status === 'delivery_pending') {
        $stmtPayment->execute([
            $appId, 'booking', $bookingAmount, $paymentRef, 'verified', 'RCP-B-' . sprintf('%06d', $appId), null, $bookingPaidAt, $confirmedAt
        ]);
    } elseif ($status === 'delivery_review') {
        $stmtPayment->execute([
            $appId, 'booking', $bookingAmount, $paymentRef, 'verified', 'RCP-B-' . sprintf('%06d', $appId), null, $bookingPaidAt, $confirmedAt
        ]);
        $stmtPayment->execute([
            $appId, 'delivery', $deliveryAmount, 'UPI/D' . rand(100000000000, 999999999999), 'pending', null, null, $deliveryPaidAt, null
        ]);
    } elseif ($status === 'complete') {
        $stmtPayment->execute([
            $appId, 'booking', $bookingAmount, $paymentRef, 'verified', 'RCP-B-' . sprintf('%06d', $appId), null, $bookingPaidAt, $confirmedAt
        ]);
        $stmtPayment->execute([
            $appId, 'delivery', $deliveryAmount, 'UPI/D' . rand(100000000000, 999999999999), 'verified', 'RCP-D-' . sprintf('%06d', $appId), null, $deliveryPaidAt, $completedAt
        ]);
    } elseif ($status === 'rejected') {
        $stmtPayment->execute([
            $appId, 'booking', $bookingAmount, $paymentRef, 'rejected', null, 'Invalid transaction reference', $createdAt, $createdAt
        ]);
    }
}

echo "Created 500 Clients / Applications with payments.\n";
echo "Database seeding completed successfully!\n";
