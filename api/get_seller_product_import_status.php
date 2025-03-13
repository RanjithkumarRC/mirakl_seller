<?php
include "../include/config_new.php";
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use Mirakl\MCI\Shop\Client\ShopApiClient;
use Mirakl\MCI\Shop\Request\Product\ProductImportStatusRequest;

$store_array = array();
if(isset($_REQUEST['data']) && isset($_REQUEST['data_id'])){
    $encode_current_storehash	=  $_REQUEST['data'];
	$decode_current_storehash	=  base64_decode($encode_current_storehash);
    $select_store_sql           = "SELECT tbl_stores_id, storehash, access_token, mirakl_api_url, mirakl_seller_api_key, shop_id FROM tbl_stores where storehash = '".$decode_current_storehash."' and is_active=1 and is_deleted=0";

    $store_result = $conn->query($select_store_sql);

    if ($store_result->num_rows > 0) {
        while($store_row = $store_result->fetch_assoc()) {
            $store_array[]  = $store_row;
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

foreach($store_array as $ind_stores){
    $storeHash          = $ind_stores['storehash'];
    $access_token       = $ind_stores['access_token'];
    $miraklSellerApiURL = $ind_stores['mirakl_api_url'];
    $miraklSellerApiKey = $ind_stores['mirakl_seller_api_key'];
    $current_store_id   = $ind_stores['tbl_stores_id'];
    $shop_id            = $ind_stores['shop_id'];
    // $mirakl_url         = str_replace('/api','',$miraklSellerApiURL);
    
    $mirakl_seller                  = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
    
    $importStatus_request           = new ProductImportStatusRequest($import_id);
    $importStatus_results           = $mirakl_seller->getProductImportStatus($importStatus_request);
    $importStatus_results_decode    = json_decode($importStatus_results);
    // echo '<pre>'; print_r($importStatus_results_decode); echo '</pre>';

    $import_status                  = array();
    $import_status['import_id']     = $importStatus_results_decode->import_id;
    $import_status['import_status'] = $importStatus_results_decode->import_status;

    $select_import_id_details_sql   = "select file_name, file_url from tbl_seller_productToMirakl_files  where import_id = '".$importStatus_results_decode->import_id."' and tbl_stores_id = '".$current_store_id."' and shop_id = '".$shop_id."' and is_active = 1 and is_deleted = 0";
    $import_id_result               = $conn->query($select_import_id_details_sql);

    if ($import_id_result->num_rows > 0) {
        while($import_data = $import_id_result->fetch_assoc()) {
            $import_status['file_name'] = $import_data['file_name'];
            $import_status['file_url']  = $import_data['file_url'];
        }
    }

    if($importStatus_results_decode->import_status == 'SENT'){
        $import_status['transformed_lines_read']        = $importStatus_results_decode->transformed_lines_read;
        $import_status['transform_lines_in_success']    = $importStatus_results_decode->transform_lines_in_success;
        $import_status['transform_lines_in_error']      = $importStatus_results_decode->transformed_lines_in_error;
        $import_status['transform_lines_with_warning']  = $importStatus_results_decode->transformed_lines_with_warning;
    }
    
    if($importStatus_results_decode->import_status == 'COMPLETE'){
        $import_status['transformed_lines_read']        = $importStatus_results_decode->transformed_lines_read;
        $import_status['transform_lines_in_success']    = $importStatus_results_decode->transform_lines_in_success;
        $import_status['transform_lines_in_error']      = $importStatus_results_decode->transformed_lines_in_error;
        $import_status['transform_lines_with_warning']  = $importStatus_results_decode->transformed_lines_with_warning;
        $import_status['invalid_products']              = isset($importStatus_results_decode->integration_details->invalid_products) ? $importStatus_results_decode->integration_details->invalid_products : 0;
        $import_status['products_not_accepted_in_time'] = isset($importStatus_results_decode->integration_details->products_not_accepted_in_time) ? $importStatus_results_decode->integration_details->products_not_accepted_in_time : 0;
        $import_status['products_not_synchronized_in_time']     = isset($importStatus_results_decode->integration_details->products_not_synchronized_in_time) ? $importStatus_results_decode->integration_details->products_not_synchronized_in_time : 0;
        $import_status['products_successfully_synchronized']    = isset($importStatus_results_decode->integration_details->products_successfully_synchronized) ? $importStatus_results_decode->integration_details->products_successfully_synchronized : 0;
        $import_status['products_with_synchronization_issues']  = isset($importStatus_results_decode->integration_details->products_with_synchronization_issues) ? $importStatus_results_decode->integration_details->products_with_synchronization_issues : 0;
        $import_status['products_with_wrong_identifiers']       = isset($importStatus_results_decode->integration_details->products_with_wrong_identifiers) ? $importStatus_results_decode->integration_details->products_with_wrong_identifiers : 0;
        $import_status['rejected_products']                     = isset($importStatus_results_decode->integration_details->rejected_products) ? $importStatus_results_decode->integration_details->rejected_products : 0;
        // echo '-----'; echo '<pre>'; print_r($importStatus_results_decode->integration_details); echo '</pre>'; echo '-----';
    }
    $import_status['transformation_error_report']   = $importStatus_results_decode->transformation_error_report;
    $import_status['transformed_file']              = $importStatus_results_decode->transformed_file;
    $import_status['new_product_report']            = $importStatus_results_decode->new_product_report;
    $import_status['error_report']                  = $importStatus_results_decode->error_report;
    echo json_encode($import_status);
}

?>