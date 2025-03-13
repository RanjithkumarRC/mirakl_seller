<?php
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
include "/var/www/html/mirakl_seller/include/config_new.php";

$store_array = array();
if(isset($_REQUEST['data'])){
    $encode_current_storehash	=  $_REQUEST['data'];
	$decode_current_storehash	=  base64_decode($encode_current_storehash);
    $select_store_sql           = "SELECT tbl_stores_id, storehash, access_token, mirakl_api_url, mirakl_seller_api_key, shop_id FROM tbl_stores where storehash = '".$decode_current_storehash."' and is_active = 1 and is_deleted = 0";
}else{
    $select_store_sql           = "SELECT tbl_stores_id, storehash, access_token, mirakl_api_url, mirakl_seller_api_key, shop_id FROM tbl_stores where is_active = 1 and is_deleted = 0";
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

    $select_import_id_query = 'select import_id from tbl_seller_productToMirakl_files where sync_status != "COMPLETE" and tbl_stores_id = "'.$current_store_id.'" and shop_id ="'.$shop_id.'" and is_active=1 and is_deleted=0';

    $import_id_results = $conn->query($select_import_id_query);

    if ($import_id_results->num_rows > 0) {
        while($import_id_result = $import_id_results->fetch_assoc()) {
            $import_id                      = $import_id_result['import_id'];
            $get_product_import_status_api  = $miraklSellerApiURL.'/products/imports/'.$import_id.'?shop_id='.$shop_id;
            $method                         = 'GET';
            $header                         = ["Accept: application/json","Content-Type: application/json","Authorization: ".$miraklSellerApiKey];

            $product_import_status_response         = BC_API_Call($get_product_import_status_api,$method,$header);
            $product_import_status_response_decoded = json_decode($product_import_status_response);

            // echo '<pre>'; print_r($product_import_status_response_decoded); echo '</pre>';
            if($product_import_status_response_decoded->import_status == 'COMPLETE'){
                $update_file_status_sql = 'update tbl_seller_productToMirakl_files set sync_status = "INPROGRESS" where import_id = "'.$import_id.'" and tbl_stores_id = "'.$current_store_id.'" and shop_id="'.$shop_id.'"';
                $conn->query($update_file_status_sql);

                if($product_import_status_response_decoded->has_transformation_error_report == 1){
                    $import_new_product_report = getImportTransformationErrorReport($import_id, $miraklSellerApiURL, $shop_id, $method, $header, $current_store_id, $conn);
                }

                if($product_import_status_response_decoded->has_error_report == 1){
                    $import_error_report = getImportErrorReport($import_id, $miraklSellerApiURL, $shop_id, $method, $header, $current_store_id, $conn);
                    // echo '<pre>'; print_r($import_error_report); echo '</pre>';
                }

                if($product_import_status_response_decoded->has_new_product_report == 1){
                    $import_new_product_report = getImportNewProductReport($import_id, $miraklSellerApiURL, $shop_id, $method, $header, $current_store_id, $conn);
                }
                $update_file_status_sql = 'update tbl_seller_productToMirakl_files set sync_status = "COMPLETE" where import_id = "'.$import_id.'" and tbl_stores_id = "'.$current_store_id.'" and shop_id="'.$shop_id.'"';
                $conn->query($update_file_status_sql);
            }
        }
    }
}

function getImportErrorReport($import_id, $miraklSellerApiURL, $shop_id, $method, $header, $current_store_id, $conn){
    $get_product_import_error_status_api    = $miraklSellerApiURL.'/products/imports/'.$import_id.'/error_report?shop_id='.$shop_id;
    $product_import_error_response          = BC_API_Call($get_product_import_error_status_api,$method,$header);

    $error_report_file_name     = 'error_report_'.$current_store_id.'_'.rand(1,1000).'_'.time().'.csv';
    $error_report_csv_filename  = '/var/www/html/mirakl_seller/product_csvFiles/error_report/'.$error_report_file_name;
    $error_report_csv_file      = fopen($error_report_csv_filename, 'w');
    $csv                        = file_put_contents($error_report_csv_filename,$product_import_error_response);
    fclose($error_report_csv_file);

    $error_report_file  = fopen($error_report_csv_filename, "r");
    $error_report_datas = array();
    while (!feof($error_report_file)) {
        $error_report_datas[] = fgetcsv($error_report_file, null, ';');
    }
    unlink($error_report_csv_filename);

    $firstValue     = array_shift($error_report_datas); //remove first value from array and assign it to variable
    foreach($error_report_datas as &$error_report_data){ //loop over remaining values
        $error_report_data = array_combine($firstValue,$error_report_data); //combine both array to create key value pair
    }

    foreach($error_report_datas as $error_report){
        if($error_report){
            $update_error_report_status_sql = 'update tbl_bc_mirakl_products set sync_status="ERROR", is_error=1, product_report_status = "'.$error_report['errors'].'" where product_name = "'.$error_report['TITLE'].'" and (product_sku = "'.$error_report['SHOP_SKU'].'" || variant_sku = "'.$error_report['SHOP_SKU'].'") and shop_id = "'.$shop_id.'" and tbl_stores_id ="'.$current_store_id.'" and is_active = 1 and is_deleted = 0';

            if ($conn->query($update_error_report_status_sql) === TRUE) {
                echo "Error Record Updated successfully";
            } else {
                echo "Error: Error Record - " . $update_error_report_status_sql . "<br>" . $conn->error;
            }
        }
    }
}

