<?php
require_once __DIR__ . '/app_config.php';
/**
 * Configuração Isolada do Monitor de Chamados
 * Preencha as credenciais abaixo para habilitar o monitoramento.
 */

define('MONITOR_USER', appRequiredConfig('monitor', 'user'));
define('MONITOR_PASS', appRequiredConfig('monitor', 'password'));
define('MONITOR_URL_LOGIN', 'https://interno.gestaopro.srv.br/login');
define('MONITOR_URL_CHAMADOS', 'https://interno.gestaopro.srv.br/chamados');

// Caminhos para arquivos de sessão e log
define('MONITOR_COOKIE_FILE', __DIR__ . '/logs/monitor_cookie.txt');
define('MONITOR_LOG_FILE', __DIR__ . '/logs/monitor_chamados.log');

// Configurações de Filtro
define('MONITOR_RESPONSAVEL', appRequiredConfig('monitor', 'responsavel'));
define('MONITOR_STATUS_VALIDOS', [
    'Aguardando Suporte',
    'Aguardando Testes',
    'Aguardando Desenvolvimento',
    'Aguardando Desenv.' // Incluindo variação comum
]);
?>
