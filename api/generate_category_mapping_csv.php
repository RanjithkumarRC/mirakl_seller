<?php
include "../include/config_new.php";
ini_set('max_execution_time', 0);
header('Content-Type: text/html; charset=utf-8');
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

use Bigcommerce\Api\Client as Bigcommerce;
use BigCommerce\ApiV3\Client as BigcommerceV3;
use BigCommerce\ApiV3\ResourceModels\Catalog\Product\ProductImage;
use BigCommerce\ApiV3\Api\Catalog\Brands\BrandMetafieldsApi;

use Mirakl\MCI\Shop\Client\ShopApiClient;
use Mirakl\MCI\Shop\Request\Attribute\GetAttributesRequest;

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

foreach($store_array as $ind_stores){
    $storeHash          = $ind_stores['storehash'];
    $access_token       = $ind_stores['access_token'];
    $miraklSellerApiURL = $ind_stores['mirakl_api_url'];
    $miraklSellerApiKey = $ind_stores['mirakl_seller_api_key'];
    $current_store_id   = $ind_stores['tbl_stores_id'];
    $shop_id            = $ind_stores['shop_id'];
}

if(isset($storeHash) && isset($access_token) && isset($miraklSellerApiURL) && isset($miraklSellerApiKey) && isset($current_store_id)){
    configureBCApiNew($storeHash,$access_token);
    $client_id      = clientId();
    $bc_v3_api      = new BigcommerceV3($storeHash, $client_id, $access_token);
    $mirakl_seller  = new ShopApiClient($miraklSellerApiURL, $miraklSellerApiKey, $shop_id);

    $final_csv_array = array();
    $get_product_count_api  = 'https://api.bigcommerce.com/stores/'.$storeHash.'/v2/products/count';
    $method                 = 'GET';
    $header                 = ["Accept: application/json","Content-Type: application/json","X-Auth-Token: ".$access_token];

    $bc_product_count_response          = BC_API_Call($get_product_count_api,$method,$header);
    $bc_product_count_response_decoded  = json_decode($bc_product_count_response);

    $total_count        = $bc_product_count_response_decoded->count;
    $total_page_count   = ceil($total_count/250);

    $bc_product_array = array();
    for($loop=1;$loop<=$total_page_count;$loop++){ 
        $get_product_api = 'https://api.bigcommerce.com/stores/'.$storeHash.'/v3/catalog/products?limit=250&include=custom_fields&page='.$loop;
    
        $bc_product_response            = BC_API_Call($get_product_api,$method,$header);
        $bc_product_response_decoded    = json_decode($bc_product_response);
        $bc_product_array               = array_merge($bc_product_array,$bc_product_response_decoded->data);
    }

    $file_name      = 'products_'.$current_store_id.'_'.time().'.csv';
    $csv_filename   = '/var/www/html/mirakl_seller/product_csvFiles/'.$file_name;
    
    // PM11
    $products_attributes        = new GetAttributesRequest();
    $products_attribute_result  = $mirakl_seller->getAttributes($products_attributes);
    $column_heading             = [];
    foreach($products_attribute_result as $products_attributes) {
        $products_attribute = json_decode($products_attributes);
        $column_heading[] = $products_attribute->code;
    }
    // $column_heading = array('category code', 'SHOP_SKU', 'TITLE', 'Product Description', 'main image', 'Size', 'Colour', 'SKU','UPC','Brand','Country of Origin','PDP Template','Enable Product Reviews','Enable Wishlist','Enable Giftwrap','Enable HBC Points','Enable Quicklook','REFINEMENT - Size (EN)','Colour Group (EN)');//,'Swatch Hex Value'
    array_push($final_csv_array,$column_heading);

    if(count($bc_product_array) > 0){
        // $countryOfOrigin        = countryOfOrigin();
        // $PDPTemplate            = PDPTemplate();
        // $enableProductReviews   = enableProductReviews();
        // $enableWishlist         = enableWishlist();
        // $enableGiftwrap         = enableGiftwrap();
        // $enableHBCPoints        = enableHBCPoints();
        // $enableQuicklook        = enableQuicklook();
        foreach($bc_product_array as $individual_product){
            $marketplace_product    = 0;
            // $size                   = '';
            // $refinement_size        = '';
            // $colour                 = '';
            // $shoe_width             = '';
            foreach($individual_product->custom_fields as $check_marketplace_product){
                if($check_marketplace_product->name == 'marketplace' && $check_marketplace_product->value == 'true'){
                    $marketplace_product = 1;
                }
                // if($check_marketplace_product->name == 'US'){
                //     $size               = $check_marketplace_product->name.' '.$check_marketplace_product->value;
                //     $refinement_size    = $check_marketplace_product->value;
                // }
                // if($check_marketplace_product->name == 'Colour'){
                //     $colour             = $check_marketplace_product->value;
                // }
                // if($check_marketplace_product->name == 'Shoe Width'){
                //     $shoe_width         = $check_marketplace_product->value;
                // }
            }
            // if($shoe_width != ''){
            //     $refinement_size    .= $shoe_width;
            // }

            if($marketplace_product == 1){
                $individual_row_data = array();

                $bc_product_id          = $individual_product->id;
                $bc_product_categories  = $individual_product->categories;
                foreach($bc_product_categories as $bc_product_categorie){
                    $getCategory    = Bigcommerce::getCategory($bc_product_categorie);
                    $category_name  = $getCategory->name;
                }
                // $brandResponse      = $bc_v3_api->catalog()->brand($individual_product->brand_id)->get();
                // $brand_name         = $brandResponse->getBrand()->name;

                $imageResponse      = $bc_v3_api->catalog()->product($bc_product_id)->images()->getAll();
                $product_image      = $imageResponse->getProductImages()[0]->url_standard;

                $variantsResponse   = $bc_v3_api->catalog()->product($bc_product_id)->variants()->getAll();
                // echo '<pre>'; print_r($variantsResponse); echo '</pre>';

                if(count($variantsResponse->getProductVariants()) > 1){
                    foreach($variantsResponse->getProductVariants() as $product_varient){
                        foreach($column_heading as $att_key => $product_attribute){
                            if(strtolower($product_attribute) == 'category code'){
                                $individual_row_data[$att_key]  = $category_name; // Category Code
                            }
                            if(strtolower($product_attribute) == 'title'){
                                $individual_row_data[$att_key]  = $individual_product->name; // TITLE
                            }
                            if(strtolower($product_attribute) == 'product description'){
                                $individual_row_data[$att_key]  = strip_tags($individual_product->description); // Description
                            }
                            if(strtolower($product_attribute) == 'main image'){
                                if($product_varient->image_url != ''){
                                    $bc_product_image = $product_varient->image_url;
                                }else{
                                    $bc_product_image = $product_image;
                                }
                                $individual_row_data[$att_key] = $bc_product_image; // main image
                            }
                            if(strtolower($product_attribute) == 'shop_sku'){
                                $individual_row_data[$att_key]  = $product_varient->sku;
                            }
                            // if(strtolower($product_attribute) == 'upc'){
                            //     $individual_row_data[$att_key]  = $individual_product->upc; // upc
                            // }
                            // if(strtolower($product_attribute) == 'brand'){
                            //     $individual_row_data[$att_key]  = strtolower($brand_name); // brand
                            // }
                            // if(strtolower($product_attribute) == 'country of origin'){
                            //     $individual_row_data[$att_key]  = $countryOfOrigin; // country of origin
                            // }
                            // if(strtolower($product_attribute) == 'pdp template'){
                            //     $individual_row_data[$att_key]  = $PDPTemplate; // PDP Template
                            // }
                            // if(strtolower($product_attribute) == 'enable product reviews'){
                            //     $individual_row_data[$att_key]  = $enableProductReviews; // Enable Product Reviews
                            // }
                            // if(strtolower($product_attribute) == 'enable wishlist'){
                            //     $individual_row_data[$att_key]  = $enableWishlist; // Enable Wishlist
                            // }
                            // if(strtolower($product_attribute) == 'enable giftwrap'){
                            //     $individual_row_data[$att_key]  = $enableGiftwrap; // Enable Giftwrap
                            // }
                            // if(strtolower($product_attribute) == 'enable hbc points'){
                            //     $individual_row_data[$att_key]  = $enableHBCPoints; // Enable HBC Points
                            // }
                            // if(strtolower($product_attribute) == 'enable quicklook'){
                            //     $individual_row_data[$att_key]  = $enableQuicklook; // Enable Quicklook
                            // }
                            // if(strtolower($product_attribute) == 'colour'){
                            //     $individual_row_data[$att_key]  = strtoupper($colour); // Colour
                            // }
                            // if(strtolower($product_attribute) == 'swatch hex value'){
                            //     $individual_row_data[$att_key]  = 'FFFFFF'; // Swatch Hex Value
                            // }
                            if(strtolower($product_attribute) == 'sku'){
                                $individual_row_data[$att_key] = $individual_product->sku;
                            }
                            // if(strtolower($product_attribute) == 'colour group (en)'){
                            //     $individual_row_data[$att_key]  = ucfirst(strtolower($colour)); // Colour Group (EN)
                            // }
                            // if(strtolower($product_attribute) == 'refinement - size (en)'){
                            //     $individual_row_data[$att_key]  = $refinement_size; // REFINEMENT - Size (EN)
                            // }
                        }

                        foreach($product_varient->option_values as $variant_option_values){
                            // echo '<pre>'; print_r($variant_option_values); echo '<pre>';
                            if(in_array($variant_option_values->option_display_name,$column_heading)){
                                $attrbt_key = array_search ($variant_option_values->option_display_name, $column_heading);
                                // if($variant_option_values->option_display_name == 'Color'){
                                //     $individual_row_data[$attrbt_key] = $variant_option_values->label; //  variant options
                                // }
                                // $size_array = explode(',', $variant_option_values->label);
                                // $size_str = '';
                                // foreach($size_array as $size_value){
                                //     if(strpos($size_value, 'US ') !== false){
                                //         $size_str   = trim($size_value);
                                //     }
                                // }
                                if(!empty($attrbt_key)){
                                    $individual_row_data[$attrbt_key] = $variant_option_values->label; // variant options
                                }
                            }
                        }

                        $missing_attribute = array_diff_key($column_heading,$individual_row_data);
                        foreach($missing_attribute as $miss_key => $miss_value){
                            $individual_row_data[$miss_key] = '';
                        }

                        ksort($individual_row_data);
                        array_push($final_csv_array,$individual_row_data);
                    }
                }else{
                    foreach($column_heading as $att_key => $product_attribute){
                        if(strtolower($product_attribute) == 'category code'){
                            $individual_row_data[$att_key]  = $category_name; // Category Code
                        }
                        if(strtolower($product_attribute) == 'title'){
                            $individual_row_data[$att_key]  = $individual_product->name; // TITLE
                        }
                        if(strtolower($product_attribute) == 'product description'){
                            $individual_row_data[$att_key]  = strip_tags($individual_product->description); // Description
                        }
                        if(strtolower($product_attribute) == 'main image'){
                            $individual_row_data[$att_key]  = $product_image; // main image
                        }
                        if(strtolower($product_attribute) == 'shop_sku'){
                            $individual_row_data[$att_key]  = $individual_product->sku; // SHOP_SKU
                        }
                        // if(strtolower($product_attribute) == 'upc'){
                        //     $individual_row_data[$att_key]  = $individual_product->upc; // upc
                        // }
                        // if(strtolower($product_attribute) == 'brand'){
                        //     $individual_row_data[$att_key]  = strtolower($brand_name); // brand
                        // }
                        // if(strtolower($product_attribute) == 'country of origin'){
                        //     $individual_row_data[$att_key]  = $countryOfOrigin; // country of origin
                        // }
                        // if(strtolower($product_attribute) == 'pdp template'){
                        //     $individual_row_data[$att_key]  = $PDPTemplate; // PDP Template
                        // }
                        // if(strtolower($product_attribute) == 'enable product reviews'){
                        //     $individual_row_data[$att_key]  = $enableProductReviews; // Enable Product Reviews
                        // }
                        // if(strtolower($product_attribute) == 'enable wishlist'){
                        //     $individual_row_data[$att_key]  = $enableWishlist; // Enable Wishlist
                        // }
                        // if(strtolower($product_attribute) == 'enable giftwrap'){
                        //     $individual_row_data[$att_key]  = $enableGiftwrap; // Enable Giftwrap
                        // }
                        // if(strtolower($product_attribute) == 'enable hbc points'){
                        //     $individual_row_data[$att_key]  = $enableHBCPoints; // Enable HBC Points
                        // }
                        // if(strtolower($product_attribute) == 'enable quicklook'){
                        //     $individual_row_data[$att_key]  = $enableQuicklook; // Enable Quicklook
                        // }
                        // if(strtolower($product_attribute) == 'swatch hex value'){
                        //     $individual_row_data[$att_key]  = 'FFFFFF'; // Swatch Hex Value
                        // }
                        // if(strtolower($product_attribute) == 'size'){
                        //     $individual_row_data[$att_key]  = $size; // Size
                        // }
                        // if(strtolower($product_attribute) == 'colour'){
                        //     $individual_row_data[$att_key]  = strtoupper($colour); // Colour
                        // }
                        // if(strtolower($product_attribute) == 'colour group (en)'){
                        //     $individual_row_data[$att_key]  = ucfirst(strtolower($colour)); // Colour Group (EN)
                        // }
                        // if(strtolower($product_attribute) == 'sku'){
                        //     $individual_row_data[$att_key]  = $individual_product->sku; // SHOP_SKU
                        // }
                        // if(strtolower($product_attribute) == 'refinement - size (en)'){
                        //     $individual_row_data[$att_key]  = $refinement_size; // REFINEMENT - Size (EN)
                        // }
                    }

                    $missing_attribute = array_diff_key($column_heading,$individual_row_data);
                    foreach($missing_attribute as $miss_key => $miss_value){
                        $individual_row_data[$miss_key] = '';
                    }

                    ksort($individual_row_data);
                    array_push($final_csv_array,$individual_row_data);
                }
            }
        }
    }

    // echo '<pre>'; print_r($final_csv_array); echo '</pre>';
    // die();

    if(!empty($individual_row_data)){
        $csv_file = fopen($csv_filename, 'w');
        foreach ($final_csv_array as $fields) {
            fputcsv($csv_file, $fields);
        }
        fclose($csv_file);
        echo $csv_filename;
    }else{
        echo "No product to sync (or) All product are already sync";
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
die();
?>