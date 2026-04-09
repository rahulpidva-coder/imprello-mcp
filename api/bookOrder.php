<?php
header('Content-Type: application/json');

include('../../dbConfig.php');
date_default_timezone_set('Asia/Kolkata');

$response = [
    'success' => false,
    'message' => '',
    'data' => []
];

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    $response['message'] = 'Invalid JSON input';
    echo json_encode($response);
    exit;
}

$merId = isset($input['merId']) ? (int)$input['merId'] : 0;
$items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
$notes = isset($input['notes']) ? trim($input['notes']) : '';

if ($merId <= 0) {
    $response['message'] = 'Valid merId is required';
    echo json_encode($response);
    exit;
}

if (count($items) === 0) {
    $response['message'] = 'At least one item is required';
    echo json_encode($response);
    exit;
}

$dealerQry = "SELECT companyName FROM ".tblPrefix."merchant WHERE merId = {$merId} LIMIT 1";
$dealerRslt = mysqli_query($con, $dealerQry);

if (!$dealerRslt || mysqli_num_rows($dealerRslt) === 0) {
    $response['message'] = 'Dealer not found';
    echo json_encode($response);
    exit;
}

$dealerRow = mysqli_fetch_assoc($dealerRslt);
$dealerName = mysqli_real_escape_string($con, $dealerRow['companyName']);

$idQry = "SELECT idNumber FROM ".tblPrefix."iddetails WHERE idType = 'dealerOrder' LIMIT 1";
$idRslt = mysqli_query($con, $idQry);

if (!$idRslt || mysqli_num_rows($idRslt) === 0) {
    $response['message'] = 'dealerOrder counter not found in iddetails';
    echo json_encode($response);
    exit;
}

$idRow = mysqli_fetch_assoc($idRslt);
$newOrderId = ((int)$idRow['idNumber']) + 1;

$orderDate = date('Y-m-d');
$createdOn = date('Y-m-d H:i:s');
$orderType = 1;
$orderStatus = 0;
$orderTotal = 0;
$exe = 'mcp';

mysqli_begin_transaction($con);

try {
    $summaryQry = "INSERT INTO ".tblPrefix."tempordersummary
        (orderId, orderDate, merId, dealerName, orderTotal, orderStatus, orderType, notes, createdOn, exe)
        VALUES
        ({$newOrderId}, '{$orderDate}', {$merId}, '{$dealerName}', {$orderTotal}, {$orderStatus}, {$orderType}, '".mysqli_real_escape_string($con, $notes)."', '{$createdOn}', '{$exe}')";

    if (!mysqli_query($con, $summaryQry)) {
        throw new Exception('Failed to insert order summary');
    }

    foreach ($items as $item) {
        $pdctCode = isset($item['pdctCode']) ? trim($item['pdctCode']) : '';
        $qty = isset($item['qty']) ? (int)$item['qty'] : 0;

        if ($pdctCode === '' || $qty <= 0) {
            throw new Exception('Each item must have valid pdctCode and qty');
        }

        $pdctCodeEscaped = mysqli_real_escape_string($con, $pdctCode);

        $checkQry = "SELECT ProductCode FROM ".tblPrefix."productlist WHERE ProductCode = '{$pdctCodeEscaped}' LIMIT 1";
        $checkRslt = mysqli_query($con, $checkQry);

        if (!$checkRslt || mysqli_num_rows($checkRslt) === 0) {
            throw new Exception("Invalid product code: {$pdctCode}");
        }

        $detailQry = "INSERT INTO ".tblPrefix."temporderdetails
            (orderId, batchNo, pdctCode, basicPrice, tax, vatAmount, Qty, amount, status, createdOn)
            VALUES
            ({$newOrderId}, '', '{$pdctCodeEscaped}', 0, 0, 0, {$qty}, 0, 0, '{$createdOn}')";

        if (!mysqli_query($con, $detailQry)) {
            throw new Exception("Failed to insert item: {$pdctCode}");
        }
    }

    $updateIdQry = "UPDATE ".tblPrefix."iddetails
                    SET idNumber = {$newOrderId}
                    WHERE idType = 'dealerOrder'";

    if (!mysqli_query($con, $updateIdQry)) {
        throw new Exception('Failed to update dealerOrder counter');
    }

    mysqli_commit($con);

    $response['success'] = true;
    $response['message'] = 'Order booked successfully';
    $response['data'] = [
        'orderId' => $newOrderId,
        'merId' => $merId,
        'dealerName' => $dealerRow['companyName'],
        'orderType' => $orderType,
        'itemCount' => count($items),
        'notes' => $notes
    ];
} catch (Exception $e) {
    mysqli_rollback($con);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);