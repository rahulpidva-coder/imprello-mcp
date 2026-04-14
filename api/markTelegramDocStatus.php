<?php

require_once '../../dbConfig.php';

header('Content-Type: application/json');

$id      = isset($_REQUEST['id']) ? trim($_REQUEST['id']) : '';
$status  = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : '';
$remarks = isset($_REQUEST['remarks']) ? trim($_REQUEST['remarks']) : '';

$allowedStatuses = ['pending', 'processing', 'done', 'error'];

if ($id === '' || $status === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'id and status are required'
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid status'
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($status === 'done' || $status === 'error') {
    $sql = "UPDATE imp_tbl_telegram_que SET status = ?, remarks = ?, processed_at = NOW() WHERE id = ?";
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'status' => 'error',
            'message' => $con->error
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    $stmt->bind_param("ssi", $status, $remarks, $id);
} else {
    $sql = "UPDATE imp_tbl_telegram_que SET status = ?, remarks = ? WHERE id = ?";
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'status' => 'error',
            'message' => $con->error
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    $stmt->bind_param("ssi", $status, $remarks, $id);
}

if (!$stmt->execute()) {
    echo json_encode([
        'status' => 'error',
        'message' => $stmt->error
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Document status updated',
    'id' => $id,
    'new_status' => $status
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);