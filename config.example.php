<?php
// Copie para config.local.php e preencha apenas no ambiente local/servidor.
return [
    'db' => ['host' => 'localhost', 'name' => 'implantacao', 'user' => 'root', 'pass' => ''],
    'gestaopro' => ['base_url' => 'https://interno.gestaopro.srv.br', 'action_id' => '', 'login' => '', 'password' => ''],
    'monitor' => ['user' => '', 'password' => '', 'responsavel' => ''],
    'google' => ['credentials_path' => __DIR__ . '/credentials.json', 'token_path' => __DIR__ . '/token.json'],
];
