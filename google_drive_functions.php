<?php
require_once __DIR__ . '/app_config.php';

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google_oauth_token_helper.php';

function driveTokenPath()
{
    $primary = appGoogleTokenPath();
    $fallback = __DIR__ . '/token_drive.json';
    if (file_exists($primary)) {
        return $primary;
    }
    return $fallback;
}

function driveCredentialsPath()
{
    return appGoogleCredentialsPath();
}

function driveGetClient()
{
    $credentialsPath = driveCredentialsPath();
    if (!file_exists($credentialsPath)) {
        throw new RuntimeException('Arquivo credentials.json do Google não encontrado.');
    }

    $client = new Google\Client();
    $client->setAuthConfig($credentialsPath);
    $client->addScope(Google\Service\Drive::DRIVE);
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    $tokenPath = driveTokenPath();
    $tokenData = googleLoadTokenData($tokenPath);
    if (!is_array($tokenData)) {
        throw new RuntimeException('NO_DRIVE_TOKEN');
    }

    $client->setAccessToken($tokenData);

    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if (empty($refreshToken)) {
            throw new RuntimeException('NO_DRIVE_TOKEN');
        }

        $novoToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($novoToken['error'])) {
            if (googleIsInvalidGrantError($novoToken)) {
                googleForgetToken($tokenPath);
                throw new RuntimeException('NO_DRIVE_TOKEN');
            }
            throw new RuntimeException('Falha ao renovar token Google Drive: ' . ($novoToken['error_description'] ?? $novoToken['error'] ?? 'Erro desconhecido'));
        }

        googlePersistToken($client, $novoToken, $tokenPath);
    }

    return $client;
}

function driveGetService()
{
    $client = driveGetClient();
    return new Google\Service\Drive($client);
}

function driveGetAuthStartUrl()
{
    $credentialsPath = driveCredentialsPath();
    if (!file_exists($credentialsPath)) {
        throw new RuntimeException('Arquivo credentials.json do Google não encontrado.');
    }

    $host = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
    $host .= '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\');
    $redirectUri = $host . $path . '/google_drive_auth.php';

    $client = new Google\Client();
    $client->setAuthConfig($credentialsPath);
    $client->addScope(Google\Service\Drive::DRIVE);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setRedirectUri($redirectUri);

    return $client->createAuthUrl();
}

function driveSanitizeQueryValue($value)
{
    return str_replace("'", "\\'", $value);
}

function driveFindOrCreateFolder(Google\Service\Drive $service, $folderName = 'PDF Chamados', $parentId = null)
{
    $query = "mimeType='application/vnd.google-apps.folder' and name='" . driveSanitizeQueryValue($folderName) . "' and trashed=false";
    if ($parentId) {
        $query .= " and '" . driveSanitizeQueryValue($parentId) . "' in parents";
    }

    $response = $service->files->listFiles([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id,name)',
        'pageSize' => 1,
    ]);

    if (!empty($response->files) && count($response->files) > 0) {
        return $response->files[0]->id;
    }

    $folderMetadata = new Google\Service\Drive\DriveFile([
        'name' => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
    ]);
    if ($parentId) {
        $folderMetadata->setParents([$parentId]);
    }

    $folder = $service->files->create($folderMetadata, ['fields' => 'id']);
    return $folder->id;
}

function driveUploadNewFile(Google\Service\Drive $service, $folderId, $fileName, $content, $mimeType = 'application/pdf')
{
    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name' => $fileName,
        'parents' => [$folderId],
    ]);

    return $service->files->create($fileMetadata, [
        'data' => $content,
        'mimeType' => $mimeType,
        'uploadType' => 'multipart',
        'fields' => 'id,webViewLink,webContentLink',
    ]);
}

function driveUpdateExistingFile(Google\Service\Drive $service, $fileId, $fileName, $content, $mimeType = 'application/pdf')
{
    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name' => $fileName,
    ]);

    return $service->files->update($fileId, $fileMetadata, [
        'data' => $content,
        'mimeType' => $mimeType,
        'uploadType' => 'media',
        'fields' => 'id,webViewLink,webContentLink',
    ]);
}

function driveMoveFileToFolder(Google\Service\Drive $service, string $fileId, string $folderId): void
{
    $file = $service->files->get($fileId, ['fields' => 'parents']);
    $parents = $file->getParents();
    $previousParents = is_array($parents) ? implode(',', $parents) : '';

    if ($previousParents === $folderId) {
        return;
    }

    $options = [
        'fields' => 'id,parents',
        'addParents' => $folderId,
    ];
    if ($previousParents !== '') {
        $options['removeParents'] = $previousParents;
    }

    $service->files->update($fileId, new Google\Service\Drive\DriveFile(), $options);
}

function driveEnsureAnyoneLink(Google\Service\Drive $service, $fileId)
{
    try {
        $permission = new Google\Service\Drive\Permission([
            'type' => 'anyone',
            'role' => 'reader',
        ]);
        $service->permissions->create($fileId, $permission, ['fields' => 'id']);
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        if (strpos($message, 'duplicate') !== false || strpos($message, 'already exists') !== false) {
            return;
        }
        throw $e;
    }
}

function driveFindFileInFolder(Google\Service\Drive $service, string $folderId, string $fileName, string $mimeType = 'application/pdf'): ?string
{
    $mimeQuery = $mimeType === 'text/html' ? "mimeType='text/html'" : "mimeType='application/pdf'";
    $query = sprintf("%s and name='%s' and '%s' in parents and trashed=false", $mimeQuery, driveSanitizeQueryValue($fileName), driveSanitizeQueryValue($folderId));
    $response = $service->files->listFiles([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id,name)',
        'pageSize' => 1,
    ]);

    if (!empty($response->files) && count($response->files) > 0) {
        return $response->files[0]->id;
    }

    return null;
}

function driveGetFileLink(Google\Service\Drive $service, $fileId)
{
    $file = $service->files->get($fileId, ['fields' => 'webViewLink,webContentLink']);

    if (!empty($file->getWebContentLink())) {
        return $file->getWebContentLink();
    }

    if (!empty($file->getWebViewLink())) {
        return $file->getWebViewLink();
    }

    return 'https://drive.google.com/uc?export=download&id=' . urlencode($fileId);
}
