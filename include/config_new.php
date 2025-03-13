<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once '/var/www/html/mirakl_seller/vendor/autoload.php';

use Mirakl\MCI\Shop\Client\ShopApiClient as Mirakl_Client;
use Bigcommerce\Api\Client as Bigcommerce;
use BigCommerce\ApiV3\Client as BigcommerceV3;

$dotenv = new Dotenv\Dotenv('/var/www/html/mirakl_seller/');
$dotenv->load();

$conn = new mysqli(getenv('DATABASE_SERVER'), getenv('DATABASE_USERNAME'), getenv('DATABASE_PASSWORD'), getenv('DATABASE_NAME'));
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

function configureBCApiNew($storeHash,$access_token)
{
	Bigcommerce::configure(array(
		'client_id' => clientId(),
		'auth_token' => $access_token,
		'store_hash' => $storeHash
	));
}
function clientId()
{
	$clientId = getenv('BC_CLIENT_ID');
	return $clientId ?: '';
}
function countryOfOrigin()
{
	$str_countryOfOrigin = getenv('COUNTRY_OF_ORIGIN');
	$countryOfOrigin = str_replace('_',' ',$str_countryOfOrigin);
	return $countryOfOrigin ?: '';
}
function PDPTemplate()
{
	$PDPTemplate = getenv('PDP_TEMPLATE');
	return $PDPTemplate ?: '';
}
function enableProductReviews()
{
	$enableProductReviews = getenv('ENABLE_PRODUCT_REVIEWS');
	return $enableProductReviews ?: '';
}
function enableWishlist()
{
	$enableWishlist = getenv('ENABLE_WISHLIST');
	return $enableWishlist ?: '';
}
function enableGiftwrap()
{
	$enableGiftwrap = getenv('ENABLE_GIFTWRAP');
	return $enableGiftwrap ?: '';
}
function enableHBCPoints()
{
	$enableHBCPoints = getenv('ENABLE_HBC_POINTS');
	return $enableHBCPoints ?: '';
}
function enableQuicklook()
{
	$enableQuicklook = getenv('ENABLE_QUICKLOOK');
	return $enableQuicklook ?: '';
}
function URLSplit($url){
	$split_url = explode("://",$url);
	$store_url = str_replace('/','',$split_url[1]);
	return $store_url;
}
function BigC_API_Call($api,$method,$header){
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

function Mirakl_PRODUCT_POST_API_Call($api,$method,$header,$post_fields){
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
	$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

	curl_close($curl);
	if ($err) {
		$result['status_code'] 	= $httpCode;
		$result['result']		= $err;
		return $result;
	} else {
		$result['status_code'] 	= $httpCode;
		$result['result']		= $response;
		return $result;
	}
}
?>