<?php
/** Configurações por ambiente; este arquivo não deve conter segredos. */
function appConfig($section, $key = null)
{
    static $config = null;
    if ($config === null) {
        $config = [
            'db' => ['host' => getenv('APP_DB_HOST') ?: 'localhost', 'name' => getenv('APP_DB_NAME') ?: 'implantacao', 'user' => getenv('APP_DB_USER') ?: 'root', 'pass' => getenv('APP_DB_PASS') ?: ''],
            'gestaopro' => ['base_url' => getenv('APP_GP_BASE_URL') ?: 'https://interno.gestaopro.srv.br', 'action_id' => getenv('APP_GP_ACTION_ID') ?: '', 'login' => getenv('APP_GP_LOGIN') ?: '', 'password' => getenv('APP_GP_PASSWORD') ?: ''],
            'monitor' => ['user' => getenv('APP_MONITOR_USER') ?: '', 'password' => getenv('APP_MONITOR_PASSWORD') ?: '', 'responsavel' => getenv('APP_MONITOR_RESPONSAVEL') ?: 'Vinicius Ferreira'],
            'google' => ['credentials_path' => getenv('APP_GOOGLE_CREDENTIALS_PATH') ?: __DIR__ . '/credentials.json', 'token_path' => getenv('APP_GOOGLE_TOKEN_PATH') ?: __DIR__ . '/token.json'],
        ];
        $localPath = __DIR__ . '/config.local.php';
        if (file_exists($localPath)) {
            $local = require $localPath;
            if (is_array($local)) foreach ($local as $group => $values) if (isset($config[$group]) && is_array($values)) $config[$group] = array_merge($config[$group], $values);
        }
    }
    if (!isset($config[$section])) throw new RuntimeException('Grupo de configuração inválido.');
    return $key === null ? $config[$section] : ($config[$section][$key] ?? null);
}
function appRequiredConfig($section, $key)
{
    $value = appConfig($section, $key);
    if ($value === null || $value === '') throw new RuntimeException('Configuração obrigatória ausente: ' . $section . '.' . $key);
    return $value;
}
function appGoogleCredentialsPath() { return appRequiredConfig('google', 'credentials_path'); }
function appGoogleTokenPath() { return appRequiredConfig('google', 'token_path'); }
