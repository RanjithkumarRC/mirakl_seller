<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
ini_set('max_execution_time', 0);

include "../include/config_new.php";

use Mirakl\MMP\Shop\Client\ShopApiClient as Mirakl_Client;
use Mirakl\MMP\Shop\Request\Offer\GetAccountRequest;

if(isset($_REQUEST['mirakl_url']) && isset($_REQUEST['mirakl_seller_api']) && isset($_REQUEST['bc_storehash']) && isset($_REQUEST['bc_accesstoken'])){
    $mirakl_url         = $_REQUEST['mirakl_url'];
    $mirakl_seller_api  = $_REQUEST['mirakl_seller_api'];
    $bc_storehash       = $_REQUEST['bc_storehash'];
    $bc_accesstoken     = $_REQUEST['bc_accesstoken'];
    
    $update_store_cred_sql = "UPDATE tbl_stores SET mirakl_api_url='".$mirakl_url."', mirakl_seller_api_key='".$mirakl_seller_api."' ";
    
    $api = new Mirakl_Client($mirakl_url, $mirakl_seller_api);
    $result = $api->getAccount();
    $store_informations_decoded = json_decode($result);
    
    if(isset($store_informations_decoded->id)){
        $shop_id = $store_informations_decoded->id;
        $update_store_cred_sql.= ", shop_id='".$shop_id."'";
    }
        
    $update_store_cred_sql.= " WHERE is_active='1' and is_deleted='0' and storehash='".$bc_storehash."'";
    
    if ($conn->query($update_store_cred_sql) === TRUE) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . $conn->error;
    }
}else{
    echo "Invalid credentials";
}
?>