<?php
// ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
include "/var/www/html/mirakl_seller/include/config_new.php";

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


use Bigcommerce\Api\Client as Bigcommerce;
use BigCommerce\ApiV3\Client as BigcommerceV3;
use Firebase\JWT\JWT;
use Guzzle\Http\Client;
use Handlebars\Handlebars;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use BigCommerce\ApiV2\ResourceModels\Order\Order;
use BigCommerce\ApiV2\ResourceModels\Order\OrderProduct;
use BigCommerce\Tests\V2\V2ApiClientTest;

use Mirakl\MMP\Shop\Client\ShopApiClient;
use Mirakl\MMP\Shop\Request\Order\Get\GetOrdersRequest;
use Mirakl\MMP\Shop\Request\Order\Tracking\UpdateOrderTrackingInfoRequest;
use Mirakl\MMP\Shop\Request\Order\Ship\ShipOrderRequest;

foreach($store_array as $ind_stores){
    $storeHash          = $ind_stores['storehash'];
    $access_token       = $ind_stores['access_token'];
    $miraklSellerApiURL = $ind_stores['mirakl_api_url'];
    $miraklSellerApiKey = $ind_stores['mirakl_seller_api_key'];
    $current_store_id   = $ind_stores['tbl_stores_id'];
    $shop_id            = $ind_stores['shop_id'];

    configureBCApiNew($storeHash,$access_token);

    $api        = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
    $client_id  = clientId();
    $bc_v3_api  = new BigcommerceV3($storeHash, $client_id, $access_token);

    $allowed_orders         = [];
    $tbl_mirakl_order_data  = "SELECT * FROM tbl_seller_mirakl_orders where tbl_stores_id = ".$current_store_id." and sync_status_mirakl = 'PENDING' AND (is_update_mirakl = 0 OR bc_order_id is NULL ) AND mirakl_order_status not in ('WAITING_ACCEPTANCE','WAITING_DEBIT_PAYMENT','CANCELED')";
    
    $all_mirakl_order_result = $conn->query($tbl_mirakl_order_data);
    if ($all_mirakl_order_result->num_rows > 0) {
        while($all_mirakl_order_row = $all_mirakl_order_result->fetch_assoc()) {
            $allowed_orders[] = $all_mirakl_order_row['mirakl_order_id'];
        }
    }

    if(count($allowed_orders) > 0) {
        $request = new GetOrdersRequest();
        $request->setOrderIds($allowed_orders)
         ->setPaginate(false);
        $result = $api->getOrders($request);

        foreach ($result as $miraklOrder) { 
            $mirakl_order_decoded[] = json_decode($miraklOrder);
        }

        foreach($mirakl_order_decoded as $mirakl_order_data){
            $order_data                     = [];
            $mirakl_order_id                = $mirakl_order_data->id;
            $mirakl_order_status            = $mirakl_order_data->status->state;
            $mirakl_order_country_iso2      = "";
            $billing_address_country_iso2   = $mirakl_order_data->customer->billing_address->country_iso_code;
            if($billing_address_country_iso2 == 'AUS'){
                $mirakl_order_country_iso2 = str_replace("AUS", "AU", $billing_address_country_iso2);
                // echo $mirakl_order_country_iso2;
            }
            else{
                $mirakl_order_country_iso2  = $billing_address_country_iso2;
            }
            
            $shipping_address_country_iso2  = $mirakl_order_data->customer->shipping_address->country_iso_code;
            if($shipping_address_country_iso2 == 'AUS'){
                $mirakl_order_country_iso2 = str_replace("AUS", "AU", $shipping_address_country_iso2);
                // echo $mirakl_order_country_iso2;
            }
            else{
                $mirakl_order_country_iso2  = $shipping_address_country_iso2;
            }
           
            // $mirakl_order_shipping_price = '';
            // product data
            $bc_order_product_array = [];
            foreach($mirakl_order_data->order_lines as $individualProduct) {
                // echo '<pre>'; print_r($individualProduct); echo '</pre>';
                $mirakl_individual_product_array                    = [];
                $mirakl_individual_product_array['name']            = $individualProduct->offer->product->title;
                $mirakl_individual_product_array['quantity']        = $individualProduct->quantity;

                // $mirakl_order_shipping_price                        = $individualProduct->shipping_price;
                // $mirakl_individual_product_array['price_inc_tax'] = $individualProduct->price;
                 $mirakl_individual_product_array['price_inc_tax']  = $individualProduct->price+ $individualProduct->taxes[0]->amount;
                 $mirakl_individual_product_array['price_ex_tax']   = $individualProduct->price;
                // $mirakl_individual_product_array['status_id']    = $individualProduct->status->state;

                $bc_order_product_array[] = $mirakl_individual_product_array;
            }

            $order_data['products'] = $bc_order_product_array;

            //billing address
            $order_data['billing_address']['first_name']    = $mirakl_order_data->customer->billing_address->firstname;
            $order_data['billing_address']['last_name']     = $mirakl_order_data->customer->billing_address->lastname;
            if($mirakl_order_data->customer->billing_address->company != NULL){
                $order_data['billing_address']['company']   = $mirakl_order_data->customer->billing_address->company;
            }
            $order_data['billing_address']['street_1']      = $mirakl_order_data->customer->billing_address->street_1;
            if($mirakl_order_data->customer->billing_address->street_2 != NULL){
                $order_data['billing_address']['street_2']  = $mirakl_order_data->customer->billing_address->street_2;
            }
            $order_data['billing_address']['city']          = $mirakl_order_data->customer->billing_address->city;
            $order_data['billing_address']['state']         = $mirakl_order_data->customer->billing_address->state;
            $order_data['billing_address']['zip']           = $mirakl_order_data->customer->billing_address->zip_code;
            $order_data['billing_address']['country']       = $mirakl_order_data->customer->billing_address->country;
            // $order_data['billing_address']['country'] = 'CANADA';
            $order_data['billing_address']['country_iso2']  = $mirakl_order_country_iso2;
            // $order_data['billing_address']['country_iso2'] = 'CA';
            $order_data['billing_address']['phone']         = $mirakl_order_data->customer->billing_address->phone;
            $customer_email = explode("-",$mirakl_order_data->customer->customer_id);
            if($customer_email[1] != NULL){
                $order_data['billing_address']['email']     = $customer_email[1];
            }

            $mirakl_bc_order_status_array = [];
            $mirakl_bc_order_status_array['WAITING_ACCEPTANCE'] = '7'; //Awaiting Payment
            $mirakl_bc_order_status_array['REFUSED'] = '5'; //Cancelled
            $mirakl_bc_order_status_array['WAITING_DEBIT'] = '7'; //Awaiting Payment
            $mirakl_bc_order_status_array['WAITING_DEBIT_PAYMENT'] = '7'; //Awaiting Payment
            $mirakl_bc_order_status_array['PAYMENT_COLLECTED'] = '11'; //Awaiting Fulfillment
            $mirakl_bc_order_status_array['SHIPPING'] = '9'; //Awaiting Shipment
            $mirakl_bc_order_status_array['TO_COLLECT'] = '8'; //Awaiting Pickup
            $mirakl_bc_order_status_array['SHIPPED'] = '2'; //Shipped
            $mirakl_bc_order_status_array['RECEIVED'] = '10'; //Completed
            $mirakl_bc_order_status_array['CLOSED'] = '10'; //Completed
            $mirakl_bc_order_status_array['CANCELED'] = '5'; //Completed
            $mirakl_bc_order_status_array['REFUNDED'] = '4'; //Refunded

            $order_data['status_id']                = $mirakl_bc_order_status_array[$mirakl_order_status];
            // $order_data['shipping_cost_inc_tax']    = $mirakl_order_shipping_price;
            $order_data['shipping_cost_inc_tax']    = $mirakl_order_data->shipping->price;
            // $order_data['default_currency_code'] =$mirakl_order_data->currency_iso_code;


            // shipping address
            $order_shipping_data                = [];
            $order_shipping_data['first_name']  = $mirakl_order_data->customer->shipping_address->firstname;
            $order_shipping_data['last_name']   = $mirakl_order_data->customer->shipping_address->lastname;
            if($mirakl_order_data->customer->shipping_address->company != NULL){
                $order_shipping_data['company'] = $mirakl_order_data->customer->shipping_address->company;
            }
            $order_shipping_data['street_1']    = $mirakl_order_data->customer->shipping_address->street_1;
            if($mirakl_order_data->customer->shipping_address->street_2 != NULL){
                $order_shipping_data['street_2'] = $mirakl_order_data->customer->shipping_address->street_2;
            }
            $order_shipping_data['city']        = $mirakl_order_data->customer->shipping_address->city;
            $order_shipping_data['state']       = $mirakl_order_data->customer->shipping_address->state;
            $order_shipping_data['zip']         = $mirakl_order_data->customer->shipping_address->zip_code;
            $order_shipping_data['country']     = $mirakl_order_data->customer->shipping_address->country;
            // $order_shipping_data['country']  = 'CANADA';
            $order_shipping_data['country_iso2'] = $mirakl_order_country_iso2;
            // $order_shipping_data['country_iso2'] = 'AU';
            $order_shipping_data['phone']       = $mirakl_order_data->customer->shipping_address->phone;
            if($customer_email[1] != NULL){
                $order_shipping_data['email']   = $customer_email[1];
            }
            $order_data['shipping_addresses'][] = $order_shipping_data;

            // echo '---------------------';
            // echo '<pre>'; print_r($order_data); echo '</pre>';

            try {
                $json_order_data = json_encode($order_data);
                // echo $json_order_data;
           
                $BC_order_response = Bigcommerce::createOrder($json_order_data);
                
                if($BC_order_response->id != 'undefined') {
                    $BC_order_id        = '';
                    $BC_order_id        = $BC_order_response->id;
                    $BC_order_status_id = $BC_order_response->status_id;

                    $update_bc_order_query    = "update tbl_seller_mirakl_orders set sync_status_mirakl ='COMPLETED',  bc_order_id=".$BC_order_id.", bc_order_status='".$BC_order_status_id."', updated_at_mirakl=".time().", is_update_mirakl = 0 where mirakl_order_id = '".$mirakl_order_id."' and tbl_stores_id = ".$current_store_id;
                
                    $conn->query($update_bc_order_query);
                }
        
            } catch (\Exception $e) {
                // An exception is thrown if the requested object is not found or if an error occurs
                // var_dump($e);
                echo "<pre>";
                print_r($e);
                echo "</pre>";
            }
            // die();
        }
    } else {
        echo "No Pending Orders to insert";
    }

    // Update order from mirakl to BC
    $tbl_mirakl_order_update_data = "SELECT tbl_seller_mirakl_orders_id, mirakl_order_id, mirakl_order_status, bc_order_id, bc_order_status FROM tbl_seller_mirakl_orders where tbl_stores_id = ".$current_store_id." and sync_status_mirakl = 'PENDING' AND is_update_mirakl = 1";
    $update_mirakl_order_result = $conn->query($tbl_mirakl_order_update_data);
    if ($update_mirakl_order_result->num_rows > 0) {
        $mirakl_bc_order_status_array = [];
        $mirakl_bc_order_status_array['WAITING_ACCEPTANCE'] = '7'; //Awaiting Payment
        $mirakl_bc_order_status_array['REFUSED'] = '5'; //Cancelled
        $mirakl_bc_order_status_array['WAITING_DEBIT'] = '7'; //Awaiting Payment
        $mirakl_bc_order_status_array['WAITING_DEBIT_PAYMENT'] = '7'; //Awaiting Payment
        $mirakl_bc_order_status_array['PAYMENT_COLLECTED'] = '11'; //Awaiting Fulfillment
        $mirakl_bc_order_status_array['SHIPPING'] = '9'; //Awaiting Shipment
        $mirakl_bc_order_status_array['TO_COLLECT'] = '8'; //Awaiting Pickup
        $mirakl_bc_order_status_array['SHIPPED'] = '2'; //Shipped
        $mirakl_bc_order_status_array['RECEIVED'] = '10'; //Completed
        $mirakl_bc_order_status_array['CLOSED'] = '10'; //Completed
        $mirakl_bc_order_status_array['CANCELED'] = '5'; //Completed
        $mirakl_bc_order_status_array['REFUNDED'] = '4'; //Refunded

        while($update_mirakl_order_row = $update_mirakl_order_result->fetch_assoc()) {
            $tbl_seller_mirakl_orders_id = $update_mirakl_order_row['tbl_seller_mirakl_orders_id'];
            // $mirakl_order_id = $update_mirakl_order_row['mirakl_order_id'];
            // $mirakl_order_status = $update_mirakl_order_row['mirakl_order_status'];
            $bc_order_id                    = $update_mirakl_order_row['bc_order_id'];
            $order_update_data              = [];
            $order_update_data['status_id'] = $mirakl_bc_order_status_array[$update_mirakl_order_row['mirakl_order_status']];
            $json_order_update_data         = json_encode($order_update_data);
            $BC_order_update_response       = Bigcommerce::updateOrder($bc_order_id, $json_order_update_data);
            if($BC_order_update_response){
                $update_bc_order_update_query   = "update tbl_seller_mirakl_orders set sync_status_mirakl ='COMPLETED',  is_update_mirakl=0, bc_order_status='".$order_update_data['status_id']."', updated_at_mirakl=".time()." where tbl_seller_mirakl_orders_id = ".$tbl_seller_mirakl_orders_id;
                
                if ($conn->query($update_bc_order_update_query) === TRUE) {
                    echo "BC order status updated successfully";
                } else {
                    echo "Error: " . $update_bc_order_update_query . "<br>" . $conn->error;
                }
            }
        }
    }

    // Fetching orders status from BigCommerce and sync to Mirakl
     $tbl_bc_order_update_data = "select tbl_seller_mirakl_orders_id, mirakl_order_id, mirakl_order_status, sync_status_mirakl, bc_order_id, sync_status_bc, bc_order_status from tbl_seller_mirakl_orders where tbl_stores_id = '".$current_store_id."' and sync_status_bc = 'PENDING' AND is_update_bc = 1";
    $update_bc_order_result = $conn->query($tbl_bc_order_update_data);
    
    if ($update_bc_order_result->num_rows > 0) {
        while($update_bc_order_row = $update_bc_order_result->fetch_assoc()) {
            $tbl_seller_mirakl_orders_id    = $update_bc_order_row['tbl_seller_mirakl_orders_id'];
            $mirakl_order_id                = $update_bc_order_row['mirakl_order_id'];
            $bc_order_id                    = $update_bc_order_row['bc_order_id'];

            $bc_shipment_orders_array = Bigcommerce::getShipments($bc_order_id);
            $carrier_code       = '';
            $carrier_name       = '';
            $carrier_url        = '';
            if(!empty($bc_shipment_orders_array[0]->shipping_provider)){
                $carrier_name  = $bc_shipment_orders_array[0]->shipping_provider;
            }elseif(!empty($bc_shipment_orders_array[0]->tracking_carrier)){
                $carrier_name   = $bc_shipment_orders_array[0]->tracking_carrier;
            }
            $tracking_number    = $bc_shipment_orders_array[0]->tracking_number;

            // OR23
            $update_mirakl_order_request = new UpdateOrderTrackingInfoRequest($mirakl_order_id, [
                'carrier_code'       => $carrier_code,
                'carrier_name'       => $carrier_name,
                'carrier_url'        => $carrier_url,
                'tracking_number'    => $tracking_number,
            ]);
            $update_mirakl_order_responce = $api->updateOrderTrackingInfo($update_mirakl_order_request);
            $update_carrier_status = $update_mirakl_order_responce->getStatusCode();
            if($update_carrier_status == '204'){
                // OR24
                $shipped_status_request = new ShipOrderRequest($mirakl_order_id);
                $shipped_status_responce = $api->shipOrder($shipped_status_request);

                $shipped_status = $shipped_status_responce->getStatusCode();
                if($shipped_status == '204'){
                    $update_mirakl_order_update_query   = "update tbl_seller_mirakl_orders set sync_status_bc ='COMPLETED',  is_update_bc=0, mirakl_order_status='SHIPPED', updated_at_mirakl=".time()." where tbl_seller_mirakl_orders_id = ".$tbl_seller_mirakl_orders_id;
                
                    if ($conn->query($update_mirakl_order_update_query) === TRUE) {
                        echo "Mirakl order status updated successfully";
                    } else {
                        echo "Error: " . $update_mirakl_order_update_query . "<br>" . $conn->error;
                    }
                }
            }
            
        }
    }
}
die();
?>