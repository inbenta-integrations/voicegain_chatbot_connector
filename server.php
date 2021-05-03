<?php

require "vendor/autoload.php";

use Inbenta\VoicegainConnector\VoicegainConnector;
use Klein\Klein as Router;

header('Content-Type: application/json');

// Instance new Connector
$appPath = __DIR__ . '/';
$app = new VoicegainConnector($appPath);

// Instance the Router
$router = new Router();

// Start session
$router->respond('POST', '/?', function () use ($app) {
    $response = $app->startConversation();
    return json_encode($response, JSON_UNESCAPED_SLASHES);
});

// Receive messages
$router->respond('PUT', '/[:csid]', function ($request) use ($app) {
    $app->setCsid($request->csid);
    $app->setSequence($request->__get('seq'));
    $app->setResponseStructure();
    $response = $app->handleRequest();
    return json_encode($response, JSON_UNESCAPED_SLASHES);
});

// Disconnect
$router->respond('DELETE', '/[:csid]', function ($request) use ($app) {
    $app->setCsid($request->csid);
    $app->setSequence($request->__get('seq'));
    $response = $app->disconnect();
    return json_encode($response, JSON_UNESCAPED_SLASHES);
});

$router->dispatch();
