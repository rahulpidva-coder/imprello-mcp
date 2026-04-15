<?php
header('Content-Type: application/json; charset=utf-8');

include(__DIR__ . '/../../dbFunctions.php');

date_default_timezone_set('Asia/Kolkata');

function sendJson($arr, $code = 200){
	http_response_code($code);
	echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function getReq($key, $default = ''){
	if (isset($_POST[$key])) return $_POST[$key];
	if (isset($_GET[$key])) return $_GET[$key];
	return $default;
}

function esc($val){
	global $con;
	return mysqli_real_escape_string($con, trim($val));
}

$dealerId  = (int)getReq('dealerId', 0);
$prodId    = (int)getReq('prodId', 0);
$pdctCode  = trim(getReq('pdctCode', ''));
$price     = (float)getReq('price', 0);
$createdBy = trim(getReq('createdBy', 'mcp_auto'));
$remarks   = trim(getReq('remarks', 'Updated via MCP'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST'){
	sendJson(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Use POST request only.'], 405);
}

if ($dealerId <= 0){
	sendJson(['success' => false, 'code' => 'INVALID_DEALER_ID', 'message' => 'dealerId required.'], 400);
}

if ($price <= 0){
	sendJson(['success' => false, 'code' => 'INVALID_PRICE', 'message' => 'price must be greater than zero.'], 400);
}

// If prodId missing but pdctCode given, derive prodId
if ($prodId <= 0 && $pdctCode != ''){
	$qry = "SELECT prodId, ProductCode FROM ".tblPrefix."productlist WHERE ProductCode = '".esc($pdctCode)."' LIMIT 1";
	$rslt = mysqli_query($con, $qry);
	if ($rslt && mysqli_num_rows($rslt) > 0){
		$row = mysqli_fetch_assoc($rslt);
		$prodId = (int)$row['prodId'];
		$pdctCode = $row['ProductCode'];
	}
}

if ($prodId <= 0){
	sendJson(['success' => false, 'code' => 'INVALID_PRODUCT', 'message' => 'prodId or valid pdctCode required.'], 400);
}

// If pdctCode missing but prodId given, derive pdctCode
if ($pdctCode == ''){
	$qry = "SELECT ProductCode FROM ".tblPrefix."productlist WHERE prodId = ".$prodId." LIMIT 1";
	$rslt = mysqli_query($con, $qry);
	if ($rslt && mysqli_num_rows($rslt) > 0){
		$row = mysqli_fetch_assoc($rslt);
		$pdctCode = $row['ProductCode'];
	}
}

if ($pdctCode == ''){
	sendJson(['success' => false, 'code' => 'PRODUCT_CODE_NOT_FOUND', 'message' => 'Unable to derive pdctCode from prodId.'], 400);
}

// validate dealer
$qry = "SELECT merId, companyName FROM ".tblPrefix."merchant WHERE merId = ".$dealerId." LIMIT 1";
$rslt = mysqli_query($con, $qry);
if (!$rslt || mysqli_num_rows($rslt) == 0){
	sendJson(['success' => false, 'code' => 'DEALER_NOT_FOUND', 'message' => 'Dealer not found.'], 404);
}
$dealer = mysqli_fetch_assoc($rslt);

// validate product
$qry = "SELECT prodId, ProductCode, ProductName, TaxCateg FROM ".tblPrefix."productlist WHERE prodId = ".$prodId." LIMIT 1";
$rslt = mysqli_query($con, $qry);
if (!$rslt || mysqli_num_rows($rslt) == 0){
	sendJson(['success' => false, 'code' => 'PRODUCT_NOT_FOUND', 'message' => 'Product not found.'], 404);
}
$product = mysqli_fetch_assoc($rslt);

// derive VAT amount from tax%
$vat = 0;
if (!empty($product['TaxCateg'])){
	$qry = "SELECT tax FROM ".tblPrefix."taxcategory WHERE TaxCateg = '".esc($product['TaxCateg'])."' LIMIT 1";
	$rslt = mysqli_query($con, $qry);
	if ($rslt && mysqli_num_rows($rslt) > 0){
		$taxRow = mysqli_fetch_assoc($rslt);
		$taxPer = (float)$taxRow['tax'];
		$vat = round($price * $taxPer / 100, 2);
	}
}

mysqli_begin_transaction($con);

// active row check
$qry = "SELECT priceId, basic 
		FROM ".tblPrefix."dealerpricing 
		WHERE merId = ".$dealerId." 
		AND prodId = ".$prodId." 
		AND isActive = 1 
		ORDER BY effectiveFrom DESC, priceId DESC 
		LIMIT 1";
$rslt = mysqli_query($con, $qry);

if (!$rslt){
	mysqli_rollback($con);
	sendJson(['success' => false, 'code' => 'QUERY_FAILED', 'message' => 'Unable to fetch current dealer price.'], 500);
}

$oldPrice = null;
$oldPriceId = 0;

if (mysqli_num_rows($rslt) > 0){
	$row = mysqli_fetch_assoc($rslt);
	$oldPrice = (float)$row['basic'];
	$oldPriceId = (int)$row['priceId'];

	if (round($oldPrice,2) == round($price,2)){
		mysqli_commit($con);
		sendJson([
			'success' => true,
			'code' => 'PRICE_ALREADY_ACTIVE',
			'message' => 'Same price already active.',
			'dealerId' => $dealerId,
			'dealerName' => $dealer['companyName'],
			'prodId' => $prodId,
			'pdctCode' => $pdctCode,
			'productName' => $product['ProductName'],
			'price' => round($price,2)
		]);
	}
}

// close old active
if ($oldPriceId > 0){
	$qry = "UPDATE ".tblPrefix."dealerpricing
			SET isActive = 0,
				effectiveTo = '".$curDate."',
				updatedOn = '".$curDate."'
			WHERE priceId = ".$oldPriceId;
	if (!mysqli_query($con, $qry)){
		mysqli_rollback($con);
		sendJson(['success' => false, 'code' => 'CLOSE_OLD_FAILED', 'message' => 'Unable to close old active price.'], 500);
	}
}

// insert new active
$qry = "INSERT INTO ".tblPrefix."dealerpricing
		(merId, prodId, pdctCode, basic, vat, effectiveFrom, effectiveTo, isActive, createdOn, updatedOn, createdBy, remarks)
		VALUES
		(".$dealerId.",
		 ".$prodId.",
		 '".esc($pdctCode)."',
		 ".round($price,2).",
		 ".round($vat,2).",
		 '".$curDate."',
		 NULL,
		 1,
		 '".$curDate."',
		 '".$curDate."',
		 '".esc($createdBy)."',
		 '".esc($remarks)."')";
if (!mysqli_query($con, $qry)){
	mysqli_rollback($con);
	sendJson(['success' => false, 'code' => 'INSERT_FAILED', 'message' => 'Unable to insert new dealer price.'], 500);
}

$newPriceId = mysqli_insert_id($con);

mysqli_commit($con);

sendJson([
	'success' => true,
	'code' => 'PRICE_UPSERTED',
	'message' => 'Dealer price saved successfully.',
	'dealerId' => $dealerId,
	'dealerName' => $dealer['companyName'],
	'prodId' => $prodId,
	'pdctCode' => $pdctCode,
	'productName' => $product['ProductName'],
	'oldPrice' => $oldPrice,
	'newPrice' => round($price,2),
	'vat' => round($vat,2),
	'priceId' => $newPriceId
]);