<?php
header('Content-Type: application/json; charset=utf-8');

include(__DIR__ . '/../../dbFunctions.php');

date_default_timezone_set('Asia/Kolkata');

function sendJson($arr, $code = 200){
	http_response_code($code);
	echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function req($key, $default = ''){
	if (isset($_POST[$key])) return $_POST[$key];
	if (isset($_GET[$key])) return $_GET[$key];
	return $default;
}

function getImpRootUrl(){
	$https = (
		(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
		(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
	);

	$scheme = $https ? 'https' : 'http';
	$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

	$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
	$impPath = dirname(dirname(dirname(dirname($scriptName))));

	return rtrim($scheme.'://'.$host.$impPath, '/');
}

// -------------------- CONFIG --------------------
$BOT_TOKEN = "8768402277:AAFzd4YKnVb28piTBkD6OtaafpCflZWd74Y";
$CHAT_ID = "7946466975";
// ------------------------------------------------

$orderId = (int)req('orderId', 0);
$invNo   = trim((string)req('invNo', ''));

if ($orderId <= 0 && $invNo == ''){
	sendJson([
		'success' => false,
		'code' => 'MISSING_INPUT',
		'message' => 'Pass orderId or invNo'
	], 400);
}

// -------------------- Resolve order/invoice --------------------
if ($orderId > 0 && $invNo == ''){
	$qry = "SELECT orderId, invNo, dealerName 
			FROM ".tblPrefix."ordersummary 
			WHERE orderId = ".$orderId." 
			LIMIT 1";
	$rslt = mysqli_query($con, $qry);

	if (!$rslt || mysqli_num_rows($rslt) == 0){
		sendJson([
			'success' => false,
			'code' => 'ORDER_NOT_FOUND',
			'message' => 'Order not found'
		], 404);
	}

	$row = mysqli_fetch_assoc($rslt);
	$invNo = trim((string)$row['invNo']);
	$dealerName = $row['dealerName'];

	if ($invNo == '' || $invNo == '0'){
		sendJson([
			'success' => false,
			'code' => 'INVOICE_NOT_YET_RAISED',
			'message' => 'Invoice not yet raised for this order',
			'orderId' => $orderId
		], 200);
	}
}
else if ($invNo != '' && $orderId <= 0){
	$qry = "SELECT orderId, invNo, dealerName 
			FROM ".tblPrefix."ordersummary 
			WHERE invNo = '".mysqli_real_escape_string($con, $invNo)."' 
			LIMIT 1";
	$rslt = mysqli_query($con, $qry);

	if (!$rslt || mysqli_num_rows($rslt) == 0){
		sendJson([
			'success' => false,
			'code' => 'INVOICE_NOT_FOUND',
			'message' => 'Invoice not found'
		], 404);
	}

	$row = mysqli_fetch_assoc($rslt);
	$orderId = (int)$row['orderId'];
	$dealerName = $row['dealerName'];
}
else{
	$qry = "SELECT orderId, invNo, dealerName 
			FROM ".tblPrefix."ordersummary 
			WHERE orderId = ".$orderId." 
			LIMIT 1";
	$rslt = mysqli_query($con, $qry);

	if (!$rslt || mysqli_num_rows($rslt) == 0){
		sendJson([
			'success' => false,
			'code' => 'ORDER_NOT_FOUND',
			'message' => 'Order not found'
		], 404);
	}

	$row = mysqli_fetch_assoc($rslt);
	$dbInvNo = trim((string)$row['invNo']);
	$dealerName = $row['dealerName'];

	if ($dbInvNo == '' || $dbInvNo == '0'){
		sendJson([
			'success' => false,
			'code' => 'INVOICE_NOT_YET_RAISED',
			'message' => 'Invoice not yet raised for this order',
			'orderId' => $orderId
		], 200);
	}

	if ($invNo == ''){
		$invNo = $dbInvNo;
	}
}

// -------------------- PDF path --------------------
$pdfPath = $_SERVER['DOCUMENT_ROOT'] . "/mobileApp/IMP/pdf/inv/" . $invNo . ".pdf";

// -------------------- Generate PDF if missing --------------------
if (!file_exists($pdfPath)){
	$genUrl = getImpRootUrl() . '/pdf/genInvoice.php';

	$ch = curl_init($genUrl);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query(['orderId' => $orderId]),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_CONNECTTIMEOUT => 15
	]);

	$genResponse = curl_exec($ch);
	$genError = curl_error($ch);
	$genCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($genResponse === false || $genCode < 200 || $genCode >= 300){
		sendJson([
			'success' => false,
			'code' => 'PDF_GENERATION_FAILED',
			'message' => 'Failed to generate invoice PDF',
			'orderId' => $orderId,
			'invNo' => $invNo,
			'error' => $genError,
			'response' => $genResponse
		], 500);
	}

	clearstatcache();

	if (!file_exists($pdfPath)){
		sendJson([
			'success' => false,
			'code' => 'PDF_NOT_FOUND',
			'message' => 'Invoice exists but PDF file still not found after generation',
			'orderId' => $orderId,
			'invNo' => $invNo,
			'path' => $pdfPath
		], 404);
	}
}

// -------------------- Send to Telegram --------------------
$url = "https://api.telegram.org/bot".$BOT_TOKEN."/sendDocument";

$postFields = [
	'chat_id' => $CHAT_ID,
	'document' => new CURLFile($pdfPath),
	'caption' => "Invoice\nOrder ID: ".$orderId."\nInvoice No: ".$invNo."\nParty: ".$dealerName
];

$ch = curl_init();
curl_setopt_array($ch, [
	CURLOPT_URL => $url,
	CURLOPT_POST => true,
	CURLOPT_POSTFIELDS => $postFields,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($response === false){
	sendJson([
		'success' => false,
		'code' => 'TELEGRAM_SEND_FAILED',
		'message' => 'Telegram send failed',
		'error' => $error
	], 500);
}

sendJson([
	'success' => true,
	'code' => 'INVOICE_SENT',
	'message' => 'Invoice sent to Telegram',
	'orderId' => $orderId,
	'invNo' => $invNo
]);