<?php

require_once '../../dbConfig.php';

header('Content-Type: application/json');

$baseUrl = 'https://offspring.codeteam.in/mobileApp/IMP/dbprocess/mcp/api/';

$sql = "
    SELECT 
        id,
        file_name,
        file_path,
        file_kind,
        doc_type,
        telegram_file_id,
        telegram_chat_id,
        telegram_update_id,
        status,
        created_at
    FROM imp_tbl_telegram_que
    WHERE status = 'pending'
    ORDER BY id ASC
    LIMIT 1
";

$result = $con->query($sql);

if (!$result) {
    echo json_encode([
        'status' => 'error',
        'message' => $con->error
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($result->num_rows === 0) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No pending documents'
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$row = $result->fetch_assoc();

$row['file_url'] = $baseUrl . $row['file_path'];

echo json_encode([
    'status' => 'found',
    'data' => $row
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);