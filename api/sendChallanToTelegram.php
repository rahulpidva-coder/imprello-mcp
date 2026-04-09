<?php

$orderId = $_GET['orderId'] ?? 0;

if (!$orderId) {
    echo json_encode(["success" => false, "message" => "Order ID missing"]);
    exit;
}

$botToken = "8768402277:AAGy54EbtJDTtxrpfFASFehk8q0HDmANAl8";
$chatId = "-5278211160";

// Step 1: generate and save PDF
file_get_contents("https://offspring.codeteam.in/mobileApp/IMP/pdf/pdf_challan.php?docType=challan&orderId={$orderId}&save=1");

// Step 2: file path
$tempFile = $_SERVER['DOCUMENT_ROOT'] . "/mobileApp/IMP/pdf/chl/challan_" . $orderId . ".pdf";

// Step 3: send to Telegram
$telegramUrl = "https://api.telegram.org/bot{$botToken}/sendDocument";

$postFields = [
    'chat_id' => $chatId,
    'document' => new CURLFile(realpath($tempFile)),
    'caption' => "Challan for Order #{$orderId}"
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $telegramUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

$response = curl_exec($ch);
curl_close($ch);

// Step 4: delete file
unlink($tempFile);

echo json_encode([
    "success" => true,
    "message" => "Challan sent",
    "telegram_response" => json_decode($response, true)
]);