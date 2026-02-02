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
    $telefone = $_POST['telefone'] ?? '';
    $data_inicio = $_POST['data_inicio'];
    $data_fim = (!empty($_POST['data_fim']) && $_POST['data_fim'] !== '0000-00-00') ? $_POST['data_fim'] : null;
    $observacao = $_POST['observacao'] ?? '';
    $emitir_nf = $_POST['emitir_nf'] ?? 'Não';
    $configurado = $_POST['configurado'] ?? 'Não';

    if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
        $stmt = $pdo->prepare("UPDATE clientes SET fantasia=?, servidor=?, vendedor=?, telefone_ddd=?, data_inicio=?, data_fim=?, observacao=?, emitir_nf=?, configurado=? WHERE id_cliente=?");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $data_inicio, $data_fim, $observacao, $emitir_nf, $configurado, $_POST['id_cliente']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO clientes (fantasia, servidor, vendedor, telefone_ddd, data_inicio, data_fim, observacao, emitir_nf, configurado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $data_inicio, $data_fim, $observacao, $emitir_nf, $configurado]);
    }
    header("Location: clientes.php?msg=Dados atualizados");
    exit;
}

// 3. Consulta de Dados e Filtros
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

// 4. Lógica de Contagem para os CARDS
$integracao = 0; $operacional = 0; $finalizacao = 0; $critico = 0;
$clientes_filtrados = [];

foreach ($todos_clientes as $cl) {
    $status_cl = "concluido";
    if (empty($cl['data_fim']) || $cl['data_fim'] === '0000-00-00') {
        $d = (new DateTime($cl['data_inicio']))->diff(new DateTime())->days;
        if ($d <= 30) { $integracao++; $status_cl = "integracao"; }
        elseif ($d <= 70) { $operacional++; $status_cl = "operacional"; }
        elseif ($d <= 91) { $finalizacao++; $status_cl = "finalizacao"; }
        else { $critico++; $status_cl = "critico"; }
    }
    if (empty($estagio) || $estagio == $status_cl) { $clientes_filtrados[] = $cl; }
}

include 'header.php';
?>

