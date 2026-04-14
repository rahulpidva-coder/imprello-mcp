<?php

$botToken = '8768402277:AAFzd4YKnVb28piTBkD6OtaafpCflZWd74Y';
$saveDir  = __DIR__ . '/uploads/';

require_once '../../dbConfig.php';

if (!is_dir($saveDir)) {
    mkdir($saveDir, 0777, true);
}

$data = json_decode(file_get_contents('php://input'), true);

file_put_contents(
    __DIR__ . '/telegram_log.txt',
    json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL,
    FILE_APPEND
);

if (!isset($data['message'])) {
    http_response_code(200);
    echo 'No message';
    exit;
}

$message  = $data['message'];
$chatId   = isset($message['chat']['id']) ? (string)$message['chat']['id'] : '';
$updateId = isset($data['update_id']) ? (string)$data['update_id'] : '';

$fileId   = '';
$fileName = '';
$fileKind = '';

// ================== PDF ==================
if (isset($message['document'])) {
    $document = $message['document'];
    $fileId   = $document['file_id'] ?? '';
    $fileName = $document['file_name'] ?? ('tg_' . time() . '.pdf');

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        http_response_code(200);
        echo 'Not PDF';
        exit;
    }

    $fileKind = 'pdf';
}

// ================== IMAGE ==================
elseif (isset($message['photo'])) {
    $photos   = $message['photo'];
    $last     = end($photos); // highest resolution
    $fileId   = $last['file_id'] ?? '';
    $fileName = 'img_' . time() . '.jpg';
    $fileKind = 'image';
}

// ================== IGNORE ==================
else {
    http_response_code(200);
    echo 'Ignored';
    exit;
}

// ================== GET FILE ==================
$getFileUrl = "https://api.telegram.org/bot{$botToken}/getFile?file_id=" . urlencode($fileId);
$getFileRes = @file_get_contents($getFileUrl);

if ($getFileRes === false) {
    file_put_contents(__DIR__ . '/pdf_only.txt', date('Y-m-d H:i:s') . " | getFile API failed" . PHP_EOL, FILE_APPEND);
    exit;
}

$fileInfo = json_decode($getFileRes, true);

if (empty($fileInfo['ok']) || empty($fileInfo['result']['file_path'])) {
    file_put_contents(__DIR__ . '/pdf_only.txt', date('Y-m-d H:i:s') . " | getFile failed" . PHP_EOL, FILE_APPEND);
    exit;
}

$filePath    = $fileInfo['result']['file_path'];
$downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
$content     = @file_get_contents($downloadUrl);

if ($content === false) {
    file_put_contents(__DIR__ . '/pdf_only.txt', date('Y-m-d H:i:s') . " | download failed" . PHP_EOL, FILE_APPEND);
    exit;
}

// ================== SAVE ==================
$safeName  = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
$finalName = time() . '_' . $safeName;
$fullPath  = $saveDir . $finalName;

if (file_put_contents($fullPath, $content) === false) {
    file_put_contents(__DIR__ . '/pdf_only.txt', date('Y-m-d H:i:s') . " | save failed" . PHP_EOL, FILE_APPEND);
    exit;
}

$relativePath = 'uploads/' . $finalName;

// ================== INSERT ==================
$stmt = $con->prepare("
    INSERT INTO imp_tbl_telegram_que
    (file_name, file_path, telegram_file_id, telegram_chat_id, telegram_update_id, file_kind, doc_type, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 'unknown', 'pending', NOW())
");

if (!$stmt) {
    file_put_contents(__DIR__ . '/pdf_only.txt', date('Y-m-d H:i:s') . " | DB prepare failed | " . $con->error . PHP_EOL, FILE_APPEND);
    exit;
}

$stmt->bind_param("ssssss", $fileName, $relativePath, $fileId, $chatId, $updateId, $fileKind);

if (!$stmt->execute()) {
    file_put_contents(__DIR__ . '/pdf_only.txt', date('Y-m-d H:i:s') . " | DB execute failed | " . $stmt->error . PHP_EOL, FILE_APPEND);
    exit;
}

file_put_contents(
    __DIR__ . '/pdf_only.txt',
    date('Y-m-d H:i:s') . " | saved {$fileKind} | {$fileName}" . PHP_EOL,
    FILE_APPEND
);

http_response_code(200);
echo 'OK';