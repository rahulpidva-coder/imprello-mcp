<?php
header('Content-Type: application/json; charset=utf-8');

include(__DIR__ . '/../../dbFunctions.php');

date_default_timezone_set('Asia/Kolkata');

function sendJson($arr, $code = 200){
	http_response_code($code);
	echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function esc($val){
	global $con;
	return mysqli_real_escape_string($con, trim((string)$val));
}

function buildInInt($arr){
	$out = [];
	foreach ((array)$arr as $v){
		$v = (int)$v;
		if ($v > 0) $out[] = $v;
	}
	return $out;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$dealerIds     = isset($input['dealerIds']) && is_array($input['dealerIds']) ? buildInInt($input['dealerIds']) : [];
$prodId        = isset($input['prodId']) ? (int)$input['prodId'] : 0;
$months        = isset($input['months']) ? (int)$input['months'] : 0;
$dateFrom      = isset($input['dateFrom']) ? trim((string)$input['dateFrom']) : '';
$dateTo        = isset($input['dateTo']) ? trim((string)$input['dateTo']) : '';
$latestOnly    = !empty($input['latestOnly']);
$groupByDealer = !empty($input['groupByDealer']);
$summaryOnly   = !empty($input['summaryOnly']);
$limit         = isset($input['limit']) ? (int)$input['limit'] : 100;

if ($limit <= 0) $limit = 100;
if ($limit > 500) $limit = 500;

$where = [];
$where[] = "os.invNo IS NOT NULL";
$where[] = "os.invNo <> ''";
$where[] = "os.orderType = 1";

if ($prodId > 0){
	$where[] = "od.prodId = ".$prodId;
}

if (!empty($dealerIds)){
	$where[] = "os.merId IN (".implode(',', $dealerIds).")";
}

if ($dateFrom != ''){
	$where[] = "os.orderDate >= '".esc($dateFrom)."'";
}

if ($dateTo != ''){
	$where[] = "os.orderDate <= '".esc($dateTo)."'";
}

if ($months > 0){
	$where[] = "os.orderDate >= DATE_SUB(CURDATE(), INTERVAL ".$months." MONTH)";
}

$whereSql = implode(" AND ", $where);

$baseFrom = "
	FROM ".tblPrefix."ordersummary os
	INNER JOIN ".tblPrefix."orderdetails od ON os.orderId = od.orderId
	LEFT JOIN ".tblPrefix."productlist p ON od.prodId = p.prodId
	LEFT JOIN ".tblPrefix."merchant m ON os.merId = m.merId
	WHERE ".$whereSql;

$summaryQry = "
	SELECT
		COUNT(*) AS invoiceCount,
		ROUND(IFNULL(MIN(od.basicPrice),0),2) AS minPrice,
		ROUND(IFNULL(MAX(od.basicPrice),0),2) AS maxPrice,
		ROUND(IFNULL(AVG(od.basicPrice),0),2) AS avgPrice
	".$baseFrom;

$summaryRs = mysqli_query($con, $summaryQry);
if (!$summaryRs){
	sendJson([
		'success' => false,
		'code' => 'SUMMARY_QUERY_FAILED',
		'message' => mysqli_error($con)
	], 500);
}
$summary = mysqli_fetch_assoc($summaryRs);
mysqli_free_result($summaryRs);

$latestQry = "
	SELECT
		os.orderId,
		os.invNo,
		os.orderDate,
		os.invDate,
		os.merId AS dealerId,
		os.dealerName,
		od.prodId,
		od.pdctCode,
		IFNULL(p.ProductName, od.pdctCode) AS productName,
		od.Qty,
		od.basicPrice,
		od.tax,
		od.vatAmount,
		od.amount
	".$baseFrom."
	ORDER BY os.orderDate DESC, os.orderId DESC
	LIMIT 1";

$latestRs = mysqli_query($con, $latestQry);
if (!$latestRs){
	sendJson([
		'success' => false,
		'code' => 'LATEST_QUERY_FAILED',
		'message' => mysqli_error($con)
	], 500);
}
$latestRow = mysqli_fetch_assoc($latestRs);
mysqli_free_result($latestRs);

$summary['latestPrice'] = $latestRow ? round((float)$latestRow['basicPrice'], 2) : 0;

if ($summaryOnly){
	sendJson([
		'success' => true,
		'filters' => [
			'dealerIds' => $dealerIds,
			'prodId' => $prodId,
			'months' => $months,
			'dateFrom' => $dateFrom,
			'dateTo' => $dateTo,
			'latestOnly' => $latestOnly,
			'groupByDealer' => $groupByDealer,
			'summaryOnly' => $summaryOnly,
			'limit' => $limit
		],
		'summary' => $summary,
		'latest' => $latestRow
	]);
}

$rowLimit = $latestOnly ? 1 : $limit;

$rowsQry = "
	SELECT
		os.orderId,
		os.invNo,
		os.orderDate,
		os.invDate,
		os.merId AS dealerId,
		os.dealerName,
		od.prodId,
		od.pdctCode,
		IFNULL(p.ProductName, od.pdctCode) AS productName,
		od.Qty,
		od.basicPrice,
		od.tax,
		od.vatAmount,
		od.amount
	".$baseFrom."
	ORDER BY os.orderDate DESC, os.orderId DESC
	LIMIT ".$rowLimit;

$rowsRs = mysqli_query($con, $rowsQry);
if (!$rowsRs){
	sendJson([
		'success' => false,
		'code' => 'ROWS_QUERY_FAILED',
		'message' => mysqli_error($con)
	], 500);
}

$rows = [];
while ($row = mysqli_fetch_assoc($rowsRs)){
	$row['dealerId'] = (int)$row['dealerId'];
	$row['prodId'] = (int)$row['prodId'];
	$row['Qty'] = (float)$row['Qty'];
	$row['basicPrice'] = round((float)$row['basicPrice'], 2);
	$row['tax'] = round((float)$row['tax'], 2);
	$row['vatAmount'] = round((float)$row['vatAmount'], 2);
	$row['amount'] = round((float)$row['amount'], 2);
	$rows[] = $row;
}
mysqli_free_result($rowsRs);

$dealerGroups = [];
if ($groupByDealer){
	foreach ($rows as $row){
		$key = (string)$row['dealerId'];
		if (!isset($dealerGroups[$key])){
			$dealerGroups[$key] = [
				'dealerId' => $row['dealerId'],
				'dealerName' => $row['dealerName'],
				'latestPrice' => $row['basicPrice'],
				'rows' => []
			];
		}
		$dealerGroups[$key]['rows'][] = $row;
	}
	$dealerGroups = array_values($dealerGroups);
}

sendJson([
	'success' => true,
	'filters' => [
		'dealerIds' => $dealerIds,
		'prodId' => $prodId,
		'months' => $months,
		'dateFrom' => $dateFrom,
		'dateTo' => $dateTo,
		'latestOnly' => $latestOnly,
		'groupByDealer' => $groupByDealer,
		'summaryOnly' => $summaryOnly,
		'limit' => $limit
	],
	'summary' => $summary,
	'latest' => $latestRow,
	'rows' => $rows,
	'dealerGroups' => $dealerGroups
]);