function getImportNewProductReport($import_id, $miraklSellerApiURL, $shop_id, $method, $header, $current_store_id, $conn){
    $get_import_new_product_report_api      = $miraklSellerApiURL.'/products/imports/'.$import_id.'/new_product_report?shop_id='.$shop_id;
    $product_import_new_product_response    = BC_API_Call($get_import_new_product_report_api,$method,$header);

    $new_product_report_file_name       = 'new_product_'.$current_store_id.'_'.rand(1,1000).'_'.time().'.csv';
    $new_product_report_csv_filename    = '/var/www/html/mirakl_seller/product_csvFiles/error_report/'.$new_product_report_file_name;
    $new_product_report_csv_file        = fopen($new_product_report_csv_filename, 'w');
    $csv= file_put_contents($new_product_report_csv_filename,$product_import_new_product_response);
    fclose($new_product_report_csv_file);

    $new_product_report_file    = fopen($new_product_report_csv_filename, "r");
    $new_product_report_datas   = array();
    while (!feof($new_product_report_file)) {
        $new_product_report_datas[] = fgetcsv($new_product_report_file, null, ';');
    }
    unlink($new_product_report_csv_filename);

    $newProductFirstValue   = array_shift($new_product_report_datas); //remove first value from array and assign it to variable
    foreach($new_product_report_datas as &$new_product_report_data){ //loop over remaining values
        $new_product_report_data = array_combine($newProductFirstValue,$new_product_report_data); //combine both array to create key value pair
    }

    foreach($new_product_report_datas as $new_product_report){
        if($new_product_report){
            $update_new_product_report_status_sql = 'update tbl_bc_mirakl_products set sync_status="COMPLETE" where product_name = "'.$new_product_report['TITLE'].'" and (product_sku = "'.$new_product_report['SHOP_SKU'].'" || variant_sku = "'.$new_product_report['SHOP_SKU'].'") and shop_id = "'.$shop_id.'" and tbl_stores_id ="'.$current_store_id.'" and is_active = 1 and is_deleted = 0';

            if ($conn->query($update_new_product_report_status_sql) === TRUE) {
                echo "New Product Record Updated successfully";
            } else {
                echo "Error: New Product Record - " . $update_new_product_report_status_sql . "<br>" . $conn->error;
            }
        }
    }
}

function getImportTransformationErrorReport($import_id, $miraklSellerApiURL, $shop_id, $method, $header, $current_store_id, $conn){
    $get_import_transformation_error_report_api     = $miraklSellerApiURL.'/products/imports/'.$import_id.'/transformation_error_report?shop_id='.$shop_id;
    $product_import_transformation_error_response   = BC_API_Call($get_import_transformation_error_report_api,$method,$header);

    $transformation_error_report_file_name      = 'new_product_'.$current_store_id.'_'.rand(1,1000).'_'.time().'.csv';
    $transformation_error_report_csv_filename   = '/var/www/html/mirakl_seller/product_csvFiles/error_report/'.$transformation_error_report_file_name;
    $transformation_error_report_csv_file       = fopen($transformation_error_report_csv_filename, 'w');
    $csv                                        = file_put_contents($transformation_error_report_csv_filename,$product_import_transformation_error_response);
    fclose($transformation_error_report_csv_file);

    $transformation_error_report_file   = fopen($transformation_error_report_csv_filename, "r");
    $transformation_error_report_datas  = array();
    while (!feof($transformation_error_report_file)) {
        $transformation_error_report_datas[] = fgetcsv($transformation_error_report_file, null, ';');
    }
    unlink($transformation_error_report_csv_filename);

    $transformationErrorFirstValue = array_shift($transformation_error_report_datas); //remove first value from array and assign it to variable
    foreach($transformation_error_report_datas as &$transformation_error_report_data){ //loop over remaining values
        $transformation_error_report_data = array_combine($transformationErrorFirstValue,$transformation_error_report_data); //combine both array to create key value pair
    }
    // echo '<pre>'; print_r($transformation_error_report_datas); echo '<pre>';
    foreach($transformation_error_report_datas as $transformation_error_report){
        if($transformation_error_report){
            $update_transformation_error_report_status_sql = 'update tbl_bc_mirakl_products set sync_status="COMPLETE" ';
            if(!empty($transformation_error_report['warnings'])){
                $update_transformation_error_report_status_sql .= ', product_report_status = "'.$transformation_error_report['warnings'].'" ';
            }else{
                $update_transformation_error_report_status_sql .= ', product_report_status = "'.$transformation_error_report['errors'].'", is_error = 1 ';
            }
            $update_transformation_error_report_status_sql .= ' where product_name = "'.$transformation_error_report['TITLE'].'" and (product_sku = "'.$transformation_error_report['SHOP_SKU'].'" || variant_sku = "'.$transformation_error_report['SHOP_SKU'].'") and shop_id = "'.$shop_id.'" and tbl_stores_id ="'.$current_store_id.'" and is_active = 1 and is_deleted = 0';

            if ($conn->query($update_transformation_error_report_status_sql) === TRUE) {
                echo "Transformation Error Record Updated successfully";
            } else {
                echo "Error: ransformation Error Record - " . $update_transformation_error_report_status_sql . "<br>" . $conn->error;
            }
        }
    }
}

function BC_API_Call($api,$method,$header){
	$curl = curl_init();
	curl_setopt_array($curl, [
	CURLOPT_URL => $api,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_ENCODING => "",
	CURLOPT_MAXREDIRS => 10,
	CURLOPT_TIMEOUT => 30,
	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	CURLOPT_CUSTOMREQUEST => $method,
	CURLOPT_HTTPHEADER => $header,
	]);
	$response = curl_exec($curl);
	$err = curl_error($curl);
	curl_close($curl);
	if ($err) {
		return $err;
	} else {
		return $response;
	}
}

?>