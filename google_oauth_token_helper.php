<?php

function googleTokenPath()
{
    return __DIR__ . '/token.json';
}

function googleLoadTokenData($tokenPath = null)
{
    $tokenPath = $tokenPath ?: googleTokenPath();
    if (!file_exists($tokenPath)) {
        return null;
    }

    $tokenData = json_decode((string)file_get_contents($tokenPath), true);
    return is_array($tokenData) ? $tokenData : null;
}

function googlePersistToken(Google\Client $client, array $tokenData = null, $tokenPath = null)
{
    $tokenPath = $tokenPath ?: googleTokenPath();
    $currentToken = googleLoadTokenData($tokenPath) ?? [];
    $latestToken = $tokenData ?? $client->getAccessToken();

    if (!is_array($latestToken)) {
        $latestToken = [];
    }

    if (empty($latestToken['refresh_token']) && !empty($currentToken['refresh_token'])) {
        $latestToken['refresh_token'] = $currentToken['refresh_token'];
    }

    file_put_contents($tokenPath, json_encode($latestToken));

    return $latestToken;
}

function googleIsInvalidGrantError($error)
{
    if ($error instanceof Throwable) {
        $message = $error->getMessage();
    } elseif (is_array($error)) {
        $message = (string)($error['error'] ?? '') . ' ' . (string)($error['error_description'] ?? '');
    } else {
        $message = (string)$error;
    }

    $message = strtolower($message);
    return strpos($message, 'invalid_grant') !== false || strpos($message, 'token has been expired or revoked') !== false;
}

function googleForgetToken($tokenPath = null)
{
    $tokenPath = $tokenPath ?: googleTokenPath();
    if (file_exists($tokenPath)) {
        unlink($tokenPath);
    }
}
