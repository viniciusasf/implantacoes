<?php
require_once 'config.php';

// 1. LÃ“GICA DE PROCESSAMENTO: Encerrar treinamento com ObservaÃ§Ã£o
// Deve vir antes de qualquer saÃ­da HTML
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_encerramento'])) {
    $id = $_POST['id_treinamento'];
    $obs = $_POST['observacoes'];
    $data_hoje = date('Y-m-d H:i:s');
    
    // Esta query requer que a coluna 'observacoes' exista na tabela 'treinamentos'
    $stmt = $pdo->prepare("UPDATE treinamentos SET status = 'Resolvido', data_treinamento_encerrado = ?, observacoes = ? WHERE id_treinamento = ?");
    $stmt->execute([$data_hoje, $obs, $id]);
    
    header("Location: index.php?msg=Treinamento encerrado com sucesso");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_link_google'])) {
    $id = $_POST['id_treinamento'] ?? null;
    $google_event_link = trim((string)($_POST['google_event_link'] ?? ''));

    if (!empty($id)) {
        $stmt = $pdo->prepare("UPDATE treinamentos SET google_event_link = ? WHERE id_treinamento = ?");
        $stmt->execute([$google_event_link !== '' ? $google_event_link : null, $id]);
    }

    header("Location: relatorio.php?msg=" . urlencode("Link do Google Agenda salvo com sucesso"));
    exit;
}

// Consulta para clientes sem interaÃ§Ã£o hÃ¡ mais de 3 dias
$sql_inatividade = "
    SELECT c.id_cliente, c.fantasia, MAX(t.data_treinamento) as Ãºltima_data, c.data_inicio
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente, c.data_inicio
    HAVING 
        (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) OR 
        (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))
    ORDER BY Ãºltima_data ASC";

$clientes_inativos = $pdo->query($sql_inatividade)->fetchAll();

include 'header.php';

// 2. Buscar estatÃ­sticas para os cards
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00')")->fetchColumn();
$total_treinamentos = $pdo->query("SELECT COUNT(*) FROM treinamentos")->fetchColumn();
$treinamentos_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$treinamentos_resolvidos = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'Resolvido'")->fetchColumn();

// 3. Consulta de treinamentos pendentes
$sql = "SELECT t.*, c.fantasia as cliente_nome, c.servidor, co.nome as contato_nome, co.telefone_ddd as contato_telefone, c.telefone_ddd as cliente_telefone
        FROM treinamentos t
        JOIN clientes c ON t.id_cliente = c.id_cliente
        LEFT JOIN contatos co ON t.id_contato = co.id_contato
        WHERE t.status = 'PENDENTE'
        ORDER BY t.data_treinamento ASC 
        LIMIT 10";

$proximos_atendimentos = $pdo->query($sql)->fetchAll();
$hoje_data = date('Y-m-d');
?>

<style>
    .totalizador-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease !important;
        cursor: pointer;
    }

    .totalizador-card:hover {
        transform: translateY(-5px) !important;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12) !important;
    }
</style>

