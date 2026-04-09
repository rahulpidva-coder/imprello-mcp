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
    $response['message'] = 'Product search term is required';
    echo json_encode($response);
    exit;
}

$termEscaped = mysqli_real_escape_string($con, $term);

$qry = "SELECT prodId, ProductCode, ProductName, Basic
        FROM ".tblPrefix."productlist
        WHERE ProductName LIKE '%{$termEscaped}%'
           OR ProductCode LIKE '%{$termEscaped}%'
        ORDER BY 
            CASE 
                WHEN ProductName = '{$termEscaped}' THEN 1
                WHEN ProductName LIKE '{$termEscaped}%' THEN 2
                ELSE 3
            END,
            ProductName
        LIMIT 10";

$rslt = mysqli_query($con, $qry);

if (!$rslt) {
    $response['message'] = 'Database error while searching product';
    echo json_encode($response);
    exit;
}

$products = [];

while ($row = mysqli_fetch_assoc($rslt)) {
    $products[] = [
        'prodId' => (int)$row['prodId'],
        'ProductCode' => $row['ProductCode'],
        'ProductName' => $row['ProductName'],
        'basicPrice' => (float)$row['Basic']
    ];
}

$response['success'] = true;
$response['message'] = count($products) ? 'Products found' : 'No product found';
$response['data'] = $products;

echo json_encode($response);