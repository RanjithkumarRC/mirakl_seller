<?php
// ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once '/var/www/html/mirakl_seller/vendor/autoload.php';
include "/var/www/html/mirakl_seller/include/config_new.php";

use Bigcommerce\Api\Client as Bigcommerce;

use Mirakl\MMP\Shop\Client\ShopApiClient;
use Mirakl\MMP\Shop\Request\Order\Get\GetOrdersRequest;

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
  while($store_row = $store_result->fetch_assoc()) {
    $store_array[]= $store_row;
  }
}else{
  echo 'Unauthorized';
  die();
}

foreach($store_array as $ind_stores){
  $storeHash              = $ind_stores['storehash'];
  $access_token           = $ind_stores['access_token'];
  $tbl_stores_id          = $ind_stores['tbl_stores_id'];
  $mirakl_api_url         = $ind_stores['mirakl_api_url'];
  $mirakl_seller_api_key  = $ind_stores['mirakl_seller_api_key'];
  $shop_id                = $ind_stores['shop_id'];

  configureBCApiNew($storeHash,$access_token);
  $seller_api = new ShopApiClient($mirakl_api_url, $mirakl_seller_api_key, $shop_id);

  $check_dataexists = "select tbl_seller_mirakl_orders_id, mirakl_order_id, mirakl_order_status, sync_status_mirakl, bc_order_id, sync_status_bc, bc_order_status from tbl_seller_mirakl_orders where tbl_stores_id = '".$tbl_stores_id."'";

  $check_dataexists_result = $conn->query($check_dataexists);

  $mirakl_orders_ids      = [];
  $mirakl_orders_details  = [];
  $bc_orders_ids          = [];
  $bc_orders_details      = [];
  if($check_dataexists_result->num_rows > 0) {
    while($order_rows = $check_dataexists_result->fetch_assoc()) {
      $mirakl_orders_ids[]= $order_rows['mirakl_order_id'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['tbl_seller_mirakl_orders_id'] = $order_rows['tbl_seller_mirakl_orders_id'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['mirakl_order_id'] = $order_rows['mirakl_order_id'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['mirakl_order_status'] = $order_rows['mirakl_order_status'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['bc_order_status'] = $order_rows['bc_order_status'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['sync_status_bc'] = $order_rows['sync_status_bc'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['sync_status_mirakl'] = $order_rows['sync_status_mirakl'];
      $mirakl_orders_details[$order_rows['mirakl_order_id']]['bc_order_id'] = $order_rows['bc_order_id'];
      if(!empty($order_rows['bc_order_id'])){
        $bc_orders_ids[] = $order_rows['bc_order_id'];
        $bc_orders_details[$order_rows['bc_order_id']]['tbl_seller_mirakl_orders_id'] = $order_rows['tbl_seller_mirakl_orders_id'];
        $bc_orders_details[$order_rows['bc_order_id']]['mirakl_order_id'] = $order_rows['mirakl_order_id'];
        $bc_orders_details[$order_rows['bc_order_id']]['mirakl_order_status'] = $order_rows['mirakl_order_status'];
        $bc_orders_details[$order_rows['bc_order_id']]['bc_order_status'] = $order_rows['bc_order_status'];
        $bc_orders_details[$order_rows['bc_order_id']]['sync_status_bc'] = $order_rows['sync_status_bc'];
        $bc_orders_details[$order_rows['bc_order_id']]['sync_status_mirakl'] = $order_rows['sync_status_mirakl'];
        $bc_orders_details[$order_rows['bc_order_id']]['bc_order_id'] = $order_rows['bc_order_id'];
      }
    }
  }
  
  if(empty($mirakl_orders_ids)){
    $get_order_request = new GetOrdersRequest();
    // $get_order_request->setOrderIds('1219788510-A')
    //  ->setPaginate(false);
    $get_order_request->setPaginate(false);
    $get_order_results = $seller_api->getOrders($get_order_request);
    foreach ($get_order_results as $get_order_result) { 
      $mirakl_order_datas = json_decode($get_order_result);
      // echo '<pre>'; print_r($mirakl_order_datas); echo '<pre>';
      $insert_order_sql   = "INSERT INTO tbl_seller_mirakl_orders (mirakl_order_id, mirakl_order_status, tbl_stores_id, sync_status_mirakl, created_at) VALUES ('".$mirakl_order_datas->id."','".$mirakl_order_datas->status->state."','".$tbl_stores_id."', 'PENDING', ".time()." )";

      if ($conn->query($insert_order_sql) === TRUE) {
				echo "New record created successfully";
			} else {
				echo "Error: " . $insert_order_sql . "<br>" . $conn->error;
			}
    }
  }else{
    $order_update_datetime    = date(DATE_ISO8601, strtotime('-48 hour'));
    $order_update_date_array  = explode('+',$order_update_datetime);
    $order_update_date        = $order_update_date_array[0]."Z";

    $get_order_request = new GetOrdersRequest();
    $get_order_request->setPaginate(false);
    $get_order_request->setStartUpdateDate($order_update_date);
    $get_order_results = $seller_api->getOrders($get_order_request);

    foreach ($get_order_results as $get_order_result) {
      $mirakl_order_datas = json_decode($get_order_result);
      
      if(array_key_exists($mirakl_order_datas->id,$mirakl_orders_details)){
        if($mirakl_order_datas->status->state != $mirakl_orders_details[$mirakl_order_datas->id]['mirakl_order_status']){
          $update_mirakl_order_status = 'update tbl_seller_mirakl_orders set mirakl_order_status = "'.$mirakl_order_datas->status->state.'", is_update_mirakl = 1, sync_status_mirakl = "PENDING", updated_at_mirakl = '.time().' where tbl_seller_mirakl_orders_id = '.$mirakl_orders_details[$mirakl_order_datas->id]['tbl_seller_mirakl_orders_id'];

          if ($conn->query($update_mirakl_order_status) === TRUE) {
            echo "Updated successfully";
          } else {
            echo "Error: " . $update_mirakl_order_status . "<br>" . $conn->error;
          }
        }
      }else{
        $insert_order_sql = "INSERT INTO tbl_seller_mirakl_orders (mirakl_order_id, mirakl_order_status, tbl_stores_id, sync_status_mirakl, created_at) VALUES ('".$mirakl_order_datas->id."','".$mirakl_order_datas->status->state."','".$tbl_stores_id."', 'PENDING', ".time()." )";

        if ($conn->query($insert_order_sql) === TRUE) {
          echo "New record created successfully";
        } else {
          echo "Error: " . $insert_order_sql . "<br>" . $conn->error;
        }
      }
    }
  }

  // Fetching orders status from BigCommerce
  // tbl_seller_mirakl_orders
  if(!empty($bc_orders_details)){
    // echo '<pre>'; print_r($bc_orders_details); echo '<pre>';
    $last_updated_date                  = date(DATE_ISO8601, strtotime('-48 hour'));
    $filter_array                       = [];
    $filter_array['min_date_modified']  = $last_updated_date;

    $bc_orders_array                    = Bigcommerce::getOrders($filter_array);
    
    foreach($bc_orders_array as $individual_order){
      $bc_order_id = $individual_order->id;
      if(in_array($bc_order_id,$bc_orders_ids)){

        if(($individual_order->status_id != $bc_orders_details[$bc_order_id]['bc_order_status']) && ($bc_orders_details[$bc_order_id]['sync_status_mirakl'] != 'PENDING') && ($individual_order->status_id == 2)){
          $bc_shipment_orders_array = Bigcommerce::getShipments($bc_order_id);
          if((!empty($bc_shipment_orders_array[0]->shipping_provider)) || (!empty($bc_shipment_orders_array[0]->tracking_carrier))){
            $update_bc_order_status = 'update tbl_seller_mirakl_orders set bc_order_status = "'.$individual_order->status_id.'", is_update_bc = 1, sync_status_bc = "PENDING", updated_at_bc = '.time().' where tbl_seller_mirakl_orders_id = '.$bc_orders_details[$bc_order_id]['tbl_seller_mirakl_orders_id'];

            if ($conn->query($update_bc_order_status) === TRUE) {
              echo "Updated successfully";
            } else {
              echo "Error: " . $update_bc_order_status . "<br>" . $conn->error;
            }
          }
        }
        /*
          shipping tracking
          or24 - validate shipping order(proced to shipment)
        */
      }
    }
  }
}
?>