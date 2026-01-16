<?php
// Certifique-se de que a pasta 'vendor' está na mesma pasta que este ficheiro
require_once __DIR__ . '/vendor/autoload.php';

$client = new Google\Client();
$client->setApplicationName('ERP Treinamentos');

$httpClient = new \GuzzleHttp\Client(['verify' => false]);
$client->setHttpClient($httpClient);

$client->setAuthConfig('credentials.json');
$client->addScope(Google_Service_Calendar::CALENDAR);
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

$tokenPath = __DIR__ . '/token.json';
if (file_exists($tokenPath)) {
    $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));
}

if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
}

$service = new Google_Service_Calendar($client);