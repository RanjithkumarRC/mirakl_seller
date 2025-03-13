<?php
include "/var/www/html/mirakl_seller/include/config_new.php";
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use Bigcommerce\Api\Client as Bigcommerce;
use BigCommerce\ApiV3\Client as BigcommerceV3;

use Mirakl\MMP\Shop\Client\ShopApiClient;
use Mirakl\MMP\Shop\Request\Offer\UpdateOffersRequest;

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

    configureBCApiNew($storeHash,$access_token);
    $client_id      = clientId();
    $bc_v3_api      = new BigcommerceV3($storeHash, $client_id, $access_token);
    $select_produts ="SELECT * from tbl_bc_mirakl_products where tbl_stores_id = ".$current_store_id." and sync_status = 'COMPLETE' and is_update = 0 and shop_id = '".$shop_id."' and is_active = 1 and is_deleted =0 and is_error = 0";
    $store_product_result   = $conn->query($select_produts);
    $store_product_array    = [];
    $store_variant_sku      = [];
    $offer_array            = [];
    if ($store_product_result->num_rows > 0) {
        while($store_row = $store_product_result->fetch_assoc()) {
            if ($store_row['is_variant'] == 1){
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["tbl_bc_mirakl_product_id"]=$store_row['tbl_bc_mirakl_product_id'];
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["mirakl_product_id"]=$store_row['mirakl_product_id'];
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["product_name"]=$store_row['product_name'];  
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["product_sku"]=$store_row['product_sku'];  
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["is_variant"]=$store_row['is_variant'];
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["variant_sku"]=$store_row['variant_sku'];
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["variant_id"]=$store_row['variant_id'];  

                $store_product_array[$store_row['variant_sku']]["id"] = $store_row['bc_product_id'];
                $store_product_array[$store_row['variant_sku']]["sku"]= $store_row['variant_sku'];
            }else{
                $offer_array[$store_row['bc_product_id']][0]["tbl_bc_mirakl_product_id"]=$store_row['tbl_bc_mirakl_product_id'];
                $offer_array[$store_row['bc_product_id']][0]["mirakl_product_id"]=$store_row['mirakl_product_id'];  
                $offer_array[$store_row['bc_product_id']][$store_row['variant_sku']]["product_name"]=$store_row['product_name'];  
                $offer_array[$store_row['bc_product_id']][0]["product_sku"]=$store_row['product_sku']; 
                $offer_array[$store_row['bc_product_id']][0]["is_variant"]=$store_row['is_variant'];
                $offer_array[$store_row['bc_product_id']][0]["variant_sku"]=$store_row['variant_sku'];
                $offer_array[$store_row['bc_product_id']][0]["variant_id"]=$store_row['variant_id'];  

                $store_product_array[$store_row['product_sku']]["id"] = $store_row['bc_product_id'];
                $store_product_array[$store_row['product_sku']]["sku"]= 0;
            }
        }
    }

    $method = 'GET';
    $header = ["Accept: application/json","Content-Type: application/json","X-Auth-Token: ".$access_token];

    $bc_product_array = array();  
    $bc_product_variant_array=array();
    $is_variant=0;
    $offers_Sync_status = 0;
    foreach($store_product_array as $product_id_bc){
        $is_variant=$offer_array[$product_id_bc['id']][$product_id_bc['sku']]['is_variant'];
        $variant_sku=$offer_array[$product_id_bc['id']][$product_id_bc['sku']]['variant_sku'];
        $variant_id=$offer_array[$product_id_bc['id']][$product_id_bc['sku']]['variant_id'];

        if( $is_variant == 1){
            $getEachVariantData = $bc_v3_api->catalog()->product($product_id_bc['id'])->variant($variant_id)->get();
            // $bc_product_array[]=$getEachVariantData;
            $offers_Sync_status=CreateOffersBCtoMirakl($miraklSellerApiURL, $miraklSellerApiKey, $shop_id,$getEachVariantData, $conn, $current_store_id,$offer_array,$is_variant);
        }else{
            $get_product_api = 'https://api.bigcommerce.com/stores/'.$storeHash.'/v3/catalog/products/'.$product_id_bc['id'];
            $bc_product_response = BC_API_Call($get_product_api,$method,$header);
            $bc_product_response_decoded = json_decode($bc_product_response);
            $offers_Sync_status=CreateOffersBCtoMirakl($miraklSellerApiURL, $miraklSellerApiKey, $shop_id,$bc_product_response_decoded, $conn, $current_store_id,$offer_array,$is_variant);  
        }
    }
    echo $offers_Sync_status;
    if($offers_Sync_status == 1){
        echo "Offers Synchronized Successfully!!";
    }
    else{
        echo "No Offers to Synchronize (or) All product are Sunchronized!!";
    }
}

