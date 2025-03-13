<?php
require_once '../vendor/autoload.php';

use Mirakl\MCI\Shop\Client\ShopApiClient as Mirakl_Client;
use Mirakl\MCI\Shop\Request\Hierarchy\GetHierarchiesRequest;

if(isset($_REQUEST['mirakl_url']) && isset($_REQUEST['mirakl_seller_api'])){
    $mirakl_url         = trim($_REQUEST['mirakl_url']);
    $mirakl_seller_api  = trim($_REQUEST['mirakl_seller_api']);

    try {
        $client     = new Mirakl_Client($mirakl_url, $mirakl_seller_api);
        $request    = new GetHierarchiesRequest();
        $result     = $client->getHierarchies($request);
        echo "Authorized";
    } catch (\Exception $e) {
        echo "Unauthorized";
    }
}else{
    echo "Unauthorized";
}
?>