<style>
    .card-stat { transition: transform 0.2s; cursor: pointer; border: none !important; border-radius: 12px; }
    .card-stat:hover { transform: translateY(-5px); box-shadow: 0 10px 15px rgba(0,0,0,0.1); }
    .card-stat.active { border: 2px solid #000 !important; }
    
    /* Estilos para destaque visual no modal */
    .border-warning { border-color: #FFA500 !important; }
    .border-success { border-color: #C8E6C9 !important; }
    
    /* Estilos para as linhas da tabela */
    .row-orange { 
        background-color: #FFA500 !important; 
        color: #333 !important;
    }
    .row-orange:hover { 
        background-color: #FF9900 !important;
    }
    .row-green { 
        background-color: #C8E6C9 !important;
    }
    .row-green:hover { 
        background-color: #A5D6A7 !important;
    }
</style>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">Painel de Implantação</h4>
        <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCliente">
            <i class="bi bi-plus-lg me-2"></i>Novo Cliente
        </button>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $status_data = [
            ['id' => 'integracao', 'label' => 'Integração', 'count' => $integracao, 'color' => '#0dcaf0', 'days' => '0-30d'],
            ['id' => 'operacional', 'label' => 'Operacional', 'count' => $operacional, 'color' => '#0d6efd', 'days' => '31-70d'],
            ['id' => 'finalizacao', 'label' => 'Finalização', 'count' => $finalizacao, 'color' => '#ffc107', 'days' => '71-91d'],
            ['id' => 'critico', 'label' => 'Crítico', 'count' => $critico, 'color' => '#dc3545', 'days' => '> 91d']
        ];
        foreach ($status_data as $s): ?>
            <div class="col-md-3">
                <a href="?estagio=<?= $s['id'] ?>" class="text-decoration-none">
                    <div class="card card-stat shadow-sm <?= ($estagio == $s['id']) ? 'active' : '' ?>" style="border-left: 5px solid <?= $s['color'] ?> !important;">
                        <div class="card-body">
                            <span class="text-muted small fw-bold text-uppercase"><?= $s['label'] ?></span>
                            <div class="d-flex justify-content-between align-items-center">
                                <h2 class="fw-bold mb-0"><?= $s['count'] ?></h2>
                                <span class="badge bg-light text-muted border"><?= $s['days'] ?></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="table-responsive" style="max-height: 65vh;">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Cliente / Servidor</th>
                        <th>Vendedor</th>
                        <th>Início</th>
                        <th>Dias</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes_filtrados as $c): 
                        // Obter valores do banco
                        $emitir_nf = isset($c['emitir_nf']) ? $c['emitir_nf'] : 'Não';
                        $configurado = isset($c['configurado']) ? $c['configurado'] : 'Não';
                        
                        // Normalizar para comparação
                        $emitir_clean = strtolower(trim($emitir_nf));
                        $config_clean = strtolower(trim($configurado));
                        
                        // Converter 'não' com acento para 'nao' sem acento
                        $config_clean = str_replace('não', 'nao', $config_clean);
                        
                        // Determinar classe CSS baseada nas condições
                        $row_class = '';
                        
                        if ($emitir_clean === 'sim') {
                            if ($config_clean === 'nao') {
                                $row_class = 'row-orange';
                            } elseif ($config_clean === 'sim') {
                                $row_class = 'row-green';
                            }
                        }
                        
                        $d = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;
                    ?>
                        <tr class="<?= $row_class ?>">
                            <td class="ps-4">
                                <div class="fw-bold"><?= htmlspecialchars($c['fantasia']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($c['servidor']) ?></small>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($c['vendedor']) ?></span></td>
                            <td><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></td>
                            <td><span class="fw-bold"><?= $d ?> dias</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-light border edit-btn"
                                    data-id="<?= $c['id_cliente'] ?>"
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                    data-servidor="<?= htmlspecialchars($c['servidor']) ?>"
                                    data-vendedor="<?= htmlspecialchars($c['vendedor']) ?>"
                                    data-data_inicio="<?= $c['data_inicio'] ?>"
                                    data-data_fim="<?= $c['data_fim'] ?>"
                                    data-emitir_nf="<?= htmlspecialchars($c['emitir_nf']) ?>"
                                    data-configurado="<?= htmlspecialchars($c['configurado']) ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalCliente">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                
                                <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-light border text-primary" title="Ver Treinamentos">
                                    <i class="bi bi-journal-check"></i>
                                </a>

                                <a href="?delete=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir este cliente?')">
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
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold" id="modalTitle">Ficha do Cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_cliente" id="id_cliente">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">Nome Fantasia</label>
                        <input type="text" name="fantasia" id="fantasia" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Servidor</label>
                        <input type="text" name="servidor" id="servidor" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Vendedor</label>
                        <input type="text" name="vendedor" id="vendedor" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Data Início</label>
                        <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Data Conclusão</label>
                        <input type="date" name="data_fim" id="id_data_fim" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold" id="label_emitir_nf">Emitir nota fiscal</label>
                        <select name="emitir_nf" id="emitir_nf" class="form-select" onchange="toggleConfigurado(this.value)">
                            <option value="Não">Não</option>
                            <option value="Sim">Sim</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="div_configurado" style="display: none;">
                        <label class="form-label small fw-bold text-muted" id="label_configurado">Configurado</label>
                        <select name="configurado" id="configurado" class="form-select">
                            <option value="Não">Não</option>
                            <option value="Sim">Sim</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleConfigurado(valor) {
        const div = document.getElementById('div_configurado');
        div.style.display = (valor === 'Sim') ? 'block' : 'none';
    }
    
    function updateModalVisual() {
        const emitirNf = document.getElementById('emitir_nf').value;
        const configurado = document.getElementById('configurado').value;
        const emitirSelect = document.getElementById('emitir_nf');
        const configSelect = document.getElementById('configurado');
        const configLabel = document.getElementById('label_configurado');
        const emitirLabel = document.getElementById('label_emitir_nf');
        
        emitirSelect.classList.remove('border-warning', 'border-success', 'border-2');
        configSelect.classList.remove('border-warning', 'border-success', 'border-2');
        emitirLabel.classList.remove('text-warning', 'text-success', 'fw-bold');
        configLabel.classList.remove('text-warning', 'text-success', 'fw-bold', 'text-muted');
        
        if (emitirNf === 'Sim') {
            emitirSelect.classList.add('border-2');
            emitirLabel.classList.add('fw-bold');
            
            if (configurado === 'Não') {
                emitirSelect.classList.add('border-warning');
                configSelect.classList.add('border-warning', 'border-2');
                emitirLabel.classList.add('text-warning');
                configLabel.classList.add('text-warning', 'fw-bold');
            } else if (configurado === 'Sim') {
                emitirSelect.classList.add('border-success');
                configSelect.classList.add('border-success', 'border-2');
                emitirLabel.classList.add('text-success');
                configLabel.classList.add('text-success', 'fw-bold');
            }
        } else {
            configLabel.classList.add('text-muted');
        }
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Editar Registro';
            document.getElementById('id_cliente').value = this.dataset.id;
            document.getElementById('fantasia').value = this.dataset.fantasia;
            document.getElementById('servidor').value = this.dataset.servidor;
            document.getElementById('vendedor').value = this.dataset.vendedor;
            document.getElementById('data_inicio').value = this.dataset.data_inicio;
            document.getElementById('id_data_fim').value = this.dataset.data_fim || '';
            
            const nf = this.dataset.emitir_nf || 'Não';
            const conf = this.dataset.configurado || 'Não';
            
            document.getElementById('emitir_nf').value = nf;
            document.getElementById('configurado').value = conf;
            
            toggleConfigurado(nf);
            updateModalVisual();
        });
    });

    document.getElementById('emitir_nf').addEventListener('change', function() {
        toggleConfigurado(this.value);
        updateModalVisual();
    });
    
    document.getElementById('configurado').addEventListener('change', updateModalVisual);

    document.getElementById('modalCliente').addEventListener('hidden.bs.modal', function() {
        this.querySelector('form').reset();
        document.getElementById('id_cliente').value = '';
        document.getElementById('modalTitle').innerText = 'Ficha do Cliente';
        
        document.getElementById('emitir_nf').value = 'Não';
        document.getElementById('configurado').value = 'Não';
        
        const emitirSelect = document.getElementById('emitir_nf');
        const configSelect = document.getElementById('configurado');
        const configLabel = document.getElementById('label_configurado');
        const emitirLabel = document.getElementById('label_emitir_nf');
        
        emitirSelect.classList.remove('border-warning', 'border-success', 'border-2');
        configSelect.classList.remove('border-warning', 'border-success', 'border-2');
        emitirLabel.classList.remove('text-warning', 'text-success', 'fw-bold');
        configLabel.classList.remove('text-warning', 'text-success', 'fw-bold');
        configLabel.classList.add('text-muted');
        
        toggleConfigurado('Não');
    });
    
    document.getElementById('modalCliente').addEventListener('show.bs.modal', function() {
        setTimeout(updateModalVisual, 100);
    });
</script>

<?php include 'footer.php'; ?>