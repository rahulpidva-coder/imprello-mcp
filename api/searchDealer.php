<?php
header('Content-Type: application/json');

include('../../dbConfig.php');
date_default_timezone_set('Asia/Kolkata');

$response = [
    'success' => false,
    'message' => '',
    'data' => []
];

$term = isset($_GET['term']) ? trim($_GET['term']) : '';

if ($term === '') {
    $response['message'] = 'Dealer search term is required';
    echo json_encode($response);
    exit;
}

$termEscaped = mysqli_real_escape_string($con, $term);

$qry = "SELECT merId, companyName, city, mobile
        FROM ".tblPrefix."merchant
        WHERE companyName LIKE '%{$termEscaped}%'
        ORDER BY 
            CASE 
                WHEN companyName = '{$termEscaped}' THEN 1
                WHEN companyName LIKE '{$termEscaped}%' THEN 2
                ELSE 3
            END,
            companyName
        LIMIT 10";

$rslt = mysqli_query($con, $qry);

if (!$rslt) {
    $response['message'] = 'Database error while searching dealer';
    echo json_encode($response);
    exit;
}

$dealers = [];

while ($row = mysqli_fetch_assoc($rslt)) {
    $dealers[] = [
        'merId' => (int)$row['merId'],
        'companyName' => $row['companyName'],
        'city' => $row['city'],
        'mobile' => $row['mobile']
    ];
}

$response['success'] = true;
$response['message'] = count($dealers) ? 'Dealer matches found' : 'No dealer found';
$response['data'] = $dealers;

echo json_encode($response);