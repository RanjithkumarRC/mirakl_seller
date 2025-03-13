<?php
include "../include/config_new.php";
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use Mirakl\MCI\Shop\Client\ShopApiClient;
use Mirakl\MCI\Shop\Request\Product\DownloadProductImportErrorReportRequest;
use Mirakl\MCI\Shop\Request\Product\DownloadProductImportTransformedFileRequest;
use Mirakl\MCI\Shop\Request\Product\DownloadProductImportNewProductsReportRequest;
use Mirakl\MCI\Shop\Request\Product\DownloadProductImportTransformationErrorReportRequest;

$store_array = array();
if(isset($_REQUEST['data']) && isset($_REQUEST['data_id'])){
    $encode_current_storehash	=  $_REQUEST['data'];
	$decode_current_storehash	=  base64_decode($encode_current_storehash);
    $select_store_sql = "SELECT tbl_stores_id, storehash, access_token, mirakl_api_url, mirakl_seller_api_key, shop_id FROM tbl_stores where storehash = '".$decode_current_storehash."' and is_active=1 and is_deleted=0";

    $store_result = $conn->query($select_store_sql);

    if ($store_result->num_rows > 0) {
        while($store_row = $store_result->fetch_assoc()) {
            $store_array[]= $store_row;
        }
    }else{
        echo 'Unauthorized';
        die();
    }
}

if(empty($store_array)){
    echo 'Unauthorized';
    die();
}

$import_id  = $_REQUEST['data_id'];

$storeHash          = '';
$access_token       = '';
$miraklSellerApiURL = '';
$miraklSellerApiKey = '';
$current_store_id   = '';
$shop_id            = '';
foreach($store_array as $ind_stores){
    $storeHash          = $ind_stores['storehash'];
    $access_token       = $ind_stores['access_token'];
    $miraklSellerApiURL = $ind_stores['mirakl_api_url'];
    $miraklSellerApiKey = $ind_stores['mirakl_seller_api_key'];
    $current_store_id   = $ind_stores['tbl_stores_id'];
    $shop_id            = $ind_stores['shop_id'];
}
$mirakl_seller = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
if($_REQUEST['report'] == 'error_report'){
    // P44
    $request    = new DownloadProductImportErrorReportRequest($import_id);
    $result     = $mirakl_seller->downloadProductImportErrorReport($request);
    $result->download();
}
if($_REQUEST['report'] == 'transformed_file'){
    // P46
    $request    = new DownloadProductImportTransformedFileRequest($import_id);
    $result     = $mirakl_seller->downloadProductImportTransformedFile($request);
    $result->download();
}
if($_REQUEST['report'] == 'new_product_report'){
    // P45
    $request    = new DownloadProductImportNewProductsReportRequest($import_id);
    $result     = $mirakl_seller->downloadProductImportNewProductsReport($request);
    $result->download();
}
if($_REQUEST['report'] == 'transformation_error_report'){
    // P47
    $request    = new DownloadProductImportTransformationErrorReportRequest($import_id);
    $result     = $mirakl_seller->downloadProductImportTransformationErrorReport($request);
    $result->download();
}

die();
?>