<?php
require_once __DIR__ . '/auth.php';
// Este arquivo redireciona para o fluxo único de autorização do Google Agenda/Drive.
header('Location: google_auth_reset.php');
exit;
