<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

// 1. DEFINIR VARIÁVEIS COM VALORES PADRÃO
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'cards';
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$estagio = isset($_GET['estagio']) ? $_GET['estagio'] : 'integracao';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$mostrar_encerrados = isset($_GET['mostrar_encerrados']) ? $_GET['mostrar_encerrados'] : '0';

// Ao realizar uma busca, queremos procurar em TODOS os estágios simultaneamente
if (!empty($busca)) {
    $estagio = '';
}

function encerrarImplantacaoCliente(PDO $pdo, $idCliente, $cancelada = false)
{
    $idCliente = (int)$idCliente;
    if ($idCliente <= 0) {
        return;
    }

    $dataAtual = date('Y-m-d');
    $dataHoraAtual = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if ($cancelada) {
            $stmtCliente = $pdo->prepare(
                "UPDATE clientes
                 SET status = 'CANCELADA',
                     data_fim = ?,
                     observacao = CONCAT(IFNULL(observacao, ''), ' [IMPLANTACAO CANCELADA EM ', CURDATE(), ']'),
                     status_tratativa = 'pendente',
                     data_inicio_tratativa = NULL
                 WHERE id_cliente = ?"
            );
            $stmtCliente->execute([$dataAtual, $idCliente]);
        } else {
            $stmtCliente = $pdo->prepare(
                "UPDATE clientes
                 SET status = 'CONCLUIDA',
                     data_fim = ?,
                     status_tratativa = 'pendente',
                     data_inicio_tratativa = NULL
                 WHERE id_cliente = ?"
            );
            $stmtCliente->execute([$dataAtual, $idCliente]);
        }

        // Fecha APENAS treinamentos pendentes já passados (sem data futura).
        // Treinamentos agendados para hoje ou para o futuro permanecem PENDENTE,
        // pois ainda precisam ser realizados mesmo com a implantação encerrada.
        $observacaoFechamento = $cancelada
            ? '[Encerrado automaticamente por cancelamento da implantacao em ' . $dataAtual . ']'
            : '[Encerrado automaticamente por conclusao da implantacao em ' . $dataAtual . ']';

        $stmtTreinamentos = $pdo->prepare(
            "UPDATE treinamentos
             SET status = 'Resolvido',
                 data_treinamento_encerrado = ?,
                 observacoes = CONCAT(IFNULL(observacoes, ''), CASE WHEN IFNULL(observacoes, '') = '' THEN '' ELSE ' ' END, ?)
             WHERE id_cliente = ?
               AND UPPER(status) = 'PENDENTE'
               AND (data_treinamento IS NULL OR data_treinamento < NOW())"
        );
        $stmtTreinamentos->execute([$dataHoraAtual, $observacaoFechamento, $idCliente]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// 2. Logica para Acoes Rapidas: Concluir e Cancelar
if (isset($_GET['concluir'])) {
    $id = $_GET['concluir'];
    encerrarImplantacaoCliente($pdo, $id, false);
    header("Location: clientes.php?msg=Implantacao+concluida+com+sucesso&view=" . $view_mode);
    exit;
}

if (isset($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    encerrarImplantacaoCliente($pdo, $id, true);
    header("Location: clientes.php?msg=Implantacao+cancelada+com+sucesso&view=" . $view_mode);
    exit;
}
// 3. Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
    $stmt->execute([$id]);
    header("Location: clientes.php?msg=Cliente+removido+com+sucesso&view=" . $view_mode);
    exit;
}

// 4. Lógica para Adicionar/Editar - ATUALIZADO COM NOVOS CAMPOS
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $abrirFichaContato = ($_POST['abrir_ficha_contato'] ?? '') === '1';
    $clienteNovoId = null;
    $fantasia = $_POST['fantasia'];
    $servidor = $_POST['servidor'];
    $vendedor = $_POST['vendedor'];
    $telefone = $_POST['telefone'] ?? '';
    $data_inicio = $_POST['data_inicio'];
    $data_fim = (!empty($_POST['data_fim']) && $_POST['data_fim'] !== '0000-00-00') ? $_POST['data_fim'] : null;
    $data_previsao_encerramento = !empty($_POST['data_previsao_encerramento']) ? $_POST['data_previsao_encerramento'] : null;
    $id_cliente_api = !empty($_POST['id_cliente_api']) ? trim($_POST['id_cliente_api']) : null;
    $anexo = trim($_POST['anexo'] ?? '');
    if (!empty($id_cliente_api) && empty($anexo)) {
        $anexo = 'https://interno.gestaopro.srv.br/clientes/' . trim($id_cliente_api);
    } elseif (!empty($anexo)) {
        $anexo = preg_match('/^https?:\/\//i', $anexo) ? $anexo : 'https://' . ltrim($anexo, '/');
        $link_para_id = $anexo;
        $caminho_link = parse_url($link_para_id, PHP_URL_PATH) ?: '';
        if (preg_match('~/([0-9]+)/?$~', $caminho_link, $id_api_encontrado)) {
            $id_cliente_api = $id_api_encontrado[1];
        }
    }
    $emitir_nf = $_POST['emitir_nf'] ?? 'Não';
    $configurado = $_POST['configurado'] ?? 'Não';

    // NOVOS CAMPOS
    $num_licencas = $_POST['num_licencas'] ?? 0;
    $chamados = $_POST['chamados'] ?? '';
    $link_videos = trim($_POST['link_videos'] ?? '');
    $serial = trim($_POST['serial'] ?? '');
    $cnpj = trim($_POST['cnpj'] ?? '');
    $link_drive = trim($_POST['link_drive'] ?? '');
    
    // CAMPO DE RECURSOS
    $recursos_arr = $_POST['recursos'] ?? [];
    $recursos = is_array($recursos_arr) ? implode(', ', $recursos_arr) : (string)$recursos_arr;

    try {
        if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
            // UPDATE com novos campos
            $stmt = $pdo->prepare("UPDATE `clientes` SET 
                `fantasia`=?, `servidor`=?, `vendedor`=?, `telefone_ddd`=?, 
                `data_inicio`=?, `data_fim`=?, `data_previsao_encerramento`=?, `id_cliente_api`=?, 
                `emitir_nf`=?, `configurado`=?, `num_licencas`=?, `anexo`=?, `chamados`=?, `recursos`=?, `link_videos`=?, `serial`=?, `cnpj`=?
                WHERE `id_cliente`=?");
            $stmt->execute([
                $fantasia,
                $servidor,
                $vendedor,
                $telefone,
                $data_inicio,
                $data_fim,
                $data_previsao_encerramento,
                $id_cliente_api,
                $emitir_nf,
                $configurado,
                $num_licencas,
                $anexo,
                $chamados,
                $recursos,
                $link_videos,
                $serial ?: null,
                $cnpj ?: null,
                $_POST['id_cliente']
            ]);
            // Atualiza link_drive apenas se veio preenchido no POST (não sobrescreve se vazio)
            if ($link_drive !== '') {
                $stmtDrive = $pdo->prepare("UPDATE `clientes` SET `link_drive`=? WHERE `id_cliente`=?");
                $stmtDrive->execute([$link_drive, $_POST['id_cliente']]);
            }
        } else {
            // INSERT com novos campos
            $stmt = $pdo->prepare("INSERT INTO `clientes` (
                `fantasia`, `servidor`, `vendedor`, `telefone_ddd`, 
                `data_inicio`, `data_fim`, `data_previsao_encerramento`, `id_cliente_api`,
                `emitir_nf`, `configurado`, `num_licencas`, `anexo`, `chamados`, `recursos`, `link_videos`, `serial`, `cnpj`, `link_drive`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $fantasia,
                $servidor,
                $vendedor,
                $telefone,
                $data_inicio,
                $data_fim,
                $data_previsao_encerramento,
                $id_cliente_api,
                $emitir_nf,
                $configurado,
                $num_licencas,
                $anexo,
                $chamados,
                $recursos,
                $link_videos,
                $serial ?: null,
                $cnpj ?: null,
                $link_drive ?: null,
            ]);
            $clienteNovoId = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $id_cliente_api !== null) {
            $redirect_to = 'clientes.php';
            if (!empty($_POST['redirect_to'])) {
                $candidate = basename($_POST['redirect_to']);
                $allowed = ['clientes.php', 'monitoramento_gestaopro.php', 'chamados_gestaopro.php'];
                if (in_array($candidate, $allowed, true)) {
                    $redirect_to = $candidate;
                }
            }
            header("Location: {$redirect_to}?msg=Este+cliente+da+GestaoPRO+ja+esta+cadastrado&view=" . $view_mode);
            exit;
        }
        throw $e;
    }

    $destinoContatoSolicitado = $abrirFichaContato
        || (($_POST['redirect_to'] ?? '') === 'contatos.php');
    if ($destinoContatoSolicitado && $clienteNovoId) {
        header('Location: contatos.php?novo=1&id_cliente=' . $clienteNovoId);
        exit;
    }

    $redirect_to = 'clientes.php';
    if (!empty($_POST['redirect_to'])) {
        $candidate = basename($_POST['redirect_to']);
        $allowed = ['clientes.php', 'monitoramento_gestaopro.php', 'chamados_gestaopro.php'];
        if (in_array($candidate, $allowed, true)) {
            $redirect_to = $candidate;
        }
    }

    header("Location: {$redirect_to}?msg=Dados+atualizados&view=" . $view_mode);
    exit;
}

// 5. Consulta de Dados e Filtros - FILTRAR CLIENTES NÃO ENCERRADOS
$sql = "SELECT c.*, 
               COALESCE(c.ID_CLIENTE_API, c.id_cliente_api) as id_cliente_api,
               COUNT(t.id_treinamento) as total_treinamentos,
               MAX(t.data_treinamento) as ultimo_treinamento,
               SUM(CASE WHEN t.status = 'PENDENTE' THEN 1 ELSE 0 END) as treinamentos_pendentes
        FROM clientes c
        LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
        WHERE 1=1";
$params = [];

// Por padrão, não mostrar clientes com implantação encerrada
// Exceto quando há uma busca ativa (nesse caso, mostrar todos, inclusive encerrados)
if ($mostrar_encerrados != '1' && empty($busca)) {
    $sql .= " AND (c.data_fim IS NULL OR c.data_fim = '0000-00-00')";
}

// Busca por nome do cliente
if (!empty($busca)) {
    $sql .= " AND c.fantasia LIKE ?";
    $params[] = "%$busca%";
}

$sql .= " GROUP BY c.id_cliente ORDER BY c.data_inicio DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todos_clientes = $stmt->fetchAll();

// Buscar contatos de todos os clientes listados (uma query só, sem AJAX)
$ids_clientes = array_column($todos_clientes, 'id_cliente');
$contatos_por_cliente = [];
if (!empty($ids_clientes)) {
    $placeholders_c = implode(',', array_fill(0, count($ids_clientes), '?'));
    $stmtCt = $pdo->prepare("SELECT id_contato, id_cliente, nome FROM contatos WHERE id_cliente IN ($placeholders_c) ORDER BY nome ASC");
    $stmtCt->execute($ids_clientes);
    foreach ($stmtCt->fetchAll() as $ct) {
        $contatos_por_cliente[$ct['id_cliente']][] = ['id' => $ct['id_contato'], 'nome' => $ct['nome']];
    }
}

// Função para extrair ID API com fallback para link do anexo
function extrairIdClienteApi(array $cliente): string {
    $id = trim((string)($cliente['ID_CLIENTE_API'] ?? $cliente['id_cliente_api'] ?? ''));
    if ($id !== '' && ctype_digit($id)) {
        return $id;
    }
    $anexo = trim((string)($cliente['anexo'] ?? ''));
    if ($anexo !== '') {
        $caminho = parse_url($anexo, PHP_URL_PATH) ?: '';
        if (preg_match('~/([0-9]+)/?$~', $caminho, $m)) {
            return $m[1];
        }
    }
    return '';
}

// Buscar dados de uso do sistema em cache (da API GestãoPro)
$uso_clientes_map = [];
$cache_uso_file = __DIR__ . '/logs/gp_cache_uso_30.json';
if (file_exists($cache_uso_file)) {
    $conteudo_cache_uso = @json_decode(file_get_contents($cache_uso_file), true);
    if (!empty($conteudo_cache_uso['clientes']) && is_array($conteudo_cache_uso['clientes'])) {
        foreach ($conteudo_cache_uso['clientes'] as $itemUso) {
            $idApi = trim((string)($itemUso['id_cliente_api'] ?? ''));
            if ($idApi !== '') {
                $uso_clientes_map[$idApi] = $itemUso['dados'] ?? null;
            }
            $idCli = (int)($itemUso['id_cliente'] ?? 0);
            if ($idCli > 0) {
                $uso_clientes_map['id_' . $idCli] = $itemUso['dados'] ?? null;
            }
            $serialCad = strtoupper(trim((string)($itemUso['serial_cadastro'] ?? '')));
            if ($serialCad !== '') {
                $uso_clientes_map['serial_' . $serialCad] = $itemUso['dados'] ?? null;
            }
            if (!empty($itemUso['dados']['seriais']) && is_array($itemUso['dados']['seriais'])) {
                foreach ($itemUso['dados']['seriais'] as $s) {
                    $sUpper = strtoupper(trim((string)$s));
                    if ($sUpper !== '') {
                        $uso_clientes_map['serial_' . $sUpper] = $itemUso['dados'] ?? null;
                    }
                }
            }
        }
    }
}

function obterInfoUsoCliente(array $cliente, array $usoMap): array {
    $idApi = extrairIdClienteApi($cliente);
    $idCli = (int)($cliente['id_cliente'] ?? 0);
    $serialCli = strtoupper(trim((string)($cliente['serial'] ?? '')));
    $dadosUso = null;

    if ($idApi !== '' && isset($usoMap[$idApi])) {
        $dadosUso = $usoMap[$idApi];
    } elseif ($idCli > 0 && isset($usoMap['id_' . $idCli])) {
        $dadosUso = $usoMap['id_' . $idCli];
    } elseif ($serialCli !== '' && isset($usoMap['serial_' . $serialCli])) {
        $dadosUso = $usoMap['serial_' . $serialCli];
    }

    if (empty($idApi) && !$dadosUso) {
        return [
            'tem_id_api' => false,
            'id_api_usado' => '',
            'texto' => 'Sem ID API',
            'badge' => 'bg-secondary bg-opacity-10 text-secondary',
            'badge_label' => 'Sem ID API',
            'ultimo_dt' => null,
            'pageviews' => 0,
            'logins' => 0,
            'atritos' => 0,
            'erros' => 0,
            'ativo_hoje' => false,
        ];
    }

    if (!$dadosUso || empty($dadosUso['resumo'])) {
        return [
            'tem_id_api' => !empty($idApi),
            'id_api_usado' => $idApi,
            'texto' => 'Aguardando sincronização',
            'badge' => 'bg-secondary bg-opacity-10 text-secondary',
            'badge_label' => 'Sem leitura',
            'ultimo_dt' => null,
            'pageviews' => 0,
            'logins' => 0,
            'atritos' => 0,
            'erros' => 0,
            'ativo_hoje' => false,
        ];
    }

    $resumo = $dadosUso['resumo'];
    $ultimoIso = $resumo['ULTIMO'] ?? null;
    $pageviews = (int)($resumo['PAGEVIEWS'] ?? 0);
    $logins = (int)($resumo['LOGINS'] ?? 0);
    $atritos = (int)($resumo['ATRITOS'] ?? 0);
    $erros = (int)($resumo['ERROS'] ?? 0);

    if (empty($ultimoIso)) {
        return [
            'tem_id_api' => true,
            'id_api_usado' => $idApi,
            'texto' => 'Sem acessos nos 30d',
            'badge' => 'bg-secondary bg-opacity-10 text-secondary',
            'badge_label' => 'Sem acessos',
            'ultimo_dt' => null,
            'pageviews' => $pageviews,
            'logins' => $logins,
            'atritos' => $atritos,
            'erros' => $erros,
            'ativo_hoje' => false,
        ];
    }

    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $dt = new DateTime($ultimoIso);
        $dt->setTimezone($tz);
        $agora = new DateTime('now', $tz);

        $diffDias = (int)$agora->diff($dt)->days;
        $mesmoDia = ($dt->format('Y-m-d') === $agora->format('Y-m-d'));
        $ontem = (new DateTime('yesterday', $tz))->format('Y-m-d');
        $ehOntem = ($dt->format('Y-m-d') === $ontem);

        if ($mesmoDia) {
            $tempo = 'Hoje às ' . $dt->format('H:i');
            $badge = 'bg-success bg-opacity-15 text-success border border-success border-opacity-25';
            $badge_label = 'Ativo Hoje';
            $ativo_hoje = true;
        } elseif ($ehOntem) {
            $tempo = 'Ontem às ' . $dt->format('H:i');
            $badge = 'bg-primary bg-opacity-10 text-primary';
            $badge_label = 'Ativo Ontem';
            $ativo_hoje = false;
        } elseif ($diffDias <= 3) {
            $tempo = "Há {$diffDias} dias ({$dt->format('d/m H:i')})";
            $badge = 'bg-info bg-opacity-10 text-info';
            $badge_label = "Há {$diffDias}d";
            $ativo_hoje = false;
        } elseif ($diffDias <= 7) {
            $tempo = "Há {$diffDias} dias ({$dt->format('d/m H:i')})";
            $badge = 'bg-warning bg-opacity-10 text-warning';
            $badge_label = "Há {$diffDias}d";
            $ativo_hoje = false;
        } else {
            $tempo = "Há {$diffDias} dias ({$dt->format('d/m/Y')})";
            $badge = 'bg-danger bg-opacity-10 text-danger';
            $badge_label = "Inativo há {$diffDias}d";
            $ativo_hoje = false;
        }

        return [
            'tem_id_api' => true,
            'id_api_usado' => $idApi,
            'texto' => $tempo,
            'data_fmt' => $dt->format('d/m/Y H:i'),
            'badge' => $badge,
            'badge_label' => $badge_label,
            'ultimo_dt' => $ultimoIso,
            'pageviews' => $pageviews,
            'logins' => $logins,
            'atritos' => $atritos,
            'erros' => $erros,
            'ativo_hoje' => $ativo_hoje,
        ];
    } catch (Throwable $e) {
        return [
            'tem_id_api' => true,
            'id_api_usado' => $idApi,
            'texto' => 'Registro presente',
            'badge' => 'bg-secondary bg-opacity-10 text-secondary',
            'badge_label' => 'Atividade',
            'ultimo_dt' => $ultimoIso,
            'pageviews' => $pageviews,
            'logins' => $logins,
            'atritos' => $atritos,
            'erros' => $erros,
            'ativo_hoje' => false,
        ];
    }
}

// 6. Lógica de Contagem para os CARDS - SOMENTE CLIENTES ATIVOS
$integracao = 0;
$operacional = 0;
$atencao = 0;
$finalizacao = 0;
$encerrar = 0;
$encerrados = 0;
$clientes_filtrados = [];

foreach ($todos_clientes as $cl) {
    // Verificar se o cliente está encerrado
    $cliente_encerrado = false;
    if (!empty($cl['data_fim']) && $cl['data_fim'] !== '0000-00-00') {
        $data_fim = trim($cl['data_fim']);
        if ($data_fim !== '' && $data_fim !== '0000-00-00') {
            $cliente_encerrado = true;
        }
    }

    // Contar clientes encerrados
    if ($cliente_encerrado) {
        $encerrados++;
    }

    $status_cl = "concluido";
    if (!$cliente_encerrado) {
        $d = (new DateTime($cl['data_inicio']))->diff(new DateTime())->days;
        if ($d <= 20) {
            $integracao++;
            $status_cl = "integracao";
        } elseif ($d <= 60) {
            $operacional++;
            $status_cl = "operacional";
        } elseif ($d <= 70) {
            $atencao++;
            $status_cl = "atencao";
        } elseif ($d <= 90) {
            $finalizacao++;
            $status_cl = "finalizacao";
        } else {
            $encerrar++;
            $status_cl = "encerrar";
        }
    } else {
        $status_cl = "encerrado";
    }

    // Adiciona à lista filtrada se passar pelo filtro de estágio
    // Quando há busca ativa, exibe todos os resultados (inclusive encerrados)
    if (!empty($busca)) {
        $clientes_filtrados[] = $cl;
    } elseif ($estagio == '') {
        if ($status_cl != 'encerrado') {
            $clientes_filtrados[] = $cl;
        }
    } elseif ($estagio == $status_cl) {
        $clientes_filtrados[] = $cl;
    }
}

include 'header.php';
?>

<style>
/* Design System Clean & Modern (Perplexity Style) */
:root {
    --glass-bg: rgba(255, 255, 255, 0.03);
    --glass-border: rgba(255, 255, 255, 0.08);
}

[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #16171d;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #cbd5e1; /* Cor mais clara para melhor legibilidade */
    --primary-light: rgba(67, 97, 238, 0.2);
    --success-light: rgba(16, 185, 129, 0.2);
    --warning-light: rgba(245, 158, 11, 0.2);
    --danger-light: rgba(239, 68, 68, 0.2);
    --info-light: rgba(6, 182, 212, 0.2);
}

body, html {
    background-color: var(--bg-body);
    color: var(--text-main);
}

/* Modern Page Header */
.modern-header {
    padding: 1rem 0 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2.5rem;
}

.title-accent {
    color: var(--primary);
    background: linear-gradient(120deg, var(--primary), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Status Filter Cards */
.status-pill-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1rem;
    margin-bottom: 2.5rem;
}

.status-pill-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    padding: 1.25rem;
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.status-pill-card:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
}

.status-pill-card.active {
    background: var(--primary-light);
    border-color: var(--primary);
}

.status-pill-card h2 {
    font-size: 1.75rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
}

.status-pill-card span {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

/* Client Card Refined */
.client-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    transition: all 0.3s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.client-card-premium:hover {
    transform: translateY(-5px);
    border-color: var(--primary-light);
    box-shadow: var(--shadow-lg);
}

.client-name {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.client-meta {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
    font-weight: 500;
}

.support-ticket-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
}

.support-ticket-description {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.55;
}

.support-ticket-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
    gap: 0.75rem;
}

.support-ticket-detail {
    min-width: 0;
    padding: 0.65rem 0.75rem;
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    overflow-wrap: anywhere;
}

.client-badge-soft {
    font-size: 0.65rem;
    font-weight: 800;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    text-transform: uppercase;
}

/* Controls */
.control-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1rem;
    margin-bottom: 2rem;
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-modern {
    background: var(--bg-body) !important;
    border: 1px solid var(--border-color) !important;
    border-radius: 10px !important;
    color: var(--text-main) !important;
    padding-left: 2.5rem !important;
}

.btn-modern {
    height: 42px;
    border-radius: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

/* Action Buttons stylized */
.btn-action {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: rgba(255,255,255,0.05);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
    text-decoration: none;
}

.btn-action:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.btn-action.edit { color: #4361ee; background: rgba(67, 97, 238, 0.15); border-color: rgba(67, 97, 238, 0.3); }
.btn-action.edit:hover { background: #4361ee; color: white; }

.btn-action.treinamentos { color: #4cc9f0; background: rgba(76, 201, 240, 0.15); border-color: rgba(76, 201, 240, 0.3); }
.btn-action.treinamentos:hover { background: #4cc9f0; color: white; }

.btn-action.efetivo { color: #0ea5e9; background: rgba(14, 165, 233, 0.15); border-color: rgba(14, 165, 233, 0.3); }
.btn-action.efetivo:hover { background: #0ea5e9; color: white; }

.btn-action.concluir { color: #10b981; background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.3); }
.btn-action.concluir:hover { background: #10b981; color: white; }

.btn-action.cancelar { color: #f59e0b; background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
.btn-action.cancelar:hover { background: #f59e0b; color: white; }

.btn-action.delete { color: #ef4444; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); }
.btn-action.delete:hover { background: #ef4444; color: white; }

.btn-action.agendar { color: #7c3aed; background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
.btn-action.agendar:hover { background: #7c3aed; color: white; }

.btn-action.gestaopro { color: #ff9800; background: rgba(255, 152, 0, 0.15); border-color: rgba(255, 152, 0, 0.3); }
.btn-action.gestaopro:hover { background: #ff9800; color: white; }

.btn-action.chamados { color: #10b981; background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.3); }
.btn-action.chamados:hover { background: #10b981; color: white; }

.btn-action.uso { color: #06b6d4; background: rgba(6, 182, 212, 0.15); border-color: rgba(6, 182, 212, 0.3); }
.btn-action.uso:hover { background: #06b6d4; color: white; }

/* Box de Resumo de Uso do Cliente */
.client-usage-pill {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 0.6rem 0.8rem;
    transition: all 0.25s ease;
    cursor: pointer;
}
.client-usage-pill:hover {
    background: rgba(6, 182, 212, 0.08);
    border-color: rgba(6, 182, 212, 0.4);
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(6, 182, 212, 0.12);
}

.pulse-dot-green {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulse-green 1.8s infinite;
}
@keyframes pulse-green {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* Modal Dashboard Premium */
.dash-kpi-card {
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 1.25rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
}
.dash-kpi-card:hover {
    transform: translateY(-3px);
    border-color: rgba(6, 182, 212, 0.4);
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}
.dash-nav-pill {
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-muted);
    padding: 0.5rem 1rem;
    transition: all 0.2s ease;
}
.dash-nav-pill.active {
    background: rgba(6, 182, 212, 0.15) !important;
    color: #06b6d4 !important;
}

/* Grid & Table Animations */
.gsap-reveal {
    opacity: 0;
    transform: translateY(20px);
}

.custom-scroll {
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
}
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

</style>

<div class="container-fluid px-lg-5 pt-4">

    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Clientes</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Gestão de <span class="title-accent">Clientes</span></h2>
            <p class="text-muted small mb-0">Controle total do fluxo de implantação.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="clientes.php?mostrar_encerrados=<?= $mostrar_encerrados == '1' ? '0' : '1' ?>&view=<?= urlencode($view_mode) ?>&busca=<?= urlencode($busca) ?>&estagio="
               class="btn btn-outline-primary btn-modern px-3">
                <i class="bi <?= $mostrar_encerrados == '1' ? 'bi-eye-slash text-danger' : 'bi-eye text-success' ?>"></i>
                Ver Ativos/Encerrados
            </a>
            <button class="btn btn-primary btn-modern px-4" data-bs-toggle="modal" data-bs-target="#modalCliente">
                <i class="bi bi-plus-lg"></i> Novo Cliente
            </button>
        </div>
    </div>

    <!-- Status Filter Grid -->
    <div class="status-pill-grid gsap-reveal">
        <?php if ($mostrar_encerrados == '1'): ?>
        <div class="status-pill-card <?= $estagio == '' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Ativos</span>
            <h2 class="text-main"><?= $integracao + $operacional + $atencao + $finalizacao + $encerrar ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(255, 255, 255, 0.05);">
                <div class="progress-bar bg-primary" style="width: 100%"></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="status-pill-card <?= $estagio == 'integracao' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=integracao&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Integração | 0 a 20 dias</span>
            <h2 class="text-info"><?= $integracao ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(6, 182, 212, 0.1);">
                <div class="progress-bar bg-info" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'operacional' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=operacional&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Operacional | 21 a 60 dias</span>
            <h2 class="text-primary"><?= $operacional ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(67, 97, 238, 0.1);">
                <div class="progress-bar bg-primary" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'atencao' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=atencao&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Atenção | 61 a 70 dias</span>
            <h2 class="text-warning"><?= $atencao ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(245, 158, 11, 0.1);">
                <div class="progress-bar bg-warning" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'finalizacao' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=finalizacao&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Finalização | 71 a 90 dias</span>
            <h2 class="text-success"><?= $finalizacao ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(16, 185, 129, 0.1);">
                <div class="progress-bar bg-success" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'encerrar' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=encerrar&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Encerrar | 91+ dias</span>
            <h2 class="text-danger"><?= $encerrar ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(239, 68, 68, 0.1);">
                <div class="progress-bar bg-danger" style="width: 100%"></div>
            </div>
        </div>
        <?php if ($mostrar_encerrados == '1'): ?>
        <div class="status-pill-card <?= $estagio == 'encerrado' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=encerrado&view=<?= $view_mode ?>&mostrar_encerrados=1'">
            <span>Encerrados</span>
            <h2 class="text-muted"><?= $encerrados ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(148, 163, 184, 0.1);">
                <div class="progress-bar bg-secondary" style="width: 100%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Controls Bar -->
    <div class="control-bar gsap-reveal">
        <div class="search-container position-relative flex-grow-1" style="max-width: 500px;">
            <i class="bi bi-search position-absolute text-muted" style="left: 1rem; top: 50%; transform: translateY(-50%);"></i>
            <form method="GET" action="clientes.php" id="searchForm">
                <input type="text" name="busca" id="searchInput" class="form-control search-input-modern w-100" 
                       placeholder="Buscar cliente por nome ou fantasia..." value="<?= htmlspecialchars($busca) ?>">
                <input type="hidden" name="view" value="<?= $view_mode ?>">
                <input type="hidden" name="estagio" value="<?= $estagio ?>">
                <input type="hidden" name="mostrar_encerrados" value="<?= $mostrar_encerrados ?>">
            </form>
        </div>
        <div class="d-flex gap-2 ms-auto">
            <div class="bg-body p-1 rounded-3 d-flex border">
                <button onclick="changeViewMode('cards')" class="btn btn-sm <?= $view_mode == 'cards' ? 'btn-primary shadow-sm' : 'btn-link text-muted' ?> bx-button px-3">
                    <i class="bi bi-grid-3x3-gap"></i>
                </button>
                <button onclick="changeViewMode('list')" class="btn btn-sm <?= $view_mode == 'list' ? 'btn-primary shadow-sm' : 'btn-link text-muted' ?> bx-button px-3">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Main View Content -->
    <div class="row g-4 mb-5">
        <?php if ($view_mode == 'cards'): ?>
            <?php if (empty($clientes_filtrados)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-search display-1 text-muted opacity-25 mb-4 d-block"></i>
                    <h4 class="text-muted">Nenhum cliente encontrado nesta categoria.</h4>
                </div>
            <?php endif; ?>

            <?php foreach ($clientes_filtrados as $index => $c): 
                $data_inicio = new DateTime($c['data_inicio']);
                $hoje = new DateTime();
                $d = $data_inicio->diff($hoje)->days;
                $tem_previsao = !empty($c['data_previsao_encerramento']) && $c['data_previsao_encerramento'] !== '0000-00-00';
                $dias_previsao = $tem_previsao
                    ? $data_inicio->diff(new DateTime($c['data_previsao_encerramento']))->days
                    : null;
                $dias_restantes = $tem_previsao ? ($dias_previsao - $d) : null;
                $cliente_encerrado = (!empty($c['data_fim']) && $c['data_fim'] !== '0000-00-00');
                
                $status_config = [
                    'integracao' => ['label' => 'Integração', 'class' => 'bg-info bg-opacity-10 text-info', 'icon' => 'bi-rocket-takeoff'],
                    'operacional' => ['label' => 'Operacional', 'class' => 'bg-primary bg-opacity-10 text-primary', 'icon' => 'bi-gear'],
                    'atencao' => ['label' => 'Atenção', 'class' => 'bg-warning bg-opacity-10 text-warning', 'icon' => 'bi-exclamation-triangle'],
                    'finalizacao' => ['label' => 'Finalização', 'class' => 'bg-success bg-opacity-10 text-success', 'icon' => 'bi-flag'],
                    'encerrar' => ['label' => 'Encerrar', 'class' => 'bg-danger bg-opacity-10 text-danger', 'icon' => 'bi-x-circle'],
                    'encerrado' => ['label' => 'Encerrado', 'class' => 'bg-secondary bg-opacity-10 text-secondary', 'icon' => 'bi-archive']
                ];

                $current_status = 'integracao';
                if ($cliente_encerrado) $current_status = 'encerrado';
                elseif ($d > 90) $current_status = 'encerrar';
                elseif ($d > 70) $current_status = 'finalizacao';
                elseif ($d > 60) $current_status = 'atencao';
                elseif ($d > 20) $current_status = 'operacional';

                $cfg = $status_config[$current_status];
            ?>
            <div class="col-md-6 col-xl-4 gsap-reveal">
                <div class="client-card-premium">
                    <div class="client-name">
                        <span class="text-truncate" title="<?= htmlspecialchars($c['fantasia']) ?>"><?= htmlspecialchars($c['fantasia']) ?></span>
                        <span class="client-badge-soft <?= $cfg['class'] ?>">
                            <i class="bi <?= $cfg['icon'] ?> me-1"></i> <?= $cfg['label'] ?>
                        </span>
                    </div>
                    
                    <div class="client-meta">
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-person-badge me-2 text-muted"></i> <?= htmlspecialchars($c['vendedor']) ?>
                        </div>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-server me-2 text-muted"></i> <?= htmlspecialchars($c['servidor']) ?>
                        </div>
                        <?php if (!empty($c['serial'])): ?>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-upc-scan me-2 text-muted"></i> SERIAL: <?= htmlspecialchars($c['serial'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($c['id_cliente_api'])): ?>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-braces-asterisk me-2 text-muted"></i> ID API: <?= htmlspecialchars($c['id_cliente_api']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($contatos_por_cliente[$c['id_cliente']])): ?>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-person-lines-fill me-2 text-muted"></i> 
                            <span class="text-truncate"><?= htmlspecialchars(implode(', ', array_column($contatos_por_cliente[$c['id_cliente']], 'nome'))) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php 
                        $infoUso = obterInfoUsoCliente($c, $uso_clientes_map);
                        $idApiUsado = ($infoUso['id_api_usado'] ?? '') ?: extrairIdClienteApi($c);
                    ?>
                    <div class="client-usage-pill mt-3 mb-3 client-usage-box" 
                         onclick="abrirDashboardUso(this)" 
                         data-id-api="<?= htmlspecialchars($idApiUsado, ENT_QUOTES, 'UTF-8') ?>" 
                         data-id-cliente="<?= $c['id_cliente'] ?>" 
                         data-fantasia="<?= htmlspecialchars($c['fantasia'], ENT_QUOTES, 'UTF-8') ?>" 
                         data-serial="<?= htmlspecialchars($c['serial'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                         title="Clique para abrir o Dashboard de Uso do GestãoPro">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <div class="d-flex align-items-center text-truncate me-2">
                                <i class="bi bi-speedometer2 text-info me-2 fs-6"></i>
                                <span class="small fw-semibold text-truncate" style="font-size: 0.78rem;">Uso do Sistema</span>
                            </div>
                            <span class="badge rounded-pill px-2 py-1 usage-status-badge <?= $infoUso['badge'] ?>" style="font-size: 0.68rem;">
                                <?php if ($infoUso['ativo_hoje']): ?>
                                    <span class="pulse-dot-green me-1"></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($infoUso['badge_label']) ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between small text-muted" style="font-size: 0.72rem;">
                            <span class="text-truncate me-2"><i class="bi bi-clock-history me-1"></i>Último: <strong class="text-main"><?= htmlspecialchars($infoUso['texto']) ?></strong></span>
                            <?php if ($infoUso['pageviews'] > 0): ?>
                            <span class="fw-semibold text-info flex-shrink-0"><i class="bi bi-eye me-1"></i><?= number_format($infoUso['pageviews'], 0, ',', '.') ?> views</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-auto">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Ini­cio: <?= date('d/m/Y', strtotime($c['data_inicio'])) ?></span>
                            <?php if (!empty($c['data_previsao_encerramento']) && $c['data_previsao_encerramento'] !== '0000-00-00'): ?>
                            <span class="text-warning">Previsão: <?= date('d/m/Y', strtotime($c['data_previsao_encerramento'])) ?></span>
                            <?php endif; ?>
                            <span class="fw-bold text-main"><?= $tem_previsao ? 'Restam: ' . $dias_restantes . ' dias' : $d . ' dias' ?></span>
                        </div>
                        <div class="progress mb-3" style="height: 6px; border-radius: 10px; background: var(--bg-body);">
                            <?php 
                                $pct = min(100, ($d / 90) * 100);
                                $bar_class = 'bg-info';
                                if ($cliente_encerrado) { $pct = 100; $bar_class = 'bg-secondary'; }
                                elseif ($d > 90) $bar_class = 'bg-danger';
                                elseif ($d > 70) $bar_class = 'bg-success';
                                elseif ($d > 60) $bar_class = 'bg-warning';
                                elseif ($d > 20) $bar_class = 'bg-primary';
                            ?>
                            <div class="progress-bar <?= $bar_class ?>" style="width: <?= $pct ?>%"></div>
                        </div>

                        <?php if ($c['treinamentos_pendentes'] > 0): ?>
                            <div class="alert alert-danger py-2 px-3 mb-3 border-0 small d-flex align-items-center rounded-3">
                                <i class="bi bi-exclamation-circle-fill me-2"></i>
                                <span><b><?= $c['treinamentos_pendentes'] ?></b> treinamento(s) pendente(s)</span>
                            </div>
                        <?php endif; ?>

                        <div class="control-bar p-2 mb-0" style="background: var(--bg-body); border-radius: 12px; border: none; gap: 4px;">
                            <button class="btn btn-sm btn-action edit edit-trigger" 
                                    data-id="<?= $c['id_cliente'] ?>" 
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>" 
                                    data-servidor="<?= $c['servidor'] ?>" 
                                    data-vendedor="<?= $c['vendedor'] ?>" 
                                    data-telefone="<?= $c['telefone_ddd'] ?>" 
                                    data-inicio="<?= $c['data_inicio'] ?>" 
                                    data-fim="<?= $c['data_fim'] ?>" 
                                    data-previsao-encerramento="<?= $c['data_previsao_encerramento'] ?? '' ?>" 
                                    data-id-cliente-api="<?= htmlspecialchars($c['id_cliente_api'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                    data-nf="<?= $c['emitir_nf'] ?>" 
                                    data-cfg="<?= $c['configurado'] ?>" 
                                    data-licencas="<?= $c['num_licencas'] ?>" 
                                    data-anexo="<?= $c['anexo'] ?>" 
                                    data-chamados="<?= htmlspecialchars($c['chamados'] ?? '') ?>" 
                                    data-recursos="<?= htmlspecialchars($c['recursos'] ?? '') ?>" 
                                    data-link-videos="<?= htmlspecialchars($c['link_videos'] ?? '') ?>" 
                                    title="Editar" onclick="openEditModal(this)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php $idApiChamados = extrairIdClienteApi($c); ?>
                            <button type="button" class="btn btn-sm btn-action chamados btn-suporte-cliente"
                                    data-id-api="<?= htmlspecialchars($idApiChamados, ENT_QUOTES, 'UTF-8') ?>"
                                    data-fantasia="<?= htmlspecialchars($c['fantasia'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="Chamados de suporte de <?= htmlspecialchars($c['fantasia'], ENT_QUOTES, 'UTF-8') ?>"
                                    title="<?= $idApiChamados === '' ? 'Cadastre o ID Cliente API para consultar os chamados' : 'Chamados de suporte' ?>"
                                    onclick="abrirChamadosSuporte(this)" <?= $idApiChamados === '' ? 'disabled' : '' ?>>
                                <i class="bi bi-headset"></i>
                            </button>
                            <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-action treinamentos" title="Ver Treinamentos">
                                <i class="bi bi-calendar-check"></i>
                            </a>
                            <a href="treinamento_efetivo.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-action efetivo" title="Treinamento Efetivo">
                                <i class="bi bi-clipboard-data"></i>
                            </a>
                                <button class="btn btn-sm btn-action agendar novo-treino-trigger"
                                        data-id="<?= $c['id_cliente'] ?>"
                                        data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                        data-contatos="<?= htmlspecialchars(json_encode($contatos_por_cliente[$c['id_cliente']] ?? []), ENT_QUOTES) ?>"
                                        title="Agendar Novo Treinamento"
                                        onclick="openNovoTreinamentoModal(this)">
                                    <i class="bi bi-calendar-plus"></i>
                                </button>
                                <?php if (!empty($c['anexo'])): ?>
                                    <a href="<?= (strpos($c['anexo'], 'http') === 0) ? $c['anexo'] : 'https://' . $c['anexo'] ?>" 
                                       target="_blank" class="btn btn-sm btn-action gestaopro" title="Link GestãoPRO">
                                        <i class="bi bi-rocket-takeoff"></i>
                                    </a>
                                <?php endif; ?>
                                <?php 
                                    $link_chamados = '';
                                    if (!empty($c['chamados'])) {
                                        $link_chamados = $c['chamados'];
                                    } elseif (!empty($c['anexo'])) {
                                        $base = (strpos($c['anexo'], 'http') === 0) ? $c['anexo'] : 'https://' . $c['anexo'];
                                        $link_chamados = rtrim($base, '?') . '?tab=chamados-abertos';
                                    }
                                ?>
                                <?php if (!empty($link_chamados)): ?>
                                    <a href="<?= htmlspecialchars($link_chamados) ?>" 
                                       target="_blank" class="btn btn-sm btn-action chamados" title="Link Chamados">
                                        <i class="bi bi-headset"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($c['link_videos'])): ?>
                                    <a href="<?= htmlspecialchars($c['link_videos']) ?>" 
                                       target="_blank" class="btn btn-sm btn-action gestaopro" style="color: #ea4335; background: rgba(234, 67, 53, 0.15); border-color: rgba(234, 67, 53, 0.3);" title="Vídeos Passo a Passo">
                                        <i class="bi bi-youtube"></i>
                                    </a>
                                <?php endif; ?>
                            <?php if (!$cliente_encerrado): ?>
                                <a href="?concluir=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action concluir" title="Concluir" onclick="return confirm('Concluir implantação?')">
                                    <i class="bi bi-check-lg"></i>
                                </a>
                                <a href="?cancelar=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action cancelar" title="Cancelar" onclick="return confirm('Cancelar implantação?')">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                            <a href="?delete=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action delete ms-auto" title="Excluir" onclick="return confirm('Excluir cliente?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Table View Modernized -->
            <div class="col-12 gsap-reveal">
                <div class="dashboard-section p-0 overflow-hidden">
                    <div class="table-responsive custom-scroll" style="max-height: 700px;">
                        <table class="table-dashboard">
                            <thead>
                                <tr>
                                    <th class="ps-4">Cliente / Início</th>
                                    <th>Vendedor / Servidor</th>
                                    <th class="text-center">Duração</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end pe-4">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($clientes_filtrados)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5">
                                            <h5 class="text-muted">Nenhum cliente encontrado.</h5>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($clientes_filtrados as $c): 
                                    $d = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;
                                    $cliente_encerrado = (!empty($c['data_fim']) && $c['data_fim'] !== '0000-00-00');
                                    
                                    $status_config = [
                                        'integracao' => ['label' => 'Integração', 'class' => 'bg-info bg-opacity-10 text-info', 'icon' => 'bi-rocket-takeoff'],
                                        'operacional' => ['label' => 'Operacional', 'class' => 'bg-primary bg-opacity-10 text-primary', 'icon' => 'bi-gear'],
                                        'atencao' => ['label' => 'Atenção', 'class' => 'bg-warning bg-opacity-10 text-warning', 'icon' => 'bi-exclamation-triangle'],
                                        'finalizacao' => ['label' => 'Finalização', 'class' => 'bg-success bg-opacity-10 text-success', 'icon' => 'bi-flag'],
                                        'encerrar' => ['label' => 'Encerrar', 'class' => 'bg-danger bg-opacity-10 text-danger', 'icon' => 'bi-x-circle'],
                                        'encerrado' => ['label' => 'Encerrado', 'class' => 'bg-secondary bg-opacity-10 text-secondary', 'icon' => 'bi-archive']
                                    ];

                                    $current_status = 'integracao';
                                    if ($cliente_encerrado) $current_status = 'encerrado';
                                    elseif ($d > 90) $current_status = 'encerrar';
                                    elseif ($d > 70) $current_status = 'finalizacao';
                                    elseif ($d > 60) $current_status = 'atencao';
                                    elseif ($d > 20) $current_status = 'operacional';
                                    $cfg = $status_config[$current_status] ?? ['label' => 'Desconhecido', 'class' => 'bg-secondary', 'icon' => 'bi-question'];
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= htmlspecialchars($c['fantasia']) ?></div>
                                        <div class="text-muted small"><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-bold"><?= htmlspecialchars($c['vendedor']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($c['servidor']) ?></div>
                                        <?php if (!empty($c['id_cliente_api'])): ?>
                                        <div class="text-muted small"><i class="bi bi-braces-asterisk me-1"></i>ID API: <?= htmlspecialchars($c['id_cliente_api']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($contatos_por_cliente[$c['id_cliente']])): ?>
                                        <div class="text-muted small"><i class="bi bi-person-lines-fill me-1"></i><?= htmlspecialchars(implode(', ', array_column($contatos_por_cliente[$c['id_cliente']], 'nome'))) ?></div>
                                        <?php endif;                                         ?>
                                        <?php 
                                            $infoUsoT = obterInfoUsoCliente($c, $uso_clientes_map);
                                        ?>
                                        <div class="mt-1 d-flex align-items-center gap-1" style="font-size: 0.74rem;">
                                            <i class="bi bi-speedometer2 text-info"></i>
                                            <span class="text-muted">Último acesso:</span>
                                            <strong class="text-main"><?= htmlspecialchars($infoUsoT['texto']) ?></strong>
                                            <span class="badge rounded-pill px-2 <?= $infoUsoT['badge'] ?>" style="font-size: 0.65rem;">
                                                <?php if ($infoUsoT['ativo_hoje']): ?><span class="pulse-dot-green me-1"></span><?php endif; ?>
                                                <?= htmlspecialchars($infoUsoT['badge_label']) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark rounded-pill px-3 shadow-sm"><?= $d ?> dias</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="client-badge-soft <?= $cfg['class'] ?>"><?= $cfg['label'] ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button class="btn btn-sm btn-action edit edit-trigger" 
                                                    data-id="<?= $c['id_cliente'] ?>" 
                                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>" 
                                                    data-servidor="<?= $c['servidor'] ?>" 
                                                    data-vendedor="<?= $c['vendedor'] ?>" 
                                                    data-telefone="<?= $c['telefone_ddd'] ?>" 
                                                    data-inicio="<?= $c['data_inicio'] ?>" 
                                                    data-fim="<?= $c['data_fim'] ?>" 
                                                    data-previsao-encerramento="<?= $c['data_previsao_encerramento'] ?? '' ?>" 
                                                    data-id-cliente-api="<?= htmlspecialchars($c['id_cliente_api'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                    data-obs="<?= htmlspecialchars($c['observacao']) ?>" 
                                                    data-nf="<?= $c['emitir_nf'] ?>" 
                                                    data-cfg="<?= $c['configurado'] ?>" 
                                                    data-licencas="<?= $c['num_licencas'] ?>" 
                                                    data-anexo="<?= $c['anexo'] ?>" 
                                                    data-chamados="<?= htmlspecialchars($c['chamados'] ?? '') ?>" 
                                                    data-recursos="<?= htmlspecialchars($c['recursos'] ?? '') ?>" 
                                                    data-link-videos="<?= htmlspecialchars($c['link_videos'] ?? '') ?>" 
                                                    onclick="openEditModal(this)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php $idApiChamadosTabela = extrairIdClienteApi($c); ?>
                                            <button type="button" class="btn btn-sm btn-action chamados btn-suporte-cliente"
                                                    data-id-api="<?= htmlspecialchars($idApiChamadosTabela, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-fantasia="<?= htmlspecialchars($c['fantasia'], ENT_QUOTES, 'UTF-8') ?>"
                                                    aria-label="Chamados de suporte de <?= htmlspecialchars($c['fantasia'], ENT_QUOTES, 'UTF-8') ?>"
                                                    title="<?= $idApiChamadosTabela === '' ? 'Cadastre o ID Cliente API para consultar os chamados' : 'Chamados de suporte' ?>"
                                                    onclick="abrirChamadosSuporte(this)" <?= $idApiChamadosTabela === '' ? 'disabled' : '' ?>>
                                                <i class="bi bi-headset"></i>
                                            </button>
                                            <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-action treinamentos" title="Ver Treinamentos">
                                                <i class="bi bi-calendar-check"></i>
                                            </a>
                                            <a href="treinamento_efetivo.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-action efetivo" title="Treinamento Efetivo">
                                                <i class="bi bi-clipboard-data"></i>
                                            </a>
                                            <button class="btn btn-sm btn-action agendar novo-treino-trigger"
                                                    data-id="<?= $c['id_cliente'] ?>"
                                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                                    data-contatos="<?= htmlspecialchars(json_encode($contatos_por_cliente[$c['id_cliente']] ?? []), ENT_QUOTES) ?>"
                                                    title="Agendar Novo Treinamento"
                                                    onclick="openNovoTreinamentoModal(this)">
                                                <i class="bi bi-calendar-plus"></i>
                                            </button>
                                            <?php if (!empty($c['anexo'])): ?>
                                                <a href="<?= (strpos($c['anexo'], 'http') === 0) ? $c['anexo'] : 'https://' . $c['anexo'] ?>" 
                                                   target="_blank" class="btn btn-sm btn-action gestaopro" title="Link GestãoPRO">
                                                    <i class="bi bi-rocket-takeoff"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php 
                                                $link_chamados_t = '';
                                                if (!empty($c['chamados'])) {
                                                    $link_chamados_t = $c['chamados'];
                                                } elseif (!empty($c['anexo'])) {
                                                    $base_t = (strpos($c['anexo'], 'http') === 0) ? $c['anexo'] : 'https://' . $c['anexo'];
                                                    $link_chamados_t = rtrim($base_t, '?') . '?tab=chamados-abertos';
                                                }
                                            ?>
                                            <?php if (!empty($link_chamados_t)): ?>
                                                <a href="<?= htmlspecialchars($link_chamados_t) ?>" 
                                                   target="_blank" class="btn btn-sm btn-action chamados" title="Link Chamados">
                                                    <i class="bi bi-headset"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($c['link_videos'])): ?>
                                                <a href="<?= htmlspecialchars($c['link_videos']) ?>" 
                                                   target="_blank" class="btn btn-sm btn-action gestaopro" style="color: #ea4335; background: rgba(234, 67, 53, 0.15); border-color: rgba(234, 67, 53, 0.3);" title="Vídeos Passo a Passo">
                                                    <i class="bi bi-youtube"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="?delete=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action delete" onclick="return confirm('Excluir cliente?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de Cliente Premium -->
<div class="modal fade" id="modalCliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <input type="hidden" name="abrir_ficha_contato" value="1">
            <input type="hidden" name="redirect_to" value="contatos.php">
            <div class="modal-header p-4 border-0 bg-main text-white position-relative">
                <div class="position-relative z-1">
                    <h5 class="modal-title fw-bold d-flex align-items-center" id="modalTitle">
                        <i class="bi bi-person-plus-fill me-3 fs-3"></i> Ficha do Cliente
                    </h5>
                    <p class="mb-0 opacity-75 small">Gerencie as informações e configurações da implantação</p>
                </div>
                <button type="button" class="btn-close btn-close-white position-relative z-1" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);"></div>
            </div>

            <div class="modal-body p-4 bg-light" style="max-height: 70vh; overflow-y: auto; overflow-x: hidden; scrollbar-gutter: stable;">
                <input type="hidden" name="id_cliente" id="id_cliente">
                
                <div class="dashboard-section p-4 mb-4">
                    <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                        <i class="bi bi-info-circle me-2"></i> Identificação Principal
                    </h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">Nome Fantasia / Empresa</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-building text-primary"></i></span>
                                <input type="text" name="fantasia" id="fantasia" class="form-control border-start-0 ps-0" required placeholder="Ex: Panificadora Silva">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Vendedor Responsável</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-person-badge text-primary"></i></span>
                                <input type="text" name="vendedor" id="vendedor" class="form-control border-start-0 ps-0" placeholder="Nome do vendedor">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Servidor</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-server text-primary"></i></span>
                                <input type="text" name="servidor" id="servidor" class="form-control border-start-0 ps-0" placeholder="Ex: LOCAL / NUVEM">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-7">
                        <div class="dashboard-section p-4 h-100">
                            <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                                <i class="bi bi-gear-wide-connected me-2"></i> Configurações Técnicas
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Data de Início</label>
                                    <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Data de Conclusão</label>
                                    <input type="date" name="data_fim" id="id_data_fim" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Data Previsão Encerramento</label>
                                    <input type="date" name="data_previsao_encerramento" id="data_previsao_encerramento" class="form-control" title="Data prevista combinada com o cliente">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Emitir NF?</label>
                                    <select name="emitir_nf" id="emitir_nf" class="form-select" onchange="toggleConfigurado(this.value)">
                                        <option value="Não">Não</option>
                                        <option value="Sim">Sim</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Nº de Licenças</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-key text-primary"></i></span>
                                        <input type="number" name="num_licencas" id="num_licencas" class="form-control border-start-0 ps-0" placeholder="0">
                                    </div>
                                </div>
                                <div class="col-md-6" id="div_configurado" style="display: none;">
                                    <label class="form-label small fw-bold text-muted">Já Configurado?</label>
                                    <select name="configurado" id="configurado" class="form-select bg-warning bg-opacity-10 border-warning border-opacity-25">
                                        <option value="Não">Não</option>
                                        <option value="Sim">Sim</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-bold text-muted">Link GestãooPRO</label>
                                    <div class="input-group mb-1">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-link-45deg text-primary"></i></span>
                                        <input type="text" name="anexo" id="anexo" class="form-control border-start-0 ps-0" placeholder="Ex: https://interno.gestaopro.srv.br/clientes/5190">
                                        <button class="btn btn-primary" type="button" onclick="abrirAnexo()">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Link do contrato ou documentos no GestãoPRO</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-bold text-muted">Link Chamados</label>
                                    <div class="input-group mb-1">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-headset text-success"></i></span>
                                        <input type="text" name="chamados" id="chamados" class="form-control border-start-0 ps-0" placeholder="Gerado automaticamente a partir do Link GestãoPRO">
                                        <button class="btn btn-success" type="button" onclick="gerarLinkChamados()" title="Gerar link automaticamente">
                                            <i class="bi bi-magic"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary" type="button" onclick="abrirChamados()" title="Abrir link">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Link direto para os chamados abertos do cliente no GestãoPRO</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-bold text-muted">ID Cliente API (GestãoPro)</label>
                                    <div class="input-group mb-1">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-braces-asterisk text-primary"></i></span>
                                        <input type="number" name="id_cliente_api" id="id_cliente_api" class="form-control border-start-0 ps-0" placeholder="Ex: 5698">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">ID utilizado para vincular os dados da API ao sistema local.</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-bold text-muted">Link Vi­deos Passo a Passo</label>
                                    <div class="input-group mb-1">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-play-circle text-danger"></i></span>
                                        <input type="url" name="link_videos" id="link_videos" class="form-control border-start-0 ps-0" placeholder="Ex: https://drive.google.com/drive/folders/...">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Link da pasta no Google Drive com os vi­deos didaticos.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="dashboard-section p-4 h-100">
                            <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                                <i class="bi bi-plus-circle me-2"></i> Extras
                            </h6>
                            <div class="mb-0">
                                <label class="form-label small fw-bold text-muted">Recursos Utilizados</label>
                                <div class="p-2 border rounded bg-white">
                                    <?php 
                                    $lista_recursos = ['ORÇAMENTO', 'GESTAOGPT', 'ASSISTÊNCIAPRO', 'PRODUÇÃO/OS', 'PDV'];
                                    foreach($lista_recursos as $rec): ?>
                                    <div class="form-check mb-1">
                                      <input class="form-check-input recurso-checkbox" type="checkbox" name="recursos[]" value="<?= $rec ?>" id="rec_<?= md5($rec) ?>">
                                      <label class="form-check-label small" for="rec_<?= md5($rec) ?>"><?= $rec ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>

            <div class="modal-footer p-4 border-0 bg-white">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Descartar</button>
                <button type="submit" class="btn btn-primary px-5 fw-bold" style="border-radius: 12px; height: 50px;">
                    <i class="bi bi-check-lg me-2"></i> Salvar Cliente
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Rápido: Novo Treinamento -->
<div class="modal fade" id="modalNovoTreinamento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="salvar_treinamento.php" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden;">
            <input type="hidden" name="id_cliente" id="nt_id_cliente">
            <input type="hidden" name="redirect_to" value="clientes.php">
            <div class="modal-header p-4 border-0" style="background: linear-gradient(135deg, #7c3aed, #4361ee);">
                <div>
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-plus fs-4"></i> Agendar Treinamento
                    </h5>
                    <p class="mb-0 text-white opacity-75 small" id="nt_cliente_label">Cliente</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Cliente</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:rgba(124,58,237,0.1); border-color:rgba(124,58,237,0.3);"><i class="bi bi-building text-purple" style="color:#7c3aed"></i></span>
                        <input type="text" class="form-control fw-bold" id="nt_cliente_display" readonly
                               style="background:rgba(124,58,237,0.05); border-color:rgba(124,58,237,0.3); cursor:default;">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Contato / Interlocutor</label>
                    <input type="text" name="nome_contato" id="nt_nome_contato" class="form-control" placeholder="Digite o nome do contato..." required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Módulo / Tema</label>
                    <select name="tema" id="nt_tema" class="form-select" required>
                        <option value="INSTALAÇÃO SISTEMA">INSTALAÇÃO SISTEMA</option>
                        <option value="CADASTROS/ESTOQUE">CADASTROS/ESTOQUE</option>
                        <option value="VENDAS">VENDAS</option>
                        <option value="COMPRAS">COMPRAS</option>
                        <option value="FATURAMENTO/NF">FATURAMENTO/NF</option>
                        <option value="FINANCEIRO/CAIXA">FINANCEIRO/CAIXA</option>
                        <option value="PRODUÇÃO/OS">PRODUÇÃO/OS</option>
                        <option value="RELATORIOS">RELATORIOS</option>
                        <option value="ATENDIMENTOS">ATENDIMENTOS</option>
                        <option value="DUVIDAS">DUVIDAS</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-7">
                        <label class="form-label small fw-bold text-muted">Data e Horário</label>
                        <input type="datetime-local" name="data_treinamento" id="nt_data_treinamento" class="form-control" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status" id="nt_status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="AGENDADO">AGENDADO</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary px-5 fw-bold" style="border-radius: 12px; height: 46px;">
                    <i class="bi bi-check-lg me-2"></i> Agendar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de chamados de suporte do cliente -->
<div class="modal fade" id="modalChamadosSuporte" tabindex="-1" aria-labelledby="supportTicketsTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 22px; background: var(--bg-card); overflow: hidden;">
            <div class="modal-header border-0 p-4 text-white" style="background: linear-gradient(135deg, #10234f 0%, #174a79 55%, #0f9f9a 100%);">
                <div class="d-flex align-items-center gap-3 min-w-0">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: rgba(255,255,255,.16); font-size: 1.4rem;">
                        <i class="bi bi-headset"></i>
                    </div>
                    <div class="min-w-0">
                        <h4 class="modal-title fw-bold mb-1 text-truncate" id="supportTicketsTitle">Chamados de suporte</h4>
                        <div class="small text-white-50">
                            <span id="supportTicketsClient">Cliente</span>
                            <span class="mx-1">·</span>
                            ID GestãoPro <strong class="text-white" id="supportTicketsClientId">—</strong>
                            <span class="mx-1">·</span>
                            <span id="supportTicketsUpdated">Aguardando consulta</span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-light fw-semibold" id="supportTicketsRefresh" onclick="carregarChamadosSuporteAtual(true)">
                        <i class="bi bi-arrow-clockwise me-1" id="supportTicketsRefreshIcon"></i>Atualizar
                    </button>
                    <button type="button" class="btn-close btn-close-white ms-1" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
            </div>
            <div class="modal-body p-4" style="background: var(--bg-body); min-height: 430px;">
                <div class="row g-3 mb-4" id="supportTicketsSummary" hidden>
                    <div class="col-12 col-sm-4">
                        <div class="dash-kpi-card py-3">
                            <div class="text-muted small">Total de chamados</div>
                            <div class="fs-3 fw-bold text-main" id="supportTicketsTotal">0</div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="dash-kpi-card py-3">
                            <div class="text-muted small">Em aberto</div>
                            <div class="fs-3 fw-bold text-warning" id="supportTicketsOpen">0</div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="dash-kpi-card py-3">
                            <div class="text-muted small">Resolvidos</div>
                            <div class="fs-3 fw-bold text-success" id="supportTicketsResolved">0</div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-3" id="supportTicketsFilters" hidden>
                    <div class="col-12 col-md">
                        <div class="input-group">
                            <span class="input-group-text bg-card border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="search" class="form-control border-start-0" id="supportTicketsSearch" placeholder="Buscar por número, descrição ou informação...">
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <select class="form-select" id="supportTicketsStatusFilter" aria-label="Filtrar chamados por status">
                            <option value="todos">Todos os chamados</option>
                            <option value="aberto">Em aberto</option>
                            <option value="resolvido">Resolvidos</option>
                            <option value="outro">Outros status</option>
                        </select>
                    </div>
                </div>

                <div id="supportTicketsLoading" class="py-5 text-center" role="status">
                    <div class="spinner-border text-info mb-3" aria-hidden="true"></div>
                    <h5 class="fw-bold text-main mb-1">Carregando chamados</h5>
                    <p class="text-muted small mb-0">Consultando os registros de suporte deste cliente no GestãoPro.</p>
                </div>
                <div id="supportTicketsError" class="alert alert-danger d-none" role="alert"></div>
                <div id="supportTicketsEmpty" class="py-5 text-center d-none">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <h5 class="fw-bold text-main mt-3 mb-1">Nenhum chamado encontrado</h5>
                    <p class="text-muted small mb-0">A API não retornou chamados para este cliente.</p>
                </div>
                <div id="supportTicketsNoMatches" class="py-5 text-center d-none">
                    <i class="bi bi-search fs-1 text-muted"></i>
                    <h5 class="fw-bold text-main mt-3 mb-1">Nenhum resultado para os filtros</h5>
                    <p class="text-muted small mb-0">Tente outro termo ou selecione “Todos os chamados”.</p>
                </div>
                <div id="supportTicketsList" class="d-grid gap-3" aria-live="polite"></div>
            </div>
            <div class="modal-footer border-0 px-4 py-3">
                <span class="small text-muted me-auto" id="supportTicketsResultCount"></span>
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Dashboard de Uso do Cliente -->
<div class="modal fade" id="modalDashboardUso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px; background: var(--bg-card); overflow: hidden;">
            <!-- Modal Header com gradiente moderno -->
            <div class="modal-header p-4 border-0 position-relative text-white" style="background: linear-gradient(135deg, #0b1437 0%, #11235a 50%, #0284c7 100%);">
                <div class="d-flex align-items-center gap-3 flex-grow-1">
                    <div class="indicator-icon rounded-3 text-white shadow-sm flex-shrink-0" style="background: rgba(255,255,255,0.18); width: 48px; height: 48px; display:flex; align-items:center; justify-content:center; font-size: 1.5rem;">
                        <i class="bi bi-speedometer2"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <h4 class="modal-title fw-bold text-white mb-0" id="dashNomeCliente">Dashboard de Uso</h4>
                            <span class="badge rounded-pill px-3 py-1" id="dashBadgeStatus" style="background: rgba(255,255,255,0.2); font-size: 0.75rem;">—</span>
                        </div>
                        <div class="small text-white-50 d-flex align-items-center gap-3 flex-wrap">
                            <span><i class="bi bi-braces-asterisk me-1"></i>ID API: <strong class="text-white" id="dashIdApi">—</strong></span>
                            <span><i class="bi bi-upc-scan me-1"></i>SERIAL: <strong class="text-white" id="dashSerial">—</strong></span>
                            <span id="dashOrigemAtualizacao"><i class="bi bi-clock me-1"></i>Atualizado: —</span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
                    <div class="btn-group btn-group-sm bg-black bg-opacity-25 p-1 rounded-3">
                        <button type="button" class="btn btn-sm btn-outline-light border-0 dash-btn-dias active" data-dias="30" onclick="alterarDiasDashboard(30)">30 dias</button>
                        <button type="button" class="btn btn-sm btn-outline-light border-0 dash-btn-dias" data-dias="15" onclick="alterarDiasDashboard(15)">15 dias</button>
                        <button type="button" class="btn btn-sm btn-outline-light border-0 dash-btn-dias" data-dias="7" onclick="alterarDiasDashboard(7)">7 dias</button>
                    </div>
                    <button class="btn btn-sm btn-light fw-bold px-3 d-flex align-items-center gap-2 shadow-sm" id="dashBtnRecarregar" onclick="recarregarDashboardAtual(true)" title="Consultar dados em tempo real na API">
                        <i class="bi bi-arrow-clockwise" id="dashSpinIcon"></i> <span>Atualizar</span>
                    </button>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
            </div>

            <div class="modal-body p-4" style="background: var(--bg-body); min-height: 520px;">
                <!-- Estado Carregando -->
                <div id="dashLoading" class="py-5 text-center">
                    <div class="spinner-border text-info mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                    <h5 class="fw-bold text-main mb-1">Consultando dados de uso no GestãoPro…</h5>
                    <p class="text-muted small mb-0">Analisando histórico de acessos, módulos e usuários deste cliente.</p>
                </div>

                <!-- Estado Vazio / Sem dados -->
                <div id="dashEmpty" class="py-5 text-center d-none">
                    <div class="indicator-icon mx-auto rounded-circle mb-3 text-muted" style="width: 64px; height: 64px; font-size: 2rem; background: var(--bg-card); display: flex; align-items:center; justify-content:center; border: 1px dashed var(--border-color);">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h5 class="fw-bold text-main mb-2" id="dashEmptyTitle">Nenhum registro de atividade encontrado</h5>
                    <p class="text-muted small mx-auto mb-4" style="max-width: 480px;" id="dashEmptyText">
                        O cliente ainda não possui acessos registrados ou a API GestãoPro não retornou telemetria para este ID no período consultado.
                    </p>
                    <button class="btn btn-primary px-4 fw-bold" onclick="recarregarDashboardAtual(true)">
                        <i class="bi bi-arrow-clockwise me-1"></i> Tentar Forçar Consulta na API
                    </button>
                </div>

                <!-- Conteúdo do Dashboard -->
                <div id="dashBody" class="d-none">
                    <!-- Grade de KPIs Resumo -->
                    <div class="row g-3 mb-4">
                        <!-- KPI 1: Último Acesso -->
                        <div class="col-sm-6 col-xl-3">
                            <div class="dash-kpi-card position-relative overflow-hidden">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small text-muted fw-semibold">Último Acesso</span>
                                    <div class="rounded-3 p-2 text-info" style="background: rgba(6, 182, 212, 0.12);">
                                        <i class="bi bi-clock-history fs-5"></i>
                                    </div>
                                </div>
                                <h4 class="fw-bold text-main mb-1" id="kpiUltimoAcesso">—</h4>
                                <div class="small" id="kpiUltimoRecencia"><span class="badge bg-secondary">Sem dados</span></div>
                            </div>
                        </div>

                        <!-- KPI 2: Pageviews -->
                        <div class="col-sm-6 col-xl-3">
                            <div class="dash-kpi-card position-relative overflow-hidden">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small text-muted fw-semibold">Visualizações (Pageviews)</span>
                                    <div class="rounded-3 p-2 text-primary" style="background: rgba(67, 97, 238, 0.12);">
                                        <i class="bi bi-eye fs-5"></i>
                                    </div>
                                </div>
                                <h4 class="fw-bold text-main mb-1" id="kpiPageviews">0</h4>
                                <div class="small text-muted" id="kpiMediaDiaria">0 views / dia ativo</div>
                            </div>
                        </div>

                        <!-- KPI 3: Logins & Usuários -->
                        <div class="col-sm-6 col-xl-3">
                            <div class="dash-kpi-card position-relative overflow-hidden">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small text-muted fw-semibold">Logins & Operadores</span>
                                    <div class="rounded-3 p-2 text-purple" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6;">
                                        <i class="bi bi-people fs-5"></i>
                                    </div>
                                </div>
                                <h4 class="fw-bold text-main mb-1" id="kpiLogins">0</h4>
                                <div class="small text-muted" id="kpiUsuariosCount">0 operadores ativos</div>
                            </div>
                        </div>

                        <!-- KPI 4: Atritos & Saúde -->
                        <div class="col-sm-6 col-xl-3">
                            <div class="dash-kpi-card position-relative overflow-hidden">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small text-muted fw-semibold">Atritos & Alertas</span>
                                    <div class="rounded-3 p-2" id="kpiSaudeIconWrap" style="background: rgba(245, 158, 11, 0.12); color: #f59e0b;">
                                        <i class="bi bi-exclamation-triangle fs-5" id="kpiSaudeIcon"></i>
                                    </div>
                                </div>
                                <h4 class="fw-bold text-main mb-1" id="kpiAtritos">0</h4>
                                <div class="small text-muted" id="kpiErrosCount">0 erros no sistema</div>
                            </div>
                        </div>
                    </div>

                    <!-- Navegação por Abas -->
                    <ul class="nav nav-pills gap-2 p-1 rounded-3 mb-4 border" style="background: var(--bg-card); width: fit-content;" id="dashTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link dash-nav-pill active" id="tab-visao-btn" data-bs-toggle="pill" data-bs-target="#tab-visao" type="button" role="tab">
                                <i class="bi bi-graph-up me-1"></i> Visão Geral & Módulos
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link dash-nav-pill" id="tab-usuarios-btn" data-bs-toggle="pill" data-bs-target="#tab-usuarios" type="button" role="tab">
                                <i class="bi bi-person-workspace me-1"></i> Usuários (<span id="tabCountUsuarios">0</span>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link dash-nav-pill" id="tab-rotas-btn" data-bs-toggle="pill" data-bs-target="#tab-rotas" type="button" role="tab">
                                <i class="bi bi-signpost-split me-1"></i> Telas Mais Usadas (<span id="tabCountRotas">0</span>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link dash-nav-pill" id="tab-atritos-btn" data-bs-toggle="pill" data-bs-target="#tab-atritos" type="button" role="tab">
                                <i class="bi bi-shield-exclamation me-1"></i> Dificuldades & Atritos (<span id="tabCountAtritos">0</span>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link dash-nav-pill" id="tab-json-btn" data-bs-toggle="pill" data-bs-target="#tab-json" type="button" role="tab">
                                <i class="bi bi-code-slash me-1"></i> Resposta da API
                            </button>
                        </li>
                    </ul>

                    <!-- Conteúdo das Abas -->
                    <div class="tab-content" id="dashTabsContent">
                        <!-- ABA 1: Visão Geral -->
                        <div class="tab-pane fade show active" id="tab-visao" role="tabpanel">
                            <div class="row g-4">
                                <!-- Gráfico Linha do Tempo (ApexCharts) -->
                                <div class="col-lg-8">
                                    <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div>
                                                <h6 class="fw-bold text-main mb-0"><i class="bi bi-calendar3 me-2 text-info"></i>Evolução de Acessos Diários</h6>
                                                <small class="text-muted">Volume de telas acessadas por dia pelo cliente</small>
                                            </div>
                                            <span class="badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill small" id="dashDiasAtivosLabel">0 dias com atividade</span>
                                        </div>
                                        <div id="chart-uso-timeline" style="min-height: 280px;"></div>
                                    </div>
                                </div>

                                <!-- Gráfico de Módulos (Donut / Rosca) -->
                                <div class="col-lg-4">
                                    <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                        <h6 class="fw-bold text-main mb-1"><i class="bi bi-pie-chart me-2 text-primary"></i>Uso por Módulo</h6>
                                        <small class="text-muted d-block mb-3">Onde o cliente mais passa tempo</small>
                                        <div id="chart-uso-modulos" style="min-height: 280px;" class="d-flex align-items-center justify-content-center"></div>
                                    </div>
                                </div>

                                <!-- Tabela Detalhada de Módulos -->
                                <div class="col-12">
                                    <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                        <h6 class="fw-bold text-main mb-3"><i class="bi bi-grid-3x3-gap me-2 text-info"></i>Detalhamento por Módulo / Aplicativo</h6>
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-muted);">
                                                    <tr>
                                                        <th class="ps-3">Módulo</th>
                                                        <th>Participação</th>
                                                        <th class="text-center">Pageviews</th>
                                                        <th class="text-center">Operadores</th>
                                                        <th class="pe-3 text-end">Último Acesso no Módulo</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dashTbodyModulos"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ABA 2: Usuários / Operadores -->
                        <div class="tab-pane fade" id="tab-usuarios" role="tabpanel">
                            <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <div>
                                        <h6 class="fw-bold text-main mb-0"><i class="bi bi-people me-2 text-purple"></i>Operadores e Usuários Ativos</h6>
                                        <small class="text-muted">Acessos individuais por login do cliente no período</small>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-muted);">
                                            <tr>
                                                <th class="ps-3">Operador / Login</th>
                                                <th class="text-center">Pageviews</th>
                                                <th class="text-center">Logins Efetuados</th>
                                                <th class="text-center">Atritos</th>
                                                <th class="text-center">Derrubadas</th>
                                                <th class="pe-3 text-end">Último Acesso Deste Usuário</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dashTbodyUsuarios"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ABA 3: Rotas & Telas -->
                        <div class="tab-pane fade" id="tab-rotas" role="tabpanel">
                            <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                <h6 class="fw-bold text-main mb-1"><i class="bi bi-signpost-split me-2 text-info"></i>Funcionalidades e Telas Mais Acessadas</h6>
                                <small class="text-muted d-block mb-3">Ranking das ações e rotas executadas no sistema</small>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-muted);">
                                            <tr>
                                                <th class="ps-3">Módulo</th>
                                                <th>Tela / Rota</th>
                                                <th style="width: 35%;">Volume Relativo</th>
                                                <th class="pe-3 text-end">Total de Acessos</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dashTbodyRotas"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ABA 4: Atritos & Dificuldades -->
                        <div class="tab-pane fade" id="tab-atritos" role="tabpanel">
                            <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                <div class="alert alert-warning border-0 d-flex align-items-center rounded-3 p-3 mb-4">
                                    <i class="bi bi-info-circle-fill fs-5 me-3 flex-shrink-0"></i>
                                    <div class="small">
                                        <strong>O que são Atritos?</strong> São mensagens de aviso, bloqueio ou validação exibidas ao cliente enquanto operava o sistema (ex: falta de dados obrigatórios, produtos não encontrados, parcelas pendentes). Use isso no treinamento para orientar o cliente onde ele mais tem dúvidas!
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-muted);">
                                            <tr>
                                                <th class="ps-3">Módulo / Tela</th>
                                                <th>Mensagem Recebida pelo Cliente</th>
                                                <th class="pe-3 text-end">Ocorrências</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dashTbodyAtritos"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ABA 5: JSON Bruto -->
                        <div class="tab-pane fade" id="tab-json" role="tabpanel">
                            <div class="card p-4 border" style="background: var(--bg-card); border-radius: 20px;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold text-main mb-0"><i class="bi bi-code me-2 text-muted"></i>Resposta Bruta da API GestãoPro</h6>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="copiarJsonDashboard()">
                                        <i class="bi bi-clipboard me-1"></i> Copiar JSON
                                    </button>
                                </div>
                                <pre id="dashJsonPre" class="p-3 rounded-3 small mb-0 border" style="background: var(--bg-body); max-height: 480px; overflow-y: auto; color: var(--text-main); font-family: monospace;"></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer p-3 border-0" style="background: var(--bg-card);">
                <button type="button" class="btn btn-secondary px-4 fw-bold" style="border-radius: 10px;" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Biblioteca ApexCharts para gráficos dinâmicos do Dashboard -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<script>
    function escaparHtmlChamadosCliente(valor) {
        return String(valor ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function localizarChamadosCliente(dados) {
        if (Array.isArray(dados)) {
            return dados.filter(item => item && typeof item === 'object' && !Array.isArray(item));
        }
        if (!dados || typeof dados !== 'object') return null;

        let encontrouColecaoVazia = false;
        for (const chave of ['chamados', 'implantacao', 'implantacoes', 'tickets', 'data', 'dados', 'items', 'registros', 'results']) {
            if (dados[chave] !== undefined) {
                const encontrados = localizarChamadosCliente(dados[chave]);
                if (encontrados && encontrados.length) return encontrados;
                if (encontrados !== null) encontrouColecaoVazia = true;
            }
        }

        const chavesConhecidas = new Set(['chamados', 'implantacao', 'implantacoes', 'tickets', 'data', 'dados', 'items', 'registros', 'results']);
        for (const [chave, valor] of Object.entries(dados)) {
            if (!chavesConhecidas.has(chave.toLowerCase()) && valor && typeof valor === 'object') {
                const encontrados = localizarChamadosCliente(valor);
                if (encontrados && encontrados.length) return encontrados;
                if (encontrados !== null) encontrouColecaoVazia = true;
            }
        }

        const chaves = Object.keys(dados).map(chave => chave.toLowerCase());
        if (chaves.some(chave => ['id_chamado', 'numero', 'ticket_id'].includes(chave))) {
            return [dados];
        }
        if (chaves.includes('id') && !Object.values(dados).some(valor => valor && typeof valor === 'object')) {
            return [dados];
        }

        return encontrouColecaoVazia ? [] : null;
    }

    function campoChamadoCliente(chamado, alternativas) {
        for (const chave of alternativas) {
            if (chamado[chave] !== undefined && chamado[chave] !== null && String(chamado[chave]).trim() !== '') {
                return chamado[chave];
            }
        }
        const chavesLowercase = new Map(Object.keys(chamado).map(chave => [chave.toLowerCase(), chave]));
        for (const chave of alternativas) {
            const chaveReal = chavesLowercase.get(chave.toLowerCase());
            if (chaveReal && chamado[chaveReal] !== null && String(chamado[chaveReal]).trim() !== '') {
                return chamado[chaveReal];
            }
        }
        return '';
    }

    function valorLegivelChamadoCliente(valor) {
        if (valor === null || valor === undefined || valor === '') return 'Não informado';
        if (typeof valor === 'object') {
            return Array.isArray(valor)
                ? valor.map(valorLegivelChamadoCliente).join(', ')
                : JSON.stringify(valor);
        }
        return String(valor);
    }

    function rotuloCampoChamadoCliente(chave) {
        return String(chave)
            .replace(/_/g, ' ')
            .toLowerCase()
            .replace(/\b\w/g, letra => letra.toUpperCase());
    }

    let chamadosSuporteAtivos = { idApi: '', fantasia: '', chamados: [], origem: 'api' };
    let requisicaoChamadosSuporte = 0;

    function classificarStatusChamadoCliente(status) {
        const normalizado = String(status || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        if (/nao\s+(resolvid|concluid|finalizad|fechad|encerrad)/.test(normalizado)) return 'aberto';
        if (/(resolvid|concluid|finalizad|fechad|encerrad)/.test(normalizado)) return 'resolvido';
        if (/(abert|pendente|andamento|atendimento|aguard|novo)/.test(normalizado)) return 'aberto';
        return 'outro';
    }

    function formatarDataChamadoCliente(valor) {
        if (!valor) return '';
        const texto = String(valor);
        const data = new Date(texto);
        if (Number.isNaN(data.getTime())) return texto;
        return data.toLocaleString('pt-BR', {
            timeZone: 'America/Sao_Paulo',
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function atualizarResumoChamadosCliente(chamados) {
        const abertos = chamados.filter(chamado => classificarStatusChamadoCliente(campoChamadoCliente(chamado, ['CHAMADO_STATUS', 'STATUS_CHAMADO', 'STATUS', 'status'])) === 'aberto').length;
        const resolvidos = chamados.filter(chamado => classificarStatusChamadoCliente(campoChamadoCliente(chamado, ['CHAMADO_STATUS', 'STATUS_CHAMADO', 'STATUS', 'status'])) === 'resolvido').length;
        document.getElementById('supportTicketsTotal').textContent = chamados.length.toLocaleString('pt-BR');
        document.getElementById('supportTicketsOpen').textContent = abertos.toLocaleString('pt-BR');
        document.getElementById('supportTicketsResolved').textContent = resolvidos.toLocaleString('pt-BR');
        document.getElementById('supportTicketsSummary').hidden = false;
    }

    function renderizarChamadoSuporte(chamado) {
        const id = campoChamadoCliente(chamado, ['ID', 'ID_CHAMADO', 'id_chamado', 'numero', 'ticket_id']);
        const status = campoChamadoCliente(chamado, ['CHAMADO_STATUS', 'STATUS_CHAMADO', 'STATUS', 'status']);
        const assunto = campoChamadoCliente(chamado, ['ASSUNTO', 'TITULO', 'TEMA', 'assunto']);
        const descricao = campoChamadoCliente(chamado, ['DESCRICAO', 'DESCRICAO_PROBLEMA', 'MENSAGEM', 'descricao']);
        const data = campoChamadoCliente(chamado, ['DATA_ABERTURA', 'DATA_CRIACAO', 'DATA', 'CRIADO_EM', 'created_at']);
        const categoria = classificarStatusChamadoCliente(status);
        const classeStatus = categoria === 'resolvido' ? 'bg-success-subtle text-success' : (categoria === 'aberto' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-secondary-subtle text-secondary');
        const ignorados = new Set([
            'id', 'id_chamado', 'numero', 'ticket_id',
            'chamado_status', 'status_chamado', 'status',
            'descricao', 'descricao_problema', 'mensagem',
            'assunto', 'titulo', 'tema',
            'data_abertura', 'data_criacao', 'data', 'criado_em', 'created_at'
        ]);
        const detalhes = Object.entries(chamado)
            .filter(([chave, valor]) => !ignorados.has(chave.toLowerCase()) && valor !== null && valor !== '')
            .map(([chave, valor]) => `<div class="support-ticket-detail"><div class="text-muted small mb-1">${escaparHtmlChamadosCliente(rotuloCampoChamadoCliente(chave))}</div><div class="fw-medium">${escaparHtmlChamadosCliente(valorLegivelChamadoCliente(valor))}</div></div>`)
            .join('');
        const textoPrincipal = assunto || descricao || 'Sem descrição disponível.';
        const descricaoSecundaria = assunto && descricao && assunto !== descricao
            ? `<div class="support-ticket-description text-muted small mt-2">${escaparHtmlChamadosCliente(descricao)}</div>`
            : '';

        return `<article class="support-ticket-card" data-support-ticket data-status="${categoria}" data-search="${escaparHtmlChamadosCliente(JSON.stringify(chamado).toLowerCase())}">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge rounded-pill bg-primary-subtle text-primary">${id !== '' ? '#' + escaparHtmlChamadosCliente(id) : 'Chamado'}</span>
                    ${status !== '' ? `<span class="badge rounded-pill ${classeStatus}">${escaparHtmlChamadosCliente(status)}</span>` : '<span class="badge rounded-pill bg-secondary-subtle text-secondary">Status não informado</span>'}
                </div>
                ${data !== '' ? `<span class="small text-muted"><i class="bi bi-calendar3 me-1"></i>${escaparHtmlChamadosCliente(formatarDataChamadoCliente(data))}</span>` : ''}
            </div>
            <h6 class="fw-bold text-main mt-3 mb-0">${escaparHtmlChamadosCliente(textoPrincipal)}</h6>
            ${descricaoSecundaria}
            ${detalhes ? `<details class="mt-3">
                <summary class="small fw-semibold text-primary" style="cursor:pointer">Ver outras informações</summary>
                <div class="support-ticket-details mt-3">${detalhes}</div>
            </details>` : ''}
        </article>`;
    }

    function filtrarChamadosSuporte() {
        const lista = document.getElementById('supportTicketsList');
        const termo = document.getElementById('supportTicketsSearch').value.trim().toLowerCase();
        const statusSelecionado = document.getElementById('supportTicketsStatusFilter').value;
        let exibidos = 0;

        lista.querySelectorAll('[data-support-ticket]').forEach(item => {
            const correspondeTermo = !termo || item.dataset.search.includes(termo);
            const correspondeStatus = statusSelecionado === 'todos' || item.dataset.status === statusSelecionado;
            const visivel = correspondeTermo && correspondeStatus;
            item.classList.toggle('d-none', !visivel);
            if (visivel) exibidos++;
        });

        document.getElementById('supportTicketsNoMatches').classList.toggle('d-none', exibidos > 0 || chamadosSuporteAtivos.chamados.length === 0);
        document.getElementById('supportTicketsResultCount').textContent = `${exibidos.toLocaleString('pt-BR')} de ${chamadosSuporteAtivos.chamados.length.toLocaleString('pt-BR')} chamado(s)`;
    }

    function exibirChamadosSuporte(dados, origem, geradoEm) {
        const chamados = localizarChamadosCliente(dados);
        if (chamados === null) {
            throw new Error('A resposta da API veio em um formato que não permite identificar os chamados.');
        }
        chamadosSuporteAtivos.chamados = chamados;
        chamadosSuporteAtivos.origem = origem;
        document.getElementById('supportTicketsLoading').classList.add('d-none');
        document.getElementById('supportTicketsError').classList.add('d-none');
        document.getElementById('supportTicketsList').innerHTML = chamados.map(renderizarChamadoSuporte).join('');
        document.getElementById('supportTicketsEmpty').classList.toggle('d-none', chamados.length > 0);
        document.getElementById('supportTicketsFilters').hidden = chamados.length === 0;
        document.getElementById('supportTicketsSearch').value = '';
        document.getElementById('supportTicketsStatusFilter').value = 'todos';
        const atualizacao = geradoEm ? ` · ${geradoEm}` : '';
        document.getElementById('supportTicketsUpdated').textContent = `${origem === 'cache' ? 'Cache local' : 'GestãoPro'}${atualizacao}`;
        atualizarResumoChamadosCliente(chamados);
        filtrarChamadosSuporte();
    }

    async function abrirChamadosSuporte(elemento) {
        const idApi = (elemento.dataset.idApi || '').trim();
        if (!idApi) return;

        chamadosSuporteAtivos = { idApi, fantasia: elemento.dataset.fantasia || 'Cliente', chamados: [], origem: 'api' };
        document.getElementById('supportTicketsClient').textContent = chamadosSuporteAtivos.fantasia;
        document.getElementById('supportTicketsClientId').textContent = idApi;
        document.getElementById('supportTicketsUpdated').textContent = 'Consultando GestãoPro...';
        document.getElementById('supportTicketsSummary').hidden = true;
        document.getElementById('supportTicketsFilters').hidden = true;
        document.getElementById('supportTicketsList').innerHTML = '';
        document.getElementById('supportTicketsResultCount').textContent = '';
        document.getElementById('supportTicketsError').classList.add('d-none');
        document.getElementById('supportTicketsEmpty').classList.add('d-none');
        document.getElementById('supportTicketsNoMatches').classList.add('d-none');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChamadosSuporte')).show();
        await carregarChamadosSuporte(idApi);
    }

    async function carregarChamadosSuporte(idApi, forcar = false) {
        const requisicao = ++requisicaoChamadosSuporte;
        const loading = document.getElementById('supportTicketsLoading');
        const refreshButton = document.getElementById('supportTicketsRefresh');
        const refreshIcon = document.getElementById('supportTicketsRefreshIcon');
        const error = document.getElementById('supportTicketsError');
        loading.classList.remove('d-none');
        error.classList.add('d-none');
        refreshButton.disabled = true;
        refreshIcon.classList.add('spin-animation');

        try {
            const query = new URLSearchParams({ endpoint: 'chamados_cliente', id_cliente_api: idApi });
            if (forcar) query.set('forcar', '1');
            const resposta = await fetch(`api_gestaopro_bridge.php?${query.toString()}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await resposta.json();
            if (!resposta.ok || !json.sucesso) {
                throw new Error(json.erro || 'Não foi possível carregar os chamados deste cliente.');
            }
            if (requisicao !== requisicaoChamadosSuporte || chamadosSuporteAtivos.idApi !== idApi) return;
            exibirChamadosSuporte(json.dados, json.origem || 'api', json.gerado_em || '');
        } catch (erro) {
            if (requisicao !== requisicaoChamadosSuporte || chamadosSuporteAtivos.idApi !== idApi) return;
            loading.classList.add('d-none');
            error.textContent = `Falha ao carregar os chamados: ${erro.message || 'erro desconhecido'}`;
            error.classList.remove('d-none');
            document.getElementById('supportTicketsUpdated').textContent = 'Falha na consulta';
            console.error('Erro ao carregar chamados do cliente:', erro);
        } finally {
            if (requisicao === requisicaoChamadosSuporte) {
                refreshButton.disabled = false;
                refreshIcon.classList.remove('spin-animation');
            }
        }
    }

    function carregarChamadosSuporteAtual(forcar = false) {
        if (!chamadosSuporteAtivos.idApi) return;
        carregarChamadosSuporte(chamadosSuporteAtivos.idApi, forcar);
    }

    document.getElementById('supportTicketsSearch').addEventListener('input', filtrarChamadosSuporte);
    document.getElementById('supportTicketsStatusFilter').addEventListener('change', filtrarChamadosSuporte);

    // --- GERENCIADOR DO DASHBOARD POR CLIENTE ---
    let dashClienteAtivo = null;
    let dashDiasAtivos = 30;
    let chartTimelineInstancia = null;
    let chartModulosInstancia = null;
    let cacheDadosDashboard = {};

    function formatarDataHoraIso(isoStr) {
        if (!isoStr) return null;
        try {
            const dt = new Date(isoStr);
            if (isNaN(dt.getTime())) return null;
            return dt.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) { return null; }
    }

    function formatarTempoRelativo(isoStr) {
        if (!isoStr) return { texto: 'Sem registros', badge: 'bg-secondary', badgeLabel: 'Sem dados', ehHoje: false };
        try {
            const dt = new Date(isoStr);
            if (isNaN(dt.getTime())) return { texto: 'Inválido', badge: 'bg-secondary', badgeLabel: 'Inválido', ehHoje: false };
            const agora = new Date();
            const diffMs = agora.getTime() - dt.getTime();
            const diffMin = Math.floor(diffMs / 60000);
            const diffHoras = Math.floor(diffMs / 3600000);
            const diffDias = Math.floor(diffMs / 86400000);

            const dtFmt = dt.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            const mesmoDia = dt.toDateString() === agora.toDateString();

            if (mesmoDia) {
                const tempo = diffMin < 5 ? 'Agora mesmo' : (diffMin < 60 ? `Há ${diffMin} min` : `Há ${diffHoras}h (Hoje às ${dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })})`);
                return { texto: tempo, dataCompleta: dtFmt, badge: 'bg-success bg-opacity-15 text-success border border-success border-opacity-25', badgeLabel: 'Ativo Hoje', ehHoje: true };
            }

            const ontem = new Date();
            ontem.setDate(ontem.getDate() - 1);
            if (dt.toDateString() === ontem.toDateString()) {
                return { texto: `Ontem às ${dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`, dataCompleta: dtFmt, badge: 'bg-primary bg-opacity-10 text-primary', badgeLabel: 'Ativo Ontem', ehHoje: false };
            }

            if (diffDias <= 3) {
                return { texto: `Há ${diffDias} dias (${dtFmt})`, dataCompleta: dtFmt, badge: 'bg-info bg-opacity-10 text-info', badgeLabel: `Há ${diffDias}d`, ehHoje: false };
            }
            if (diffDias <= 7) {
                return { texto: `Há ${diffDias} dias (${dtFmt})`, dataCompleta: dtFmt, badge: 'bg-warning bg-opacity-10 text-warning', badgeLabel: `Atenção (${diffDias}d)`, ehHoje: false };
            }
            return { texto: `Há ${diffDias} dias (${dtFmt})`, dataCompleta: dtFmt, badge: 'bg-danger bg-opacity-10 text-danger', badgeLabel: `Inativo há ${diffDias}d`, ehHoje: false };
        } catch (e) {
            return { texto: 'Data indisponível', badge: 'bg-secondary', badgeLabel: 'Indisponível', ehHoje: false };
        }
    }

    function destruirGraficosDashboard() {
        if (chartTimelineInstancia) {
            try { chartTimelineInstancia.destroy(); } catch (e) {}
            chartTimelineInstancia = null;
        }
        if (chartModulosInstancia) {
            try { chartModulosInstancia.destroy(); } catch (e) {}
            chartModulosInstancia = null;
        }
    }

    async function abrirDashboardUso(elem, dias = 30, forcar = false) {
        const d = elem.dataset || {};
        const idApi = (d.idApi || '').trim();
        const idCliente = d.idCliente || '';
        const fantasia = d.fantasia || 'Cliente';
        const serial = d.serial || '';

        dashClienteAtivo = { idApi, idCliente, fantasia, serial };
        dashDiasAtivos = dias;

        // Atualizar Header do Modal
        document.getElementById('dashNomeCliente').textContent = fantasia;
        document.getElementById('dashIdApi').textContent = idApi || 'Não cadastrado';
        document.getElementById('dashSerial').textContent = serial || 'Não informado';
        document.getElementById('dashOrigemAtualizacao').innerHTML = '<i class="bi bi-clock me-1"></i>Consultando...';
        document.getElementById('dashBadgeStatus').className = 'badge rounded-pill px-3 py-1 bg-secondary';
        document.getElementById('dashBadgeStatus').textContent = 'Carregando...';

        // Atualizar botões de dias
        document.querySelectorAll('.dash-btn-dias').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.dias, 10) === dias);
        });

        // Exibir Modal
        const modalEl = document.getElementById('modalDashboardUso');
        const modalInst = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInst.show();

        if (!idApi) {
            document.getElementById('dashLoading').classList.add('d-none');
            document.getElementById('dashBody').classList.add('d-none');
            document.getElementById('dashEmpty').classList.remove('d-none');
            document.getElementById('dashEmptyTitle').textContent = 'Cliente sem ID Cliente API';
            document.getElementById('dashEmptyText').textContent = 'Para visualizar as estatísticas de uso em tempo real do GestãoPro, informe o ID API ou o link do GestãoPro na ficha de edição deste cliente.';
            document.getElementById('dashBadgeStatus').textContent = 'Sem ID API';
            return;
        }

        await consultarDadosUsoCliente(idApi, dias, forcar);
    }

    async function alterarDiasDashboard(dias) {
        dashDiasAtivos = dias;
        document.querySelectorAll('.dash-btn-dias').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.dias, 10) === dias);
        });
        if (dashClienteAtivo && dashClienteAtivo.idApi) {
            await consultarDadosUsoCliente(dashClienteAtivo.idApi, dias, false);
        }
    }

    async function recarregarDashboardAtual(forcar = true) {
        if (!dashClienteAtivo || !dashClienteAtivo.idApi) return;
        const icon = document.getElementById('dashSpinIcon');
        const btn = document.getElementById('dashBtnRecarregar');
        if (icon) icon.classList.add('spin-animation');
        if (btn) btn.disabled = true;
        try {
            await consultarDadosUsoCliente(dashClienteAtivo.idApi, dashDiasAtivos, forcar);
        } finally {
            if (icon) icon.classList.remove('spin-animation');
            if (btn) btn.disabled = false;
        }
    }

    async function consultarDadosUsoCliente(idApi, dias, forcar = false) {
        const loadingEl = document.getElementById('dashLoading');
        const emptyEl = document.getElementById('dashEmpty');
        const bodyEl = document.getElementById('dashBody');

        loadingEl.classList.remove('d-none');
        emptyEl.classList.add('d-none');
        bodyEl.classList.add('d-none');

        destruirGraficosDashboard();

        try {
            const url = `api_gestaopro_bridge.php?endpoint=uso&id_cliente_api=${encodeURIComponent(idApi)}&dias=${dias}${forcar ? '&forcar=1' : ''}`;
            const resp = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const json = await resp.json();

            if (!resp.ok || !json.sucesso) {
                throw new Error(json.erro || 'Não foi possível carregar os dados de uso deste cliente.');
            }

            const dados = json.dados;
            if (!dados || (typeof dados === 'object' && Object.keys(dados).length === 0)) {
                loadingEl.classList.add('d-none');
                emptyEl.classList.remove('d-none');
                document.getElementById('dashEmptyTitle').textContent = 'Nenhum dado retornado para este cliente';
                document.getElementById('dashEmptyText').textContent = 'A API do GestãoPro retornou resposta vazia para o período selecionado.';
                document.getElementById('dashBadgeStatus').textContent = 'Sem dados';
                return;
            }

            renderizarDashboardUso(dados, dias, json.origem || 'api', json.gerado_em || 'agora');

            loadingEl.classList.add('d-none');
            bodyEl.classList.remove('d-none');

        } catch (err) {
            console.error('Erro no Dashboard de uso:', err);
            loadingEl.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            document.getElementById('dashEmptyTitle').textContent = 'Falha ao consultar uso';
            document.getElementById('dashEmptyText').textContent = err.message || 'Houve um erro na comunicação com a API GestãoPro.';
            document.getElementById('dashBadgeStatus').textContent = 'Erro';
        }
    }

    function renderizarDashboardUso(dados, dias, origem, geradoEm) {
        const resumo = dados.resumo || {};
        const diario = Array.isArray(dados.diario) ? dados.diario : [];
        const modulos = Array.isArray(dados.modulos) ? dados.modulos : [];
        const usuarios = Array.isArray(dados.usuarios) ? dados.usuarios : [];
        const rotas = Array.isArray(dados.rotas) ? dados.rotas : [];
        const atritos = Array.isArray(dados.atritos) ? dados.atritos : [];
        const erros = Array.isArray(dados.erros) ? dados.erros : [];

        // Origem & Atualização
        const origemTexto = origem === 'cache' ? 'Em cache local' : 'API em tempo real';
        document.getElementById('dashOrigemAtualizacao').innerHTML = `<i class="bi bi-clock me-1"></i>Atualizado: <strong>${geradoEm}</strong> (${origemTexto})`;

        // Status e KPIs
        const ultimoIso = resumo.ULTIMO;
        const statusUso = formatarTempoRelativo(ultimoIso);

        const badgeStatusEl = document.getElementById('dashBadgeStatus');
        badgeStatusEl.className = `badge rounded-pill px-3 py-1 ${statusUso.badge}`;
        badgeStatusEl.innerHTML = statusUso.ehHoje ? `<span class="pulse-dot-green me-1"></span>${statusUso.badgeLabel}` : statusUso.badgeLabel;

        // KPI 1: Último Acesso
        const kpiUltimoEl = document.getElementById('kpiUltimoAcesso');
        const kpiUltimoRecenciaEl = document.getElementById('kpiUltimoRecencia');
        if (ultimoIso) {
            const dataFmt = formatarDataHoraIso(ultimoIso);
            kpiUltimoEl.textContent = dataFmt || statusUso.texto;
            kpiUltimoRecenciaEl.innerHTML = `<span class="badge rounded-pill ${statusUso.badge}">${statusUso.texto}</span>`;
        } else {
            kpiUltimoEl.textContent = 'Sem acessos';
            kpiUltimoRecenciaEl.innerHTML = '<span class="badge bg-secondary">Nenhum login nos últimos 30d</span>';
        }

        // KPI 2: Pageviews
        const totalPageviews = parseInt(resumo.PAGEVIEWS || 0, 10);
        document.getElementById('kpiPageviews').textContent = totalPageviews.toLocaleString('pt-BR');
        const diasComAtividade = diario.filter(d => (parseInt(d.PAGEVIEWS, 10) || 0) > 0).length;
        const mediaDiaria = diasComAtividade > 0 ? Math.round(totalPageviews / diasComAtividade) : 0;
        document.getElementById('kpiMediaDiaria').textContent = `${mediaDiaria.toLocaleString('pt-BR')} views / dia ativo (${diasComAtividade}d ativos)`;
        document.getElementById('dashDiasAtivosLabel').textContent = `${diasComAtividade} de ${dias} dias com atividade`;

        // KPI 3: Logins & Usuários
        const totalLogins = parseInt(resumo.LOGINS || 0, 10);
        const qtdUsuarios = parseInt(resumo.USUARIOS || usuarios.length || 0, 10);
        document.getElementById('kpiLogins').textContent = totalLogins.toLocaleString('pt-BR');
        document.getElementById('kpiUsuariosCount').textContent = `${qtdUsuarios} operador(es) ativo(s)`;

        // KPI 4: Atritos & Saúde
        const totalAtritos = parseInt(resumo.ATRITOS || 0, 10);
        const totalErros = parseInt(resumo.ERROS || erros.length || 0, 10);
        document.getElementById('kpiAtritos').textContent = totalAtritos.toLocaleString('pt-BR');
        document.getElementById('kpiErrosCount').textContent = totalErros > 0 ? `${totalErros} erro(s) técnico(s)` : 'Nenhum erro registrado';

        const saudeWrap = document.getElementById('kpiSaudeIconWrap');
        if (totalErros > 0) {
            saudeWrap.style.background = 'rgba(239, 68, 68, 0.15)';
            saudeWrap.style.color = '#ef4444';
        } else if (totalAtritos > 20) {
            saudeWrap.style.background = 'rgba(245, 158, 11, 0.15)';
            saudeWrap.style.color = '#f59e0b';
        } else {
            saudeWrap.style.background = 'rgba(16, 185, 129, 0.15)';
            saudeWrap.style.color = '#10b981';
        }

        // Atualizar Badges das Abas
        document.getElementById('tabCountUsuarios').textContent = usuarios.length;
        document.getElementById('tabCountRotas').textContent = rotas.length;
        document.getElementById('tabCountAtritos').textContent = atritos.length;

        // Renderizar Gráficos ApexCharts
        destruirGraficosDashboard();
        renderizarGraficoTimeline(diario);
        renderizarGraficoModulos(modulos);

        // Renderizar Tabelas
        renderizarTabelaModulos(modulos, totalPageviews);
        renderizarTabelaUsuarios(usuarios);
        renderizarTabelaRotas(rotas, totalPageviews);
        renderizarTabelaAtritos(atritos);

        // Renderizar JSON Bruto
        document.getElementById('dashJsonPre').textContent = JSON.stringify(dados, null, 2);
    }

    function renderizarGraficoTimeline(diario) {
        const container = document.querySelector("#chart-uso-timeline");
        if (!container) return;
        container.innerHTML = '';

        if (!diario || diario.length === 0) {
            container.innerHTML = '<div class="text-center py-5 text-muted small"><i class="bi bi-calendar-x fs-4 d-block mb-2"></i>Sem histórico diário no período</div>';
            return;
        }

        const datas = diario.map(d => {
            const partes = (d.DIA || '').split('-');
            return partes.length === 3 ? `${partes[2]}/${partes[1]}` : d.DIA;
        });
        const pageviews = diario.map(d => parseInt(d.PAGEVIEWS || 0, 10));

        const options = {
            series: [{ name: 'Pageviews', data: pageviews }],
            chart: {
                type: 'area',
                height: 280,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'Inter, sans-serif',
                foreColor: 'var(--text-muted)',
                background: 'transparent'
            },
            colors: ['#06b6d4'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2.5 },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.45,
                    opacityTo: 0.05,
                    stops: [0, 95, 100]
                }
            },
            xaxis: {
                categories: datas,
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: {
                labels: {
                    formatter: val => Math.round(val)
                }
            },
            grid: {
                borderColor: 'rgba(255,255,255,0.06)',
                strokeDashArray: 3
            },
            tooltip: {
                theme: document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light',
                y: { formatter: val => `${val} acessos a telas` }
            }
        };

        chartTimelineInstancia = new ApexCharts(container, options);
        chartTimelineInstancia.render();
    }

    function renderizarGraficoModulos(modulos) {
        const container = document.querySelector("#chart-uso-modulos");
        if (!container) return;
        container.innerHTML = '';

        if (!modulos || modulos.length === 0) {
            container.innerHTML = '<div class="text-center py-5 text-muted small"><i class="bi bi-pie-chart fs-4 d-block mb-2"></i>Sem dados de módulos</div>';
            return;
        }

        const labels = modulos.map(m => (m.APP || 'Outros').toUpperCase());
        const series = modulos.map(m => parseInt(m.PAGEVIEWS || 0, 10));
        const cores = ['#06b6d4', '#4361ee', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#64748b'];

        const options = {
            series: series,
            labels: labels,
            chart: {
                type: 'donut',
                height: 280,
                fontFamily: 'Inter, sans-serif',
                foreColor: 'var(--text-muted)'
            },
            colors: cores.slice(0, series.length),
            dataLabels: { enabled: false },
            legend: { position: 'bottom', fontSize: '11px' },
            plotOptions: {
                pie: {
                    donut: {
                        size: '72%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total',
                                color: 'var(--text-muted)',
                                formatter: w => w.globals.seriesTotals.reduce((a, b) => a + b, 0).toLocaleString('pt-BR')
                            }
                        }
                    }
                }
            },
            tooltip: {
                theme: document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'
            }
        };

        chartModulosInstancia = new ApexCharts(container, options);
        chartModulosInstancia.render();
    }

    function renderizarTabelaModulos(modulos, totalGeral) {
        const tbody = document.getElementById('dashTbodyModulos');
        if (!tbody) return;
        if (!modulos || modulos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">Nenhum módulo utilizado no período.</td></tr>';
            return;
        }

        tbody.innerHTML = modulos.map(m => {
            const pviews = parseInt(m.PAGEVIEWS || 0, 10);
            const pct = totalGeral > 0 ? Math.round((pviews / totalGeral) * 100) : 0;
            const ultimo = formatarDataHoraIso(m.ULTIMO) || '—';
            const usuariosQtd = m.USUARIOS || 1;

            return `
                <tr>
                    <td class="ps-3 fw-bold text-main">
                        <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1 rounded-pill me-2">${escapeHtml((m.APP || 'core').toUpperCase())}</span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2" style="max-width: 220px;">
                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px; background: rgba(255,255,255,0.06);">
                                <div class="progress-bar bg-info" style="width: ${pct}%"></div>
                            </div>
                            <span class="small fw-semibold text-muted">${pct}%</span>
                        </div>
                    </td>
                    <td class="text-center fw-bold text-main">${pviews.toLocaleString('pt-BR')}</td>
                    <td class="text-center"><span class="badge bg-secondary bg-opacity-10 text-secondary">${usuariosQtd}</span></td>
                    <td class="pe-3 text-end text-muted small"><i class="bi bi-clock me-1"></i>${ultimo}</td>
                </tr>
            `;
        }).join('');
    }

    function renderizarTabelaUsuarios(usuarios) {
        const tbody = document.getElementById('dashTbodyUsuarios');
        if (!tbody) return;
        if (!usuarios || usuarios.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Nenhum usuário registrado.</td></tr>';
            return;
        }

        tbody.innerHTML = usuarios.map(u => {
            const login = u.LOGIN || `Usuário #${u.ID_USUARIO || ''}`;
            const pviews = parseInt(u.PAGEVIEWS || 0, 10);
            const logins = parseInt(u.LOGINS || 0, 10);
            const atritos = parseInt(u.ATRITOS || 0, 10);
            const derrubadas = parseInt(u.DERRUBADAS || 0, 10);
            const ultimo = formatarDataHoraIso(u.ULTIMO) || '—';

            return `
                <tr>
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 32px; height: 32px; font-size: 0.8rem; background: linear-gradient(135deg, #0ea5e9, #6366f1);">
                                ${escapeHtml(login.charAt(0).toUpperCase())}
                            </div>
                            <div>
                                <strong class="text-main d-block">${escapeHtml(login)}</strong>
                                <small class="text-muted" style="font-size: 0.72rem;">Serial: ${escapeHtml(u.SERIAL || '—')}</small>
                            </div>
                        </div>
                    </td>
                    <td class="text-center fw-bold text-main">${pviews.toLocaleString('pt-BR')}</td>
                    <td class="text-center"><span class="badge bg-primary bg-opacity-10 text-primary">${logins}</span></td>
                    <td class="text-center"><span class="badge ${atritos > 0 ? 'bg-warning bg-opacity-10 text-warning' : 'bg-light text-muted'}">${atritos}</span></td>
                    <td class="text-center"><span class="badge ${derrubadas > 0 ? 'bg-danger bg-opacity-10 text-danger' : 'bg-light text-muted'}">${derrubadas}</span></td>
                    <td class="pe-3 text-end text-muted small"><i class="bi bi-clock me-1"></i>${ultimo}</td>
                </tr>
            `;
        }).join('');
    }

    function renderizarTabelaRotas(rotas, totalGeral) {
        const tbody = document.getElementById('dashTbodyRotas');
        if (!tbody) return;
        if (!rotas || rotas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Nenhuma rota registrada no período.</td></tr>';
            return;
        }

        const maxAcessos = Math.max(...rotas.map(r => parseInt(r.N || 0, 10)), 1);

        tbody.innerHTML = rotas.map(r => {
            const n = parseInt(r.N || 0, 10);
            const pct = Math.round((n / maxAcessos) * 100);

            return `
                <tr>
                    <td class="ps-3">
                        <span class="badge bg-secondary bg-opacity-10 text-secondary">${escapeHtml((r.APP || '').toUpperCase())}</span>
                    </td>
                    <td>
                        <code class="text-main fw-semibold" style="font-size: 0.85rem;">${escapeHtml(r.ROTA || '')}</code>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px; background: rgba(255,255,255,0.06);">
                                <div class="progress-bar bg-info" style="width: ${pct}%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="pe-3 text-end fw-bold text-main">${n.toLocaleString('pt-BR')}</td>
                </tr>
            `;
        }).join('');
    }

    function renderizarTabelaAtritos(atritos) {
        const tbody = document.getElementById('dashTbodyAtritos');
        if (!tbody) return;
        if (!atritos || atritos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-success"><i class="bi bi-check-circle me-1"></i>Nenhum atrito ou mensagem de bloqueio enfrentada pelo cliente! Operação fluida.</td></tr>';
            return;
        }

        tbody.innerHTML = atritos.map(a => {
            const n = parseInt(a.N || 1, 10);
            return `
                <tr>
                    <td class="ps-3" style="width: 25%;">
                        <span class="badge bg-warning bg-opacity-10 text-warning me-1">${escapeHtml((a.APP || '').toUpperCase())}</span>
                        <div class="small text-muted mt-1"><code>${escapeHtml(a.ROTA || '')}</code></div>
                    </td>
                    <td>
                        <div class="text-main fw-semibold">${escapeHtml(a.MENSAGEM || '')}</div>
                    </td>
                    <td class="pe-3 text-end">
                        <span class="badge bg-warning text-dark px-3 py-1 rounded-pill fw-bold">${n}x</span>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function copiarJsonDashboard() {
        const pre = document.getElementById('dashJsonPre');
        if (!pre) return;
        navigator.clipboard.writeText(pre.textContent).then(() => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Copiado!', text: 'JSON copiado para a área de transferência.', timer: 1500, showConfirmButton: false });
            } else {
                alert('JSON copiado!');
            }
        });
    }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
</script>

<style>
    @keyframes spin { 100% { transform: rotate(360deg); } }
    .spin-animation { animation: spin 1s linear infinite; }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Revelação progressiva com GSAP
        if (typeof gsap !== 'undefined') {
            gsap.to(".gsap-reveal", {
                opacity: 1,
                y: 0,
                duration: 0.6,
                stagger: 0.1,
                ease: "power2.out"
            });
        } else {
            document.querySelectorAll(".gsap-reveal").forEach(el => el.style.opacity = 1);
        }

        // Lógica de busca automática com delay (debounce)
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            // Colocar foco e mover cursor para o final
            const val = searchInput.value;
            searchInput.value = '';
            searchInput.focus();
            searchInput.value = val;

            let timeout = null;
            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    document.getElementById('searchForm').submit();
                }, 500); // 500ms de delay
            });
        }
    });

    function changeViewMode(mode) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', mode);
        window.location.href = url.toString();
    }

    function openEditModal(button) {
        const d = button.dataset;
        document.getElementById('modalTitle').innerText = 'Editar Cliente';
        const idClienteApi = (button.getAttribute('data-id-cliente-api') || d.idClienteApi || '').trim();

        document.getElementById('id_cliente').value = d.id;
        document.getElementById('fantasia').value = d.fantasia || '';
        document.getElementById('servidor').value = d.servidor || '';
        document.getElementById('vendedor').value = d.vendedor || '';
        document.getElementById('data_inicio').value = d.inicio || '';
        document.getElementById('id_data_fim').value = d.fim || '';
        document.getElementById('data_previsao_encerramento').value = d.previsaoEncerramento || '';
        document.getElementById('id_cliente_api').value = idClienteApi;
        document.getElementById('emitir_nf').value = d.nf || 'Não';
        document.getElementById('configurado').value = d.cfg || 'Não';
        document.getElementById('num_licencas').value = d.licencas || 0;
        document.getElementById('anexo').value = d.anexo || (idClienteApi ? `https://interno.gestaopro.srv.br/clientes/${idClienteApi}` : '');
        sincronizarIdClienteApi();
        document.getElementById('chamados').value = d.chamados || '';
        document.getElementById('link_videos').value = d.linkVideos || '';
        
        // Limpar e preencher checkbox de recursos
        document.querySelectorAll('.recurso-checkbox').forEach(cb => cb.checked = false);
        if (d.recursos) {
            const recursosArray = d.recursos.split(',').map(r => r.trim());
            document.querySelectorAll('.recurso-checkbox').forEach(cb => {
                if(recursosArray.includes(cb.value)) {
                    cb.checked = true;
                }
            });
        }
        
        toggleConfigurado(d.nf);
        
        const modal = new bootstrap.Modal(document.getElementById('modalCliente'));
        modal.show();
    }

    function sincronizarIdClienteApi() {
        const link = document.getElementById('anexo').value.trim();
        const campoId = document.getElementById('id_cliente_api');
        const linkComProtocolo = /^https?:\/\//i.test(link) ? link : `https://${link}`;

        try {
            const caminho = new URL(linkComProtocolo).pathname;
            const resultado = caminho.match(/\/(\d+)\/?$/);
            if (resultado) {
                campoId.value = resultado[1];
            }
        } catch (erro) {
            // Mantém o valor existente enquanto o link ainda está incompleto.
        }
    }

    document.getElementById('anexo').addEventListener('input', sincronizarIdClienteApi);
    document.getElementById('anexo').addEventListener('change', sincronizarIdClienteApi);

    function toggleConfigurado(valor) {
        const div = document.getElementById('div_configurado');
        if (div) div.style.display = (valor === 'Sim') ? 'block' : 'none';
    }

    function abrirAnexo() {
        const link = document.getElementById('anexo').value.trim();
        if (!link) {
            Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Nenhum link cadastrado.' });
            return;
        }
        window.open(link.startsWith('http') ? link : 'https://' + link, '_blank');
    }

    function gerarLinkChamados() {
        const linkAnexo = document.getElementById('anexo').value.trim();
        if (!linkAnexo) {
            Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Preencha o campo Link GestãoPRO primeiro.' });
            return;
        }
        const base = linkAnexo.startsWith('http') ? linkAnexo : 'https://' + linkAnexo;
        const linkChamados = base.replace(/\?.*$/, '') + '?tab=chamados-abertos';
        document.getElementById('chamados').value = linkChamados;
        Swal.fire({ icon: 'success', title: 'Gerado!', text: 'Link Chamados preenchido automaticamente.', timer: 2000, showConfirmButton: false });
    }

    function abrirChamados() {
        const link = document.getElementById('chamados').value.trim();
        if (!link) {
            Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Nenhum link de chamados cadastrado.' });
            return;
        }
        window.open(link.startsWith('http') ? link : 'https://' + link, '_blank');
    }

    // --- Modal Novo Treinamento Rápido ---
    function openNovoTreinamentoModal(btn) {
        const idCliente = btn.dataset.id;
        const nomeCliente = btn.dataset.fantasia;
        const contatos = JSON.parse(btn.dataset.contatos || '[]');

        document.getElementById('nt_id_cliente').value = idCliente;
        document.getElementById('nt_cliente_label').textContent = nomeCliente;
        document.getElementById('nt_cliente_display').value = nomeCliente;
        document.getElementById('nt_nome_contato').value = '';
        document.getElementById('nt_tema').selectedIndex = 0;
        document.getElementById('nt_status').value = 'PENDENTE';
        document.getElementById('nt_data_treinamento').value = '';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNovoTreinamento')).show();
    }
</script>

<?php include 'footer.php'; ?>
