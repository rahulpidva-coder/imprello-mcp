<?php
header('Content-Type: application/json; charset=utf-8');

include(__DIR__ . '/../../dbFunctions.php');

date_default_timezone_set('Asia/Kolkata');

function sendJson(array $data, int $statusCode = 200): void{
	http_response_code($statusCode);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function req(string $key, $default = null){
	if (isset($_POST[$key])) return $_POST[$key];
	if (isset($_GET[$key])) return $_GET[$key];
	return $default;
}

function getImpRootUrl(): string{
	$https = (
		(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
		(isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
	);

	$scheme = $https ? 'https' : 'http';
	$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

	$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
	$impPath = dirname(dirname(dirname(dirname($scriptName))));

	return rtrim($scheme . '://' . $host . $impPath, '/');
}

function getActiveDealerPrice(int $merId, int $prodId): ?float{
	global $con;

	$qry = "SELECT basic
			FROM ".tblPrefix."dealerpricing
			WHERE merId = ".$merId."
			  AND prodId = ".$prodId."
			  AND isActive = 1
			ORDER BY effectiveFrom DESC, priceId DESC
			LIMIT 1";

	$rs = mysqli_query($con, $qry);
	if (!$rs) throw new Exception("Dealer price fetch failed for prodId ".$prodId);

	if (mysqli_num_rows($rs) == 0){
		mysqli_free_result($rs);
		return null;
	}

	$row = mysqli_fetch_assoc($rs);
	mysqli_free_result($rs);

	return (float)$row['basic'];
}

function generateInvoice(int $orderId): array{
	$url = getImpRootUrl().'/pdf/genInvoice.php';

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query(['orderId'=>$orderId]),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_CONNECTTIMEOUT => 15
	]);

	$body = curl_exec($ch);
	$err  = curl_error($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	return [
		'success' => ($body !== false && $code >= 200 && $code < 300),
		'body'    => is_string($body) ? trim($body) : '',
		'error'   => $err,
		'code'    => $code
	];
}

try{

	if ($_SERVER['REQUEST_METHOD'] !== 'POST'){
		sendJson(['success'=>false,'message'=>'POST only'],405);
	}

	$orderId = (int)req('orderId',0);
	$login   = trim((string)req('loginName','mcp_auto'));

	if ($orderId <= 0){
		sendJson(['success'=>false,'message'=>'Invalid orderId'],400);
	}

	// -------- check final --------
	$rs = mysqli_query($con,"SELECT * FROM ".tblPrefix."ordersummary WHERE orderId=".$orderId." LIMIT 1");
	$final = mysqli_fetch_assoc($rs);

	if ($final){
		if (!empty($final['invNo'])){
			sendJson(['success'=>true,'message'=>'Already invoiced','invNo'=>$final['invNo']]);
		}

		mysqli_query($con,"UPDATE ".tblPrefix."ordersummary SET orderStatus=3 WHERE orderId=".$orderId);

		$inv = generateInvoice($orderId);

		if (!$inv['success']) sendJson(['success'=>false,'message'=>'Invoice failed'],500);

		sendJson(['success'=>true,'message'=>'Invoice generated','invNo'=>$inv['body']]);
	}

	// -------- temp summary --------
	$rs = mysqli_query($con,"SELECT * FROM ".tblPrefix."tempordersummary WHERE orderId=".$orderId." LIMIT 1");
	$temp = mysqli_fetch_assoc($rs);

	if (!$temp){
		sendJson(['success'=>false,'message'=>'Order not found'],404);
	}

	$merId = (int)$temp['merId'];

	// -------- items --------
	$qry = "SELECT td.prodId, td.pdctCode, td.Qty,
				   IFNULL(v.ProductName, td.pdctCode) AS productName,
				   IFNULL(v.tax,0) AS gst
			FROM ".tblPrefix."temporderdetails td
			LEFT JOIN v_pdct_tax_list v ON td.prodId = v.prodId
			WHERE td.orderId=".$orderId;

	$rs = mysqli_query($con,$qry);

	$codes=[]; $qtys=[]; $basics=[]; $taxes=[]; $vatArr=[]; $amounts=[];
	$missing=[];

	while($row = mysqli_fetch_assoc($rs)){
		$prodId = (int)$row['prodId'];
		$code   = trim($row['pdctCode']);
		$qty    = (float)$row['Qty'];
		$gst    = (float)$row['gst'];

		$price = getActiveDealerPrice($merId,$prodId);

		if ($price === null || $price <= 0){
			$missing[] = $code;
			continue;
		}

		$vat = round($price*$gst/100,2);
		$amt = round(($price+$vat)*$qty,2);

		$codes[]=$code;
		$qtys[]=$qty;
		$basics[]=$price;
		$taxes[]=$gst;
		$vatArr[]=$vat;
		$amounts[]=$amt;
	}

	if (!empty($missing)){
		sendJson(['success'=>false,'code'=>'PRICE_MISSING','items'=>$missing]);
	}

	if (empty($codes)){
		sendJson(['success'=>false,'message'=>'No valid items']);
	}

	// -------- move --------
	mysqli_begin_transaction($con);

	updateOrder($orderId,$codes,$basics,$qtys,$vatArr,$taxes,$amounts,2,0,'auto',$login);

	mysqli_query($con,"UPDATE ".tblPrefix."ordersummary SET orderStatus=3 WHERE orderId=".$orderId);

	mysqli_commit($con);

	// -------- invoice --------
	$inv = generateInvoice($orderId);

	if (!$inv['success']){
		sendJson(['success'=>false,'message'=>'Invoice failed'],500);
	}

	sendJson([
		'success'=>true,
		'orderId'=>$orderId,
		'invNo'=>$inv['body'],
		'pdf'=>getImpRootUrl().'/pdf/inv/'.$inv['body'].'.pdf'
	]);

}catch(Throwable $e){
	@mysqli_rollback($con);
	sendJson(['success'=>false,'error'=>$e->getMessage()],500);
}