<div class="mb-4">
    <h2 class="fw-bold">Agendamentos</h2>
    <p class="text-muted">GestÃ£o de Agendamentos.</p>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-primary border-4">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-building text-primary fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">CLIENTES</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $total_clientes; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-info border-4">
            <div class="d-flex align-items-center">
                <div class="bg-info bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-mortarboard text-info fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">TREINAMENTOS</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $total_treinamentos; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-warning border-4">
            <div class="d-flex align-items-center">
                <div class="bg-warning bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-clock-history text-warning fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">PENDENTES</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $treinamentos_pendentes; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-success border-4">
            <div class="d-flex align-items-center">
                <div class="bg-success bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-check2-circle text-success fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">RESOLVIDOS</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $treinamentos_resolvidos; ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <h5 class="mb-0 fw-bold text-dark">Próximos Atendimentos (Pendentes)</h5>
                <a href="treinamentos.php" class="btn btn-sm btn-light text-primary fw-bold">Ver todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Data Agendada</th>
                                <th>Cliente</th>
                                <th>Servidor</th>
                                <th>Contato</th>
                                <th>Tema</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($proximos_atendimentos)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">Nenhum treinamento pendente.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($proximos_atendimentos as $t): 
                                    $data_treino = date('Y-m-d', strtotime($t['data_treinamento']));
                                    $e_hoje = ($data_treino == $hoje_data);
                                    $bg_class = $e_hoje ? 'table-info' : '';
                                    $nome_contato = trim((string)($t['contato_nome'] ?? ''));
                                    $telefone_contato = trim((string)($t['contato_telefone'] ?? ''));
                                    $telefone_cliente = trim((string)($t['cliente_telefone'] ?? ''));
                                    $telefone_exibicao = $telefone_contato !== '' ? $telefone_contato : $telefone_cliente;
                                    $telefone_whatsapp = preg_replace('/\D+/', '', $telefone_exibicao);
                                    if ($telefone_whatsapp !== '') {
                                        if (strpos($telefone_whatsapp, '55') !== 0 && (strlen($telefone_whatsapp) === 10 || strlen($telefone_whatsapp) === 11)) {
                                            $telefone_whatsapp = '55' . $telefone_whatsapp;
                                        }
                                        if (strlen($telefone_whatsapp) < 12 || strlen($telefone_whatsapp) > 13) {
                                            $telefone_whatsapp = '';
                                        }
                                    }
                                    $google_meet_link = trim((string)($t['google_event_link'] ?? ''));
                                    $nome_whatsapp = $nome_contato !== '' ? $nome_contato : $t['cliente_nome'];
                                    $data_treinamento_formatada = date('d/m/Y', strtotime($t['data_treinamento']));
                                    $horario_treinamento_formatado = date('H:i', strtotime($t['data_treinamento']));
                                    $mensagem_whatsapp = implode("\n", [
                                        "Olá, " . $nome_whatsapp . "!",
                                        "",
                                        "Treinamento Agendado com Sucesso!",
                                        "*Data: " . $data_treinamento_formatada . "*",
                                        "*Horário: " . $horario_treinamento_formatado . "*",
                                        "Tema: " . $t['tema'],
                                        "Link Google Meet: " . ($google_meet_link !== '' ? $google_meet_link : 'não informado'),
                                        "",
                                        "Caso precise *alterar a Data/Horário* ou tenha alguma dúvida, me envie uma mensagem.",
                                        "",
                                        "Agradeço e nos vemos em breve!"
                                    ]);
                                    if (!preg_match('//u', $mensagem_whatsapp)) {
                                        if (function_exists('mb_convert_encoding')) {
                                            $mensagem_whatsapp = mb_convert_encoding($mensagem_whatsapp, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
                                        } elseif (function_exists('iconv')) {
                                            $convertida = @iconv('Windows-1252', 'UTF-8//IGNORE', $mensagem_whatsapp);
                                            if ($convertida !== false) {
                                                $mensagem_whatsapp = $convertida;
                                            }
                                        }
                                    }
                                    $mensagem_whatsapp_attr = htmlspecialchars($mensagem_whatsapp, ENT_QUOTES, 'UTF-8');
                                    if ($nome_contato !== '' && $telefone_exibicao !== '') {
                                        $contato_exibicao = $nome_contato . ' - ' . $telefone_exibicao;
                                    } elseif ($nome_contato !== '') {
                                        $contato_exibicao = $nome_contato;
                                    } elseif ($telefone_exibicao !== '') {
                                        $contato_exibicao = $telefone_exibicao;
                                    } else {
                                        $contato_exibicao = '---';
                                    }
                                ?>
                                <tr class="<?= $bg_class ?>">
                                    <td class="ps-4">
                                        <div class="small fw-bold">
                                            <?= date('d/m/Y H:i', strtotime($t['data_treinamento'])) ?>
                                            <?php if($e_hoje): ?>
                                                <span class="badge bg-primary text-white ms-1">HOJE</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($t['cliente_nome']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['servidor'] ?? '---') ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($contato_exibicao) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['tema']) ?></span></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border">
                                            <?= $t['status'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button"
                                           class="btn btn-sm btn-outline-success me-1 copy-whatsapp-message"
                                           data-message="<?= $mensagem_whatsapp_attr ?>"
                                           title="Copiar mensagem para WhatsApp">
                                            <i class="bi bi-whatsapp"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary me-1 open-google-link-modal"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                data-google-link="<?= htmlspecialchars((string)($t['google_event_link'] ?? '')) ?>"
                                                title="Gerenciar link Google Meet">
                                            <i class="bi bi-calendar-check"></i>
                                        </button>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-success open-finish-modal"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                data-tema="<?= htmlspecialchars($t['tema']) ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEncerrar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-journal-check me-2 text-success"></i>Finalizar Atendimento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="modal_id_treinamento">
                <input type="hidden" name="confirmar_encerramento" value="1">
                
                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Informações:</div>
                    <div class="fw-bold text-primary" id="modal_cliente_info"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">O que ficou acordado com o cliente?</label>
                    <textarea name="observacoes" class="form-control" rows="4" placeholder="Descreva os detalhes da sessÃ£o..." required></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Encerrar e Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalGoogleLink" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-calendar-event me-2 text-primary"></i>Link Google Meet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="google_modal_id_treinamento">
                <input type="hidden" name="salvar_link_google" value="1">

                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Treinamento:</div>
                    <div class="fw-bold text-primary" id="google_modal_cliente_info"></div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Link Google Meet</label>
                    <input type="url"
                           name="google_event_link"
                           id="google_event_link_relatorio"
                           class="form-control"
                           placeholder="https://meet.google.com/...">
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="gerarLinkCurtoRelatorio()">
                        <i class="bi bi-magic me-1"></i>Gerar link curto
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="colarLinkCurtoRelatorio()">
                        <i class="bi bi-clipboard-check me-1"></i>Colar
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copiarLinkRelatorio()">
                        <i class="bi bi-clipboard me-1"></i>Copiar
                    </button>
                </div>
                <div class="form-text mt-2">
                    Informe o link do Google Meet que será enviado no WhatsApp.
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Link</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.open-finish-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const cliente = this.dataset.cliente;
            const tema = this.dataset.tema;
            
            document.getElementById('modal_id_treinamento').value = id;
            document.getElementById('modal_cliente_info').innerText = cliente + " | " + tema;
            
            const myModal = new bootstrap.Modal(document.getElementById('modalEncerrar'));
            myModal.show();
        });
    });

    document.querySelectorAll('.open-google-link-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('google_modal_id_treinamento').value = this.dataset.id;
            document.getElementById('google_modal_cliente_info').innerText = this.dataset.cliente;
            document.getElementById('google_event_link_relatorio').value = this.dataset.googleLink || '';
            new bootstrap.Modal(document.getElementById('modalGoogleLink')).show();
        });
    });

    document.querySelectorAll('.copy-whatsapp-message').forEach(btn => {
        btn.addEventListener('click', async function() {
            const mensagem = this.dataset.message || '';

            if (!mensagem.trim()) {
                alert('Não há mensagem para copiar.');
                return;
            }

            try {
                await navigator.clipboard.writeText(mensagem);
                alert('Mensagem copiada. Agora cole no WhatsApp do cliente.');
            } catch (error) {
                alert('Não foi possível copiar automaticamente. Copie manualmente.');
            }
        });
    });

    function gerarLinkCurtoRelatorio() {
        const input = document.getElementById('google_event_link_relatorio');
        const link = input ? input.value.trim() : '';

        if (!link) {
            alert('Primeiro informe ou sincronize o link do evento Google.');
            return;
        }

        window.open(link, '_blank', 'noopener');

        if (!link.includes('calendar.app.google/')) {
            alert('No Google Agenda, use "Convidar por link", copie o link curto e depois clique em "Colar".');
        }
    }

    async function colarLinkCurtoRelatorio() {
        const input = document.getElementById('google_event_link_relatorio');
        if (!input) return;

        try {
            const texto = (await navigator.clipboard.readText()).trim();
            if (!texto) {
                alert('A Ã¡rea de transferÃªncia estÃ¡ vazia.');
                return;
            }
            if (!texto.startsWith('http://') && !texto.startsWith('https://')) {
                alert('O conteÃºdo copiado nÃ£o parece um link vÃ¡lido.');
                return;
            }
            input.value = texto;
        } catch (error) {
            alert('NÃ£o foi possÃ­vel ler a Ã¡rea de transferÃªncia. Cole manualmente no campo.');
        }
    }

    function copiarLinkRelatorio() {
        const input = document.getElementById('google_event_link_relatorio');
        const link = input ? input.value.trim() : '';

        if (!link) {
            alert('NÃ£o hÃ¡ link preenchido para copiar.');
            return;
        }

        navigator.clipboard.writeText(link)
            .then(() => alert('Link copiado com sucesso.'))
            .catch(() => alert('NÃ£o foi possÃ­vel copiar automaticamente. Copie manualmente.'));
    }
</script>

<?php include 'footer.php'; ?>
