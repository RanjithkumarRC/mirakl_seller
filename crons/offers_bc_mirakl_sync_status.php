<?php
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
include "/var/www/html/mirakl_seller/include/config_new.php";

use Guzzle\Http\Client;
use Mirakl\MMP\Shop\Client\ShopApiClient;
use Mirakl\MMP\Shop\Request\Offer\Importer\OfferImportReportRequest;
use Mirakl\MMP\Shop\Request\Offer\Importer\OfferImportErrorReportRequest;

$store_array = array();
if(isset($_REQUEST['data'])){
    $encode_current_storehash	=  $_REQUEST['data'];
	$decode_current_storehash	=  base64_decode($encode_current_storehash);
    $select_store_sql = "SELECT tbl_stores_id, storehash, access_token, mirakl_api_url, mirakl_seller_api_key, shop_id FROM tbl_stores where storehash = '".$decode_current_storehash."' and is_active=1 and is_deleted=0";
}else{
    $select_store_sql = "SELECT tbl_stores_id, storehash, access_token, mirakl_api_url, mirakl_seller_api_key, shop_id FROM tbl_stores where is_active=1 and is_deleted=0";
}
$store_result = $conn->query($select_store_sql);

if($store_result->num_rows > 0) {
    while($store_row    = $store_result->fetch_assoc()) {
        $store_array[]  = $store_row;
    }
}else{
    echo 'Unauthorized';
    die();
}


foreach($store_array as $ind_stores){
    $storeHash          = $ind_stores['storehash'];
    $access_token       = $ind_stores['access_token'];
    $miraklSellerApiURL = $ind_stores['mirakl_api_url'];
    $miraklSellerApiKey = $ind_stores['mirakl_seller_api_key'];
    $current_store_id   = $ind_stores['tbl_stores_id'];
    $shop_id            = $ind_stores['shop_id'];


    $select_import_id_query = 'select tbl_import_id from tbl_bc_mirakl_offers where (sync_status IS NULL OR sync_status!="COMPLETE") and tbl_stores_id = "'.$current_store_id.'" and shop_id ="'.$shop_id.'" and is_active=1 and is_deleted=0';
    $import_id_results = $conn->query($select_import_id_query);
   
    if ($import_id_results->num_rows > 0) {
        // echo '<pre>'; print_r($import_id_results); echo '</pre>';
        while($import_id_result = $import_id_results->fetch_assoc()) {
            $import_id = $import_id_result['tbl_import_id'];
            $mirakl_seller = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
            $importOfferStatus_request = new OfferImportReportRequest($import_id);
            $importOfferStatus_results = $mirakl_seller->getOffersImportResult($importOfferStatus_request);
            $importOfferStatus_results_decode = json_decode($importOfferStatus_results);

            // echo '<pre>'; print_r($importOfferStatus_results_decode); echo '</pre>';
            if($importOfferStatus_results_decode->status == 'COMPLETE'){
                $update_offerSync_status_sql = 'update tbl_bc_mirakl_offers set sync_status = "COMPLETE" where tbl_import_id  = "'.$import_id.'" and tbl_stores_id = "'.$current_store_id.'" and shop_id="'.$shop_id.'"';
                $conn->query($update_offerSync_status_sql);
                if ($conn->query($update_offerSync_status_sql) === TRUE) {
                    echo "New record created successfully";
                } else {
                    echo "Error: " . $update_offerSync_status_sql . "<br>" . $conn->error;
                    }
            }
            if($importOfferStatus_results_decode->error_report == 1 && $importOfferStatus_results_decode->lines_in_error == 1){
                
                $offer_import_error_report = getOfferImportErrorReport($import_id, $miraklSellerApiKey,$miraklSellerApiURL, $shop_id, $current_store_id, $conn);
            }
      
        }

    }
    else{
        echo "No data found";
    }
}

function getOfferImportErrorReport($import_id, $miraklSellerApiKey,$miraklSellerApiURL, $shop_id, $current_store_id, $conn){
    $update_offerSyncError_status_sql = 'update tbl_bc_mirakl_offers set is_error  = 1 ,offer_report_status =1 where tbl_import_id  = "'.$import_id.'" and tbl_stores_id = "'.$current_store_id.'" and shop_id="'.$shop_id.'"';
    if ($conn->query($update_offerSyncError_status_sql) === TRUE) {
        echo "Error Record Updated successfully";
    } else {
        echo "Error: Error Record - " . $update_offerSyncError_status_sql . "<br>" . $conn->error;
    }
    echo '<pre>'; print_r($miraklSellerApiURL.$miraklSellerApiKey.$shop_id.$import_id); echo '</pre>';

}
?>