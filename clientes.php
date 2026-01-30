<?php
require_once 'config.php';

// 1. Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
    $stmt->execute([$id]);
    header("Location: clientes.php?msg=Cliente removido com sucesso");
    exit;
}

// 2. Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fantasia = $_POST['fantasia'];
    $servidor = $_POST['servidor'];
    $vendedor = $_POST['vendedor'];
    $telefone = $_POST['telefone'];
    $data_inicio = $_POST['data_inicio'];
    $data_fim = (!empty($_POST['data_fim']) && $_POST['data_fim'] !== '0000-00-00') ? $_POST['data_fim'] : null;
    $observacao = $_POST['observacao'];

    if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
        $stmt = $pdo->prepare("UPDATE clientes SET fantasia=?, servidor=?, vendedor=?, telefone_ddd=?, data_inicio=?, data_fim=?, observacao=? WHERE id_cliente=?");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $data_inicio, $data_fim, $observacao, $_POST['id_cliente']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO clientes (fantasia, servidor, vendedor, telefone_ddd, data_inicio, data_fim, observacao) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $data_inicio, $data_fim, $observacao]);
    }
    header("Location: clientes.php?msg=Dados atualizados com sucesso");
    exit;
}

// 3. Consulta e Filtros
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$estagio = isset($_GET['estagio']) ? $_GET['estagio'] : '';

$sql = "SELECT * FROM clientes WHERE 1=1";
$params = [];
if (!empty($filtro)) {
    $sql .= " AND (fantasia LIKE ? OR vendedor LIKE ? OR servidor LIKE ?)";
    $params = ["%$filtro%", "%$filtro%", "%$filtro%"];
}

$stmt = $pdo->prepare($sql . " ORDER BY fantasia ASC");
$stmt->execute($params);
$todos_clientes = $stmt->fetchAll();

// 4. Lógica de Contagem e Estágios
$integracao = 0;
$operacional = 0;
$finalizacao = 0;
$critico = 0;
$clientes_filtrados = [];

foreach ($todos_clientes as $cl) {
    $status_cl = "concluido";
    if (empty($cl['data_fim']) || $cl['data_fim'] === '0000-00-00') {
        $d = (new DateTime($cl['data_inicio']))->diff(new DateTime())->days;
        if ($d <= 30) {
            $integracao++;
            $status_cl = "integracao";
        } elseif ($d <= 70) {
            $operacional++;
            $status_cl = "operacional";
        } elseif ($d <= 91) {
            $finalizacao++;
            $status_cl = "finalizacao";
        } else {
            $critico++;
            $status_cl = "critico";
        }
    }
    if (empty($estagio) || $estagio == $status_cl) {
        $clientes_filtrados[] = $cl;
    }
}

include 'header.php';
?>

<style>
    .card-stat {
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
        border: none !important;
    }

    .card-stat:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1) !important;
    }

    .card-stat.active {
        border-bottom: 4px solid #000 !important;
    }

    .progress {
        background-color: #f0f0f0;
        border-radius: 10px;
    }

    .table thead th {
        background-color: #f8f9fa;
        color: #6c757d;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        border-top: none;
    }

    .status-dot {
        height: 10px;
        width: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 5px;
    }

    .table-scroll-container {
        max-height: 65vh;
        /* Ocupa 65% da altura da janela, mantendo o layout responsivo */
        overflow-y: auto;
    }
</style>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h4 class="fw-bold text-dark mb-1">Painel de Implantação</h4>
            <p class="text-muted small">Acompanhamento do ciclo de 91 dias por cliente</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCliente">
                <i class="bi bi-plus-lg me-2"></i>Novo Cliente
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['id' => 'integracao', 'label' => 'Integração', 'val' => $integracao, 'color' => '#0dcaf0', 'days' => '0-30d'],
            ['id' => 'operacional', 'label' => 'Operacional', 'val' => $operacional, 'color' => '#0d6efd', 'days' => '31-70d'],
            ['id' => 'finalizacao', 'label' => 'Finalização', 'val' => $finalizacao, 'color' => '#ffc107', 'days' => '71-91d'],
            ['id' => 'critico', 'label' => 'Crítico', 'val' => $critico, 'color' => '#dc3545', 'days' => '> 91d']
        ];
        foreach ($cards as $card):
            $isActive = ($estagio == $card['id']);
        ?>
            <div class="col-md-3">
                <a href="?estagio=<?= $card['id'] ?>" class="text-decoration-none">
                    <div class="card card-stat shadow-sm h-100 <?= $isActive ? 'active' : '' ?>" style="border-left: 5px solid <?= $card['color'] ?> !important;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="text-muted small fw-bold text-uppercase"><?= $card['label'] ?></span>
                                    <h2 class="fw-bold my-1 text-dark"><?= $card['val'] ?></h2>
                                </div>
                                <span class="badge bg-light text-muted border"><?= $card['days'] ?></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-white py-3 border-0">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="estagio" value="<?= $estagio ?>">
                        <div class="input-group input-group-sm" style="max-width: 300px;">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                            <input type="text" name="filtro" class="form-control bg-light border-0" placeholder="Pesquisar cliente..." value="<?= htmlspecialchars($filtro) ?>">
                        </div>
                        <button type="submit" class="btn btn-dark btn-sm px-3">Filtrar</button>
                        <?php if ($estagio || $filtro): ?>
                            <a href="clientes.php" class="btn btn-outline-secondary btn-sm">Limpar</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="table-responsive table-scroll-container" style="max-height: 500px;">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Cliente/Servidor</th>
                        <th>Vendedor</th>
                        <th>Início</th>
                        <th>Dias/Status</th>
                        <th>Progresso da Jornada</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    <?php foreach ($clientes_filtrados as $c):
                        $isConcluido = (!empty($c['data_fim']) && $c['data_fim'] !== '0000-00-00');
                        $perc = 0;
                        $color = "bg-secondary";
                        $label_dias = "---";

                        if ($isConcluido) {
                            $perc = 100;
                            $color = "bg-success";
                            $label_dias = "Concluído";
                        } else {
                            $d = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;
                            $perc = min(round(($d / 91) * 100), 100);
                            $label_dias = $d . " dias";
                            if ($d <= 30) $color = "bg-info";
                            elseif ($d <= 70) $color = "bg-primary";
                            elseif ($d <= 91) $color = "bg-warning";
                            else $color = "bg-danger";
                        }
                    ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($c['fantasia']) ?></div>
                                <span class="text-muted">Servidor: <?= htmlspecialchars($c['servidor']) ?></small>
                            </td>
                            <td><span class="badge bg-light text-dark border fw-normal"><?= htmlspecialchars($c['vendedor']) ?></span></td>
                            <td class="text-muted small"><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></td>
                            <td>
                                <span class="small fw-bold <?= ($label_dias == 'Concluído') ? 'text-success' : 'text-dark' ?>">
                                    <span class="status-dot <?= $color ?>"></span><?= $label_dias ?>
                                </span>
                            </td>
                            <td style="min-width: 150px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar <?= $color ?>" style="width: <?= $perc ?>%"></div>
                                    </div>
                                    <span class="small text-muted" style="font-size: 0.7rem;"><?= $perc ?>%</span>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-light border edit-btn"
                                    data-id="<?= $c['id_cliente'] ?>"
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                    data-servidor="<?= htmlspecialchars($c['servidor']) ?>"
                                    data-vendedor="<?= htmlspecialchars($c['vendedor']) ?>"
                                    data-telefone="<?= htmlspecialchars($c['telefone_ddd']) ?>"
                                    data-data_inicio="<?= $c['data_inicio'] ?>"
                                    data-data_fim="<?= ($c['data_fim'] == '0000-00-00' ? '' : $c['data_fim']) ?>"
                                    data-obs="<?= htmlspecialchars($c['observacao']) ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalCliente">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-light border text-primary" title="Ver Treinamentos">
                                    <i class="bi bi-journal-check"></i>
                                </a>

                                
                                <a href="?delete=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <form method="POST">
                <div class="modal-header border-0">
                    <h5 class="fw-bold" id="modalTitle">Ficha do Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <input type="hidden" name="id_cliente" id="id_cliente">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">Nome Fantasia</label>
                            <input type="text" name="fantasia" id="fantasia" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Servidor</label>
                            <input type="text" name="servidor" id="servidor" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Vendedor</label>
                            <input type="text" name="vendedor" id="vendedor" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Data de Início</label>
                            <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Data de Conclusão</label>
                            <input type="date" name="data_fim" id="id_data_fim" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Guardar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Editar Registro';
            document.getElementById('id_cliente').value = this.dataset.id;
            document.getElementById('fantasia').value = this.dataset.fantasia;
            document.getElementById('servidor').value = this.dataset.servidor;
            document.getElementById('vendedor').value = this.dataset.vendedor;
            document.getElementById('data_inicio').value = this.dataset.data_inicio;
            document.getElementById('id_data_fim').value = this.dataset.data_fim || '';
        });
    });
</script>

<?php include 'footer.php'; ?>