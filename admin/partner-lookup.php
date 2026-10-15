<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

$code = normalise_referral_code((string) ($_GET['code'] ?? ''));

if ($code === '') {
    echo json_encode(['ok' => false, 'message' => 'Enter a partner code.']);
    exit;
}

$dealer = dealer_for_code($code);
$distributor = $dealer ? distributor_for_dealer($dealer) : distributor_for_code($code);

if (!$dealer && !$distributor) {
    $zonalDirect = zonal_cf_for_code($code);
    if ($zonalDirect) {
        $principalDirect = null;
        if (!empty($zonalDirect['principal_cf_id'])) {
            $p = principal_cf_by_id((int) $zonalDirect['principal_cf_id']);
            if ($p) {
                $principalDirect = [
                    'id'   => (int) $p['id'],
                    'code' => (string) ($p['principal_code'] ?? ''),
                    'name' => (string) ($p['full_name'] ?? ''),
                ];
            }
        }
        echo json_encode([
            'ok' => true,
            'partner' => [
                'type' => 'zonal_cf',
                'code' => (string) ($zonalDirect['zonal_code'] ?? ''),
                'name' => (string) ($zonalDirect['full_name'] ?? ''),
            ],
            'distributor' => null,
            'zonal_cf' => [
                'code' => (string) ($zonalDirect['zonal_code'] ?? ''),
                'name' => (string) ($zonalDirect['full_name'] ?? ''),
            ],
            'principal_cf' => $principalDirect,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'No active dealer, distributor, or zonal hub matches this code.']);
    exit;
}

$zonal = null;
$principal = null;

if ($distributor && !empty($distributor['zonal_cf_id'])) {
    $stmt = db()->prepare(
        "SELECT z.*, p.id AS principal_id, p.principal_code, p.full_name AS principal_name
           FROM zonal_cf z
           LEFT JOIN principal_cf p ON p.id = z.principal_cf_id
          WHERE z.id = ? AND z.is_active = 1 AND z.approval_status = 'approved'
          LIMIT 1"
    );
    $stmt->execute([(int) $distributor['zonal_cf_id']]);
    $zonal = $stmt->fetch() ?: null;

    if ($zonal && !empty($zonal['principal_id'])) {
        $principal = [
            'id' => (int) $zonal['principal_id'],
            'code' => (string) ($zonal['principal_code'] ?? ''),
            'name' => (string) ($zonal['principal_name'] ?? ''),
        ];
    }
}

echo json_encode([
    'ok' => true,
    'partner' => $dealer ? [
        'type' => 'dealer',
        'code' => (string) ($dealer['dealer_code'] ?? ''),
        'name' => (string) ($dealer['full_name'] ?? ''),
    ] : [
        'type' => 'distributor',
        'code' => (string) ($distributor['distributor_code'] ?? ''),
        'name' => (string) ($distributor['full_name'] ?? ''),
    ],
    'distributor' => $distributor ? [
        'code' => (string) ($distributor['distributor_code'] ?? ''),
        'name' => (string) ($distributor['full_name'] ?? ''),
    ] : null,
    'zonal_cf' => $zonal ? [
        'code' => (string) ($zonal['zonal_code'] ?? ''),
        'name' => (string) ($zonal['full_name'] ?? ''),
    ] : null,
    'principal_cf' => $principal,
]);