function CreateOffersBCtoMirakl($miraklSellerApiURL, $miraklSellerApiKey, $shop_id, $bc_product_array, $conn, $current_store_id,$offer_array,$is_variant){
    $offer_data_array=[];
    $mirakl_seller = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);
    $request = new UpdateOffersRequest([$shop_id]);
    $is_product_sync = 0;
        if($is_variant == 1){
            $variantData=(array)$bc_product_array;
            foreach($variantData as $variant_product){
            $offer_data_array['shop_sku'] = $variant_product->sku;
            $offer_data_array['price']=$variant_product->calculated_price;
            $offer_data_array['product_id']=$variant_product->sku;
            $offer_data_array['product_id_type']='SHOP_SKU';
            $offer_data_array['state_code']=11;
            $offer_data_array['quantity']=$variant_product->inventory_level;
            $offer_data_array['update_delete']='update';
            $request->setOffers([
                $offer_data_array
            ]);
            $offer_result = $mirakl_seller->updateOffers($request);
            $offer_result_decode=json_decode($offer_result);
           
    
    
            $offer_import_id=$offer_result_decode->import_id;
            $tbl_bc_mirakl_product_id=$offer_array[$variant_product->product_id][$variant_product->sku]["tbl_bc_mirakl_product_id"];
            $offer_name=$offer_array[$variant_product->product_id][$variant_product->sku]["product_name"];
            $mirakl_product_id=$offer_array[$variant_product->product_id][$variant_product->sku]["mirakl_product_id"];
            $offer_sku=$offer_array[$variant_product->product_id][$variant_product->sku]["product_sku"];
            $offer_variant_sku=$variant_product->sku;
            $offer_price=$variant_product->calculated_price;
            $offer_discount_price=$variant_product->sale_price;
            $offer_quantity=$variant_product->inventory_level;
            // echo '<pre>';
            // print_r($variant_product);
            // echo '</pre>'; 


            if (isset($offer_import_id)){

            $check_offers_qry="select * from tbl_bc_mirakl_offers where tbl_stores_id = '".$current_store_id."' and variant_sku = '".$offer_variant_sku."' and is_update=0 and is_active=1 and is_deleted=0";
            $check_result_qry = $conn->query($check_offers_qry);
      
            if ($check_result_qry->num_rows > 0) {
                $select_sync_status_result 	= $check_result_qry->fetch_assoc();
                if(($select_sync_status_result['offer_sku'] == $offer_sku && $select_sync_status_result['is_active'] == 1 && $select_sync_status_result['sync_status'] == 'COMPLETE') && (($select_sync_status_result['price'] !=  $offer_price) || ($select_sync_status_result['discount_price'] !=  $offer_discount_price) || ($select_sync_status_result['quantity'] !=  $offer_quantity))){
                  
                    $offers_tbl_update="UPDATE tbl_bc_mirakl_offers SET is_update = 1, is_active = 0,is_deleted = 1,updated_at = ".time()." WHERE tbl_bc_mirakl_offer_id=".$select_sync_status_result['tbl_bc_mirakl_offer_id'];
                
                    $offer_table_update_qry=$conn->query($offers_tbl_update);
                  
                    
                    $offer_import_save_sql = "INSERT INTO tbl_bc_mirakl_offers (tbl_bc_mirakl_product_id, mirakl_product_id ,tbl_stores_id,tbl_import_id, offer_name , offer_sku,is_variant ,variant_sku, price, discount_price, shop_id, is_active, is_deleted, created_at,quantity) VALUES ('".$tbl_bc_mirakl_product_id."','".$mirakl_product_id."','".$current_store_id."','".$offer_import_id."','".$offer_name."','".$offer_sku."','".$is_variant."','".$offer_variant_sku."','".$offer_price."','".$offer_discount_price."','".$shop_id."',1,0,".time().",'".$offer_quantity."')";

                    // $store_offer_result = $conn->query($offer_import_save_sql);
                  
                 

                    if ($conn->query($offer_import_save_sql) === TRUE) {
                        $is_product_sync = 1;
                       
                    } else {
                        echo "Error: " . $offer_import_save_sql . "<br>" . $conn->error;
                        }
                } 
               
              
            }
            else{
              
                $offer_import_save_sql = "INSERT INTO tbl_bc_mirakl_offers (tbl_bc_mirakl_product_id, mirakl_product_id ,tbl_stores_id,tbl_import_id, offer_name , offer_sku,is_variant ,variant_sku, price, discount_price, shop_id, is_active, is_deleted, created_at,quantity) VALUES ('".$tbl_bc_mirakl_product_id."','".$mirakl_product_id."','".$current_store_id."','".$offer_import_id."','".$offer_name."','".$offer_sku."','".$is_variant."','".$offer_variant_sku."','".$offer_price."','".$offer_discount_price."','".$shop_id."',1,0,".time().",'".$offer_quantity."')";
                if ($conn->query($offer_import_save_sql) === TRUE) {
                    $is_product_sync = 1;
                } else {
                    echo "Error: " . $offer_import_save_sql . "<br>" . $conn->error;
                    }
            }
            }
            }
        }else{
           
            $offer_data_array['shop_sku'] = $bc_product_array->data->sku;
                $offer_data_array['price']=$bc_product_array->data->price;
                $offer_data_array['product_id']=$bc_product_array->data->sku;
                $offer_data_array['product_id_type']='SHOP_SKU';
                $offer_data_array['state_code']=11;
                $offer_data_array['quantity']=$bc_product_array->data->inventory_level;
                $offer_data_array['update_delete']='update';
            
                $request->setOffers([
                    $offer_data_array
                     ]);
                $offer_result = $mirakl_seller->updateOffers($request);
                $offer_result_decode=json_decode($offer_result);
              
                $offer_import_id=$offer_result_decode->import_id;
                $tbl_bc_mirakl_product_id=$offer_array[$bc_product_array->data->id][0]["tbl_bc_mirakl_product_id"];
                $mirakl_product_id=$offer_array[$bc_product_array->data->id][0]["mirakl_product_id"];
                $offer_name=$bc_product_array->data->name;
                $offer_sku=$bc_product_array->data->sku;
                $offer_price=$bc_product_array->data->price;
                $offer_discount_price=$bc_product_array->data->sale_price;
                $offer_quantity=$bc_product_array->data->inventory_level;

                if (isset($offer_import_id)){

                    $check_offers_qry="select * from tbl_bc_mirakl_offers where tbl_stores_id = '".$current_store_id."' and offer_sku = '".$offer_sku."' and is_update=0 and is_active=1 and is_deleted=0";
                    $check_result_qry = $conn->query($check_offers_qry);
                 

                    if ($check_result_qry->num_rows > 0) {
      
                        $select_sync_status_result 	= $check_result_qry->fetch_assoc();
                  
                        if(($select_sync_status_result['offer_sku'] == $offer_sku && $select_sync_status_result['is_active'] == 1 && $select_sync_status_result['sync_status'] == 'COMPLETE') && (($select_sync_status_result['price'] !=  $offer_price) || ($select_sync_status_result['discount_price'] !=  $offer_discount_price) || ($select_sync_status_result['quantity'] !=  $offer_quantity))) {
                           
                            $offers_tbl_update="UPDATE tbl_bc_mirakl_offers SET is_update = 1, is_active = 0,is_deleted = 1,updated_at = ".time()." WHERE tbl_bc_mirakl_offer_id=".$select_sync_status_result['tbl_bc_mirakl_offer_id'];
                        
                            $offer_table_update_qry=$conn->query($offers_tbl_update);
                          
                            
                            $offer_import_save_sql = "INSERT INTO tbl_bc_mirakl_offers (tbl_bc_mirakl_product_id, mirakl_product_id ,tbl_stores_id,tbl_import_id, offer_name , offer_sku,price, discount_price, shop_id, is_active, is_deleted, created_at,quantity) VALUES ('".$tbl_bc_mirakl_product_id."','".$mirakl_product_id."','".$current_store_id."','".$offer_import_id."','".$offer_name."','".$offer_sku."','".$offer_price."','".$offer_discount_price."','".$shop_id."',1,0,".time().",'".$offer_quantity."')";
                            if ($conn->query($offer_import_save_sql) === TRUE) {
                                $is_product_sync = 1;
                            } else {
                                echo "Error: " . $offer_import_save_sql . "<br>" . $conn->error;
                                }
                        } 
                        
                        }else{
                           
                            $offer_import_save_sql = "INSERT INTO tbl_bc_mirakl_offers (tbl_bc_mirakl_product_id, mirakl_product_id ,tbl_stores_id,tbl_import_id, offer_name , offer_sku,price, discount_price, shop_id, is_active, is_deleted, created_at,quantity) VALUES ('".$tbl_bc_mirakl_product_id."','".$mirakl_product_id."','".$current_store_id."','".$offer_import_id."','".$offer_name."','".$offer_sku."','".$offer_price."','".$offer_discount_price."','".$shop_id."',1,0,".time().",'".$offer_quantity."')";
                            if ($conn->query($offer_import_save_sql) === TRUE) {
                                $is_product_sync = 1;
                            } else {
                                echo "Error: " . $offer_import_save_sql . "<br>" . $conn->error;
                                }
                        }
                    }
                }
           
           return $is_product_sync;
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

die();
?>