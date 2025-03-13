<?php
include "../include/config_new.php";
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use Mirakl\MMP\Shop\Client\ShopApiClient;
use Mirakl\MMP\Shop\Request\Offer\Importer\OfferImportReportRequest;
use Mirakl\MMP\Shop\Request\Offer\Importer\OfferImportErrorReportRequest;

$store_array = array();
if(isset($_REQUEST['data'])){
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
// $import_id=2249 ;
$err_report = $_REQUEST['err_report'];

foreach($store_array as $ind_stores){
$storeHash          = $ind_stores['storehash'];
$access_token       = $ind_stores['access_token'];
$miraklSellerApiURL = $ind_stores['mirakl_api_url'];
$miraklSellerApiKey = $ind_stores['mirakl_seller_api_key'];
$current_store_id   = $ind_stores['tbl_stores_id'];
$shop_id            = $ind_stores['shop_id'];

$mirakl_seller = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
$importOfferStatus_request = new OfferImportReportRequest($import_id);
$importOfferStatus_results = $mirakl_seller->getOffersImportResult($importOfferStatus_request);
$importOfferStatus_results_decode = json_decode($importOfferStatus_results,true);

//     echo '<pre>'; 
//    print_r($importOfferStatus_results_decode); 
//    echo '</pre>';
//    $offer_sync_status = $importOfferStatus_results_decode['status'];
//    $offer_error_report= $importOfferStatus_results_decode['error_report'];
//    $update_offer_status = "UPDATE  tbl_bc_mirakl_offers SET sync_status='".$offer_sync_status."', offer_report_status='".$offer_error_report."' WHERE tbl_stores_id =".$current_store_id;

//    $conn->query($update_offer_status);

//    if ($conn->query($update_offer_status) === TRUE) {
//     echo "New record created successfully";
// } else {
// echo "Error: " . $update_offer_status . "<br>" . $conn->error;
// }

// $mirakl_seller = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
// $request = new OffersImportsRequest();
// $request->setOrigins([ImportOrigin::API, ImportOrigin::FRONT]);
// $request->setMode(ImportMode::PARTIAL_UPDATE);
// $result = $mirakl_seller->getOffersImports($request);
// $result_decode=json_encode($result);

//echo '<pre>'; 
//if(isset($importOfferStatus_results_decode->import_id)){
//echo json_encode($importOfferStatus_results_decode); 
//}else{
//echo "No Data";    
//}
//echo '</pre>';

}
/*
$import_offer_status = array();
$import_offer_status['import_id'] = $importOfferStatus_results_decode->import_id;
$import_offer_status['import_status'] = $importOfferStatus_results_decode->status;
*/
if($err_report==1){
if($importOfferStatus_results_decode['status']=="COMPLETE" && $importOfferStatus_results_decode['error_report'] == 1){
    $mirakl_seller = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
    $offer_error_request = new OfferImportErrorReportRequest($importOfferStatus_results_decode['import_id']);
    $offer_error_result = $mirakl_seller->getOffersImportErrorReport($offer_error_request);
    //$import_offer_status['report'] = $offer_error_result->download();
    //$offer_error_result->download();

//    echo '<pre>'; 
//    print_r($offer_error_result); 
//    echo '</pre>';
  // $importOfferStatus_results_decode['offer_error_result']= $offer_error_result;   
}
echo json_encode($offer_error_result->download());

}else{
echo json_encode($importOfferStatus_results_decode); 
}
?>