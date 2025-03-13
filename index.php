<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
ini_set('max_execution_time', 0);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use Bigcommerce\Api\Client as Bigcommerce;
use Firebase\JWT\JWT;
use Guzzle\Http\Client;
use Handlebars\Handlebars;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


// Load from .env file
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$conn = new mysqli(getenv('DATABASE_SERVER'), getenv('DATABASE_USERNAME'), getenv('DATABASE_PASSWORD'), getenv('DATABASE_NAME'));
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$app = new Application();
$app['debug'] = true;

$app->get('/load', function (Request $request) use ($app,$conn) {

	$data = verifySignedRequest($request->get('signed_payload'));

	if (empty($data)) {
		return 'Invalid signed_payload.';
	}
	$key = getUserKey($data['store_hash'], $data['user']['email']);
	$storehash = $data['store_hash'];

	$select_store_sql = "SELECT * FROM tbl_stores where storehash='".$storehash."' and is_active=1 and is_deleted=0";
	$store_result = $conn->query($select_store_sql);

	if ($store_result->num_rows > 0) {
		while($store_row = $store_result->fetch_assoc()) {
			$access_token = $store_row["access_token"];
			$tbl_store_id = $store_row["tbl_stores_id"];

			$api = 'https://api.bigcommerce.com/stores/'.$storehash.'/v2/store';
			$method = 'GET';
			$header = ["Accept: application/json","Content-Type: application/json","X-Auth-Token: ".$access_token];
			$BC_post_data = [];
			$BC_post_data_json = json_encode($BC_post_data);
			$store_details = BC_API_Call($api,$method,$header,$BC_post_data_json);
			$store_details_decoded = json_decode($store_details);
			$bc_store_url = $store_details_decoded->domain;
			$update_store_sql = "UPDATE tbl_stores SET updated_at='".time()."', bc_store_url='".$bc_store_url."' WHERE tbl_stores_id =".$tbl_store_id;
			$conn->query($update_store_sql);
			
			if($store_row["mirakl_api_url"] != '' && $store_row["mirakl_seller_api_key"] != ''){
				$encode_storehash = base64_encode($storehash);
				header("Location: dashboard?data=".$encode_storehash);
				die();
			}
		}
	}

	// Render the template with the recently purchased products fetched from the BigCommerce server.
	$encode_storehash = base64_encode($data['store_hash']);
	$htmlContent =  (new Handlebars())->render(
		file_get_contents('templates/configurator.html'),
		['storeHash' => $data['store_hash'], "accesstoken" => $access_token, 'encode_storehash' => $encode_storehash]
	);
	$htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.
	
	return $htmlContent;
});

$app->get('/dashboard', function (Request $request) use ($app,$conn) {

    $encode_current_storehash   =  $request->get('data');
    $decode_current_storehash   =  base64_decode($encode_current_storehash);

    $select_store_qry = "SELECT * FROM tbl_stores where storehash = '".$decode_current_storehash."' and is_active=1 and is_deleted=0";
    $store_result_qry = $conn->query($select_store_qry);

    $tbl_store_id = 0;
    if ($store_result_qry->num_rows > 0) {
        while($store_row_qry = $store_result_qry->fetch_assoc()) {
            $tbl_store_id   = $store_row_qry["tbl_stores_id"];
        }
    }

	$select_products_sql = "SELECT * FROM tbl_bc_mirakl_products where tbl_stores_id = ".$tbl_store_id." and is_active='1' and is_deleted='0'";
	$product_result = $conn->query($select_products_sql);
	$product_count = $product_result->num_rows;

	$select_offer_sql = "SELECT * FROM tbl_bc_mirakl_offers where tbl_stores_id = ".$tbl_store_id." and is_active='1' and is_deleted='0'";
	$offer_result = $conn->query($select_offer_sql);
	$offer_count = $offer_result->num_rows;

	$select_ordersync_sql = "SELECT * FROM tbl_seller_mirakl_orders where tbl_stores_id = ".$tbl_store_id;
	$orders_result = $conn->query($select_ordersync_sql);
	$orders_count = $orders_result->num_rows;

	// Render the template with the recently purchased products fetched from the BigCommerce server.
	$htmlContent =  (new Handlebars())->render(
		file_get_contents('templates/dashboard.html'),

		['product_count' => $product_count, "orders_count" => $orders_count, "offer_count" => $offer_count, 'storehash' => $encode_current_storehash]
	);
    
    $htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.

    return $htmlContent;
});

$app->get('/settings', function (Request $request) use ($app,$conn) {
	$encode_storehash	= $request->get('data');
	$decode_storehash	= base64_decode($encode_storehash);
	$storeHash			= $decode_storehash;

	$select_store_sql = "SELECT mirakl_api_url, mirakl_seller_api_key, access_token FROM tbl_stores where storehash='".$decode_storehash."' and is_active='1' and is_deleted='0'";
	$store_result = $conn->query($select_store_sql);

	if ($store_result->num_rows > 0) {
		while($store_row = $store_result->fetch_assoc()) {
			$mirakl_api_url = $store_row["mirakl_api_url"];
			$mirakl_api_key = $store_row["mirakl_seller_api_key"];
			$access_token = $store_row["access_token"];
		}
	}

	// Render the template with the recently purchased products fetched from the BigCommerce server.
	$htmlContent =  (new Handlebars())->render(
		file_get_contents('templates/settings.html'),
		['storeHash' => $storeHash, 'mirakl_api_url' => $mirakl_api_url, 'mirakl_api_key' => $mirakl_api_key, 'access_token' => $access_token, 'data_storehash' => $encode_storehash]
	);
	$htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.
	
	return $htmlContent;
});

$app->get('/product-sync-BCtoMirakl', function (Request $request) use ($app,$conn) {
	$encode_current_storehash	=  $request->get('data');
	$decode_current_storehash	=  base64_decode($encode_current_storehash);

	$select_store_sql = "SELECT tbl_stores_id FROM tbl_stores where storehash ='".$decode_current_storehash."' and is_active=1 and is_deleted=0";
	$store_result = $conn->query($select_store_sql);

	$tbl_stores_id=0;
	if ($store_result->num_rows > 0) {
		while($store_row = $store_result->fetch_assoc()) {
			$tbl_stores_id = $store_row["tbl_stores_id"];
		}
	}

	$select_import_files_sql = "SELECT * FROM tbl_seller_productToMirakl_files where tbl_stores_id = ".$tbl_stores_id." and is_active='1' and is_deleted='0' order by tbl_seller_productToMirakl_files_id desc";
	$import_files_result = $conn->query($select_import_files_sql);
	$import_files_array = [];

	if($import_files_result->num_rows > 0){
		$loops_index = 1;
		while($import_files_row = $import_files_result->fetch_assoc()) {
			$file_url = $import_files_row['file_url'];
			$download_file_url = str_replace('/var/www/html','',$file_url);
			$import_files_array[$loops_index]['index'] 		= $loops_index;
			$import_files_array[$loops_index]['import_id'] 	= $import_files_row['import_id'];
			$import_files_array[$loops_index]['file_name'] 	= $import_files_row['file_name'];
			$import_files_array[$loops_index]['file_url'] 	= $download_file_url;
			$import_files_array[$loops_index]['sync_status']= $import_files_row['sync_status'];
			$import_files_array[$loops_index]['created'] = date('d/m/Y H:i:s', $import_files_row['created_at']);
			$loops_index++;
		}
	}

	// Render the template with the recently purchased products fetched from the BigCommerce server.
	$htmlContent =  (new Handlebars())->render(
		file_get_contents('templates/product-sync-BCtoMirakl.html'),
		['import_files_array' => $import_files_array ,'storehash' => $encode_current_storehash]
	);
	$htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.

	return $htmlContent;
});

$app->get('/offer-sync-BCtoMirakl', function (Request $request) use ($app,$conn) {
	$encode_current_storehash	=  $request->get('data');
	$decode_current_storehash	=  base64_decode($encode_current_storehash);

	$select_store_sql = "SELECT tbl_stores_id ,shop_id FROM tbl_stores where storehash ='".$decode_current_storehash."' and is_active=1 and is_deleted=0";
	$store_result = $conn->query($select_store_sql);

	$tbl_stores_id=0;
	$shop_id=0;
	if ($store_result->num_rows > 0) {
		while($store_row = $store_result->fetch_assoc()) {
			$tbl_stores_id = $store_row["tbl_stores_id"];
			$shop_id = $store_row["shop_id"];
			$is_variant=$store_row["is_variant"];
		}
	}

	// $select_import_files_sql = "SELECT * FROM tbl_bc_mirakl_offers inner join tbl_bc_mirakl_products on tbl_bc_mirakl_offers.offer_sku=tbl_bc_mirakl_products.product_sku where tbl_bc_mirakl_products.tbl_stores_id=".$tbl_stores_id." and tbl_bc_mirakl_products.sync_status='COMPLETE' and tbl_bc_mirakl_products.is_error=0 and tbl_bc_mirakl_products.shop_id='".$shop_id."' and tbl_bc_mirakl_products.is_active=1 and tbl_bc_mirakl_products.is_deleted=0 and tbl_bc_mirakl_offers.is_active=1 and tbl_bc_mirakl_offers.is_deleted=0";

	// $select_import_files_sql = "SELECT * FROM tbl_bc_mirakl_offers";
	$select_import_files_sql="SELECT tbl_bc_mirakl_offers.* FROM tbl_bc_mirakl_offers inner join tbl_bc_mirakl_products on (CASE when tbl_bc_mirakl_products.is_variant = 0 then tbl_bc_mirakl_offers.offer_sku=tbl_bc_mirakl_products.product_sku when tbl_bc_mirakl_products.is_variant = 1 then tbl_bc_mirakl_offers.variant_sku=tbl_bc_mirakl_products.variant_sku END) where tbl_bc_mirakl_products.tbl_stores_id=".$tbl_stores_id." and tbl_bc_mirakl_products.sync_status='COMPLETE' and tbl_bc_mirakl_products.is_error=0 and tbl_bc_mirakl_products.shop_id='".$shop_id."' and tbl_bc_mirakl_products.is_active=1 and tbl_bc_mirakl_products.is_deleted=0 and tbl_bc_mirakl_offers.is_active=1 and tbl_bc_mirakl_offers.is_deleted=0";
	$import_files_result = $conn->query($select_import_files_sql);

	$import_files_array = [];

	if($import_files_result->num_rows > 0){
		$loops_index = 1;
		while($import_files_row = $import_files_result->fetch_assoc()) {
			$import_files_array[$loops_index]['index'] = $loops_index;
			// echo $import_files_row;
			$import_files_array[$loops_index]['import_id'] = $import_files_row['tbl_import_id'];
			$import_files_array[$loops_index]['product_name'] = $import_files_row['offer_name'];
			$import_files_array[$loops_index]['offer_sku'] = $import_files_row['offer_sku'];
			$import_files_array[$loops_index]['qty'] = $import_files_row['quantity'];
			$import_files_array[$loops_index]['offer_price'] = $import_files_row['price'];
			$import_files_array[$loops_index]['offer_discount_price'] = $import_files_row['discount_price'];
			$import_files_array[$loops_index]['variant_sku'] = $import_files_row['variant_sku'];
			$import_files_array[$loops_index]['created'] = date('d/m/Y H:i:s', $import_files_row['created_at']);
			$loops_index++;
		}
	}

	// Render the template with the recently purchased products fetched from the BigCommerce server.
	$htmlContent =  (new Handlebars())->render(
		file_get_contents('templates/offer-sync-BCtoMirakl.html'),
		['import_files_array' => $import_files_array ,'storehash' => $encode_current_storehash]
	);
	$htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.

	return $htmlContent;
});

// Start Order Sync BigCtoMirakl
$app->get('/order-sync-BCtoMirakl', function (Request $request) use ($app,$conn) {

	$encode_current_storehash	=  $request->get('data');
	$decode_current_storehash	=  base64_decode($encode_current_storehash);

	$select_store_sql = "SELECT tbl_stores_id FROM tbl_stores where storehash ='".$decode_current_storehash."' and is_active=1 and is_deleted=0";
	$store_result = $conn->query($select_store_sql);

	$tbl_stores_id=0;
	if ($store_result->num_rows > 0) {
		while($store_row = $store_result->fetch_assoc()) {
			$tbl_stores_id = $store_row["tbl_stores_id"];
		}
	}

	$select_seller_orders_sql = "SELECT tbl_seller_mirakl_orders_id,mirakl_order_id,mirakl_order_status,sync_status_bc,sync_status_mirakl,bc_order_id,bc_order_status FROM tbl_seller_mirakl_orders where tbl_stores_id = ".$tbl_stores_id." order by tbl_seller_mirakl_orders_id desc";
	$seller_order_result = $conn->query($select_seller_orders_sql);
	$seller_order_array = [];

	$bc_order_status = [];
	$bc_order_status[0]	= 'Incomplete';
	$bc_order_status[1]	= 'Pending';
	$bc_order_status[2]	= 'Shipped';
	$bc_order_status[3]	= 'Partially Shipped';
	$bc_order_status[4]	= 'Refunded';
	$bc_order_status[5]	= 'Cancelled';
	$bc_order_status[6]	= 'Declined';
	$bc_order_status[7]	= 'Awaiting Payment';
	$bc_order_status[8]	= 'Awaiting Pickup';
	$bc_order_status[9]	= 'Awaiting Shipment';
	$bc_order_status[10]	= 'Completed';
	$bc_order_status[11]	= 'Awaiting Fulfillment';
	$bc_order_status[12]	= 'Manual Verification Required';
	$bc_order_status[13]	= 'Disputed';
	$bc_order_status[14]	= 'Partially Refunded';
	
	if ($seller_order_result->num_rows > 0) {
		$loops_index = 1;
		while($seller_order_row = $seller_order_result->fetch_assoc()) {
			$mirakl_order_status	= str_replace('_',' ',$seller_order_row['mirakl_order_status']);
			$seller_order_array[$loops_index]['index'] = $loops_index;
			$seller_order_array[$loops_index]['mirakl_order_id'] = $seller_order_row['mirakl_order_id'];
			$seller_order_array[$loops_index]['mirakl_order_status'] = $mirakl_order_status;
			$seller_order_array[$loops_index]['sync_status_bc'] = isset($seller_order_row['sync_status_bc']) ? $seller_order_row['sync_status_bc'] : '-';
			$seller_order_array[$loops_index]['sync_status_mirakl'] = $seller_order_row['sync_status_mirakl'];
			$seller_order_array[$loops_index]['bc_order_id'] = isset($seller_order_row['bc_order_id']) ? $seller_order_row['bc_order_id'] : '-';
			$seller_order_array[$loops_index]['bc_order_status'] = isset($bc_order_status[$seller_order_row['bc_order_status']])?$bc_order_status[$seller_order_row['bc_order_status']]:'-';
			$loops_index++;
		}
	}
	// Render the template with the recently purchased products fetched from the BigCommerce server.
	$htmlContent =  (new Handlebars())->render(
		file_get_contents('templates/order-sync-BCtoMirakl.html'),
		['seller_order_array' => $seller_order_array, 'storehash' => $encode_current_storehash]
	);
	$htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.
	
	return $htmlContent;
});
// End Order sync BigCtoMirakl

$app->get('/auth_callback', function (Request $request) use ($app,$conn) {
	$payload = array(
		'client_id' => clientId(),
		'client_secret' => clientSecret(),
		'redirect_uri' => callbackUrl(),
		'grant_type' => 'authorization_code',
		'code' => $request->get('code'),
		'scope' => $request->get('scope'),
		'context' => $request->get('context'),
	);

	$client = new Client(bcAuthService());
	$req = $client->post('/oauth2/token', array(), $payload, array(
		'exceptions' => false,
	));
	$resp = $req->send();

	if ($resp->getStatusCode() == 200) {
		$data = $resp->json();
		list($context, $storeHash) = explode('/', $data['context'], 2);
		$key = getUserKey($storeHash, $data['user']['email']);

		$bc_store_id = $data["user"]['id'];
		$access_token = $data["access_token"];
		$username = $data["user"]['username'];
		$email = $data["user"]['email'];
		$account_uuid = $data["account_uuid"];

		$api = 'https://api.bigcommerce.com/stores/'.$storeHash.'/v2/store';
		$method = 'GET';
		$header = ["Accept: application/json","Content-Type: application/json","X-Auth-Token: ".$access_token];
		$BC_post_data = [];
		$BC_post_data_json = json_encode($BC_post_data);
		$store_details = BC_API_Call($api,$method,$header,$BC_post_data_json);
		$store_details_decoded = json_decode($store_details);
		$bc_store_url = $store_details_decoded->domain;

		$select_store_sql = "SELECT * FROM tbl_stores where is_active=1 and is_deleted=0 and storehash='".$storeHash."'";
		$store_result = $conn->query($select_store_sql);

		if ($store_result->num_rows > 0) {
			while($store_row = $store_result->fetch_assoc()) {
				$tbl_store_id = $store_row["tbl_stores_id"];
				$update_store_sql = "UPDATE tbl_stores SET updated_at='".time()."', bc_store_url='".$bc_store_url."', access_token='".$access_token."' WHERE tbl_stores_id =".$tbl_store_id;
				$conn->query($update_store_sql);
				$encode_storehash = base64_encode($storehash);
				header("Location: dashboard?data=".$encode_storehash);
			}
		}else{ 
			$insert_store_sql = "INSERT INTO tbl_stores (storehash, bc_store_id, bc_store_url, access_token, username, email, account_uuid, is_active, is_deleted, created_at, updated_at) VALUES ('".$storeHash."','".$bc_store_id."','".$bc_store_url."','".$access_token."','".$username."','".$email."','".$account_uuid."', '1', '0', ".time().", ".time()." )";
			$conn->query($insert_store_sql);
		}
		
		// Render the template with the recently purchased products fetched from the BigCommerce server.
		$encode_storehash = base64_encode($storeHash);
		$htmlContent =  (new Handlebars())->render(
			file_get_contents('templates/configurator.html'),
			['storeHash' => $storeHash, "accesstoken" => $access_token, 'encode_storehash' => $encode_storehash]
		);
		$htmlContent = str_ireplace('http:', 'https:', $htmlContent); // Ensures we have HTTPS links, which for some reason we don't always get.

		return $htmlContent;
	} else {
		return 'Something went wrong... [' . $resp->getStatusCode() . '] ' . $resp->getBody();
	}

});

// Endpoint for removing users in a multi-user setup
$app->get('/remove-user', function(Request $request) use ($app,$conn) {
	$data = verifySignedRequest($request->get('signed_payload'));
	if (empty($data)) {
		return 'Invalid signed_payload.';
	}

	$storeHash = $data['store_hash'];
    $update_store_sql = "UPDATE tbl_stores SET updated_at='".time()."', is_deleted='1', is_active='0' WHERE storehash='".$storeHash."'";

    $conn->query($update_store_sql);
	
	return '[Remove User] '.$data['user']['email'];
});

/**
 * Configure the static BigCommerce API client with the authorized app's auth token, the client ID from the environment
 * and the store's hash as provided.
 * @param string $storeHash Store hash to point the BigCommece API to for outgoing requests.
 */
function configureBCApi($storeHash)
{
	Bigcommerce::configure(array(
		'client_id' => clientId(),
		'auth_token' => getAuthToken($storeHash),
		'store_hash' => $storeHash
	));
}

function configureBCApiNew($storeHash,$access_token)
{
	Bigcommerce::configure(array(
		'client_id' => clientId(),
		'auth_token' => $access_token,
		'store_hash' => $storeHash
	));
}

/**
 * @param string $storeHash store's hash that we want the access token for
 * @return string the oauth Access (aka Auth) Token to use in API requests.
 */
function getAuthToken($storeHash)
{
	$redis = new Credis_Client('localhost');
	$authData = json_decode($redis->get("stores/{$storeHash}/auth"));
	return $authData->access_token;
}

/**
 * @param string $jwtToken	customer's JWT token sent from the storefront.
 * @return string customer's ID decoded and verified
 */
function getCustomerIdFromToken($jwtToken)
{
	$signedData = JWT::decode($jwtToken, clientSecret(), array('HS256', 'HS384', 'HS512', 'RS256'));
	return $signedData->customer->id;
}

/**
 * This is used by the `GET /load` endpoint to load the app in the BigCommerce control panel
 * @param string $signedRequest Pull signed data to verify it.
 * @return array|null null if bad request, array of data otherwise
 */
function verifySignedRequest($signedRequest)
{
	list($encodedData, $encodedSignature) = explode('.', $signedRequest, 2);

	// decode the data
	$signature = base64_decode($encodedSignature);
	$jsonStr = base64_decode($encodedData);
	$data = json_decode($jsonStr, true);

	// confirm the signature
	$expectedSignature = hash_hmac('sha256', $jsonStr, clientSecret(), $raw = false);
	if (!hash_equals($expectedSignature, $signature)) {
		error_log('Bad signed request from BigCommerce!');
		return null;
	}
	return $data;
}

/**
 * @return string Get the app's client ID from the environment vars
 */
function clientId()
{
	$clientId = getenv('BC_CLIENT_ID');
	return $clientId ?: '';
}

/**
 * @return string Get the app's client secret from the environment vars
 */
function clientSecret()
{
	$clientSecret = getenv('BC_CLIENT_SECRET');
	return $clientSecret ?: '';
}

/**
 * @return string Get the callback URL from the environment vars
 */
function callbackUrl()
{
	$callbackUrl = getenv('BC_CALLBACK_URL');
	return $callbackUrl ?: '';
}

/**
 * @return string Get auth service URL from the environment vars
 */
function bcAuthService()
{
	$bcAuthService = getenv('BC_AUTH_SERVICE');
	return $bcAuthService ?: '';
}

function getUserKey($storeHash, $email)
{
	return "kitty.php:$storeHash:$email";
}

function BC_API_Call($api,$method,$header,$post_fields){
	$curl = curl_init();
	curl_setopt_array($curl, [
	CURLOPT_URL => $api,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_ENCODING => "",
	CURLOPT_MAXREDIRS => 10,
	CURLOPT_TIMEOUT => 30,
	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	CURLOPT_CUSTOMREQUEST => $method,
	CURLOPT_POSTFIELDS => $post_fields,
	CURLOPT_HTTPHEADER => $header,
	]);
	$response = curl_exec($curl);
	$err = curl_error($curl);
	curl_close($curl);
	if ($err) {
		//echo "cURL Error #:" . $err;
		return $err;
	} else {
		//echo $response;
		return $response;
	}
}

$app->run();
