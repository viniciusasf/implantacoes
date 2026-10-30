<?php 
require_once 'config.php';
require_once 'header.php'; 

// Buscar mapeamento de clientes
$stmt_map = $pdo->query("SELECT id_cliente, id_cliente_api, chamados, anexo FROM clientes WHERE id_cliente_api IS NOT NULL AND id_cliente_api != ''");
$mapa_clientes_local = [];
$mapa_links_chamados = [];
while ($row_map = $stmt_map->fetch(PDO::FETCH_ASSOC)) {
    $mapa_clientes_local[$row_map['id_cliente_api']] = $row_map['id_cliente'];
    
    $link = '';
    if (!empty($row_map['chamados'])) {
        $link = $row_map['chamados'];
    } elseif (!empty($row_map['anexo'])) {
        $base = (strpos($row_map['anexo'], 'http') === 0) ? $row_map['anexo'] : 'https://' . $row_map['anexo'];
        $link = rtrim($base, '?') . '?tab=chamados-abertos';
    }
    if ($link !== '') {
        $mapa_links_chamados[$row_map['id_cliente_api']] = $link;
    }
}

$stmt_retornos = $pdo->query("SELECT id_chamado FROM chamados_retornos");
$chamados_retornos_local = [];
while ($row_retorno = $stmt_retornos->fetch(PDO::FETCH_ASSOC)) {
    $chamados_retornos_local[] = (int)$row_retorno['id_chamado'];
}
?>

<div class="container-fluid px-0">

<!-- PAGE HEADER -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="mb-1" style="font-size:1.6rem;">
            <i class="bi bi-cloud-arrow-down-fill me-2" style="color:var(--primary)"></i>
            Monitoramento GestãoPro
        </h1>
        <p class="mb-0" style="color:var(--text-muted);font-size:.85rem;" id="subtitulo-monitoramento">
            Dados em tempo real da API <strong>interno.gestaopro.srv.br/api/mesa?usuario=53&secoes=clientes</strong>
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <div class="btn-group btn-group-sm p-1 rounded-3" style="background:var(--bg-body); border:1px solid var(--border-color);" role="group" id="btn-group-fonte">
            <button type="button" class="btn btn-sm btn-primary fw-bold" id="btn-fonte-mesa" data-fonte="mesa" title="Carregar clientes da Mesa (Vinicius #53)">
                <i class="bi bi-kanban me-1"></i> Mesa (#53)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="btn-fonte-geral" data-fonte="implantacoes" title="Carregar todos os clientes da API Geral de Implantações">
                <i class="bi bi-globe me-1"></i> Geral (/api/implantacoes)
            </button>
        </div>
        <span id="badge-origem" class="badge rounded-pill" style="font-size:.75rem;background:var(--primary-light);color:var(--primary)">—</span>
        <span id="badge-atualizacao" style="font-size:.75rem;color:var(--text-muted)"></span>
        <button id="btn-refresh" class="btn btn-primary btn-sm">
            <i class="bi bi-arrow-clockwise" id="ico-refresh"></i> Atualizar
        </button>
    </div>
</div>

<!-- KPI CARDS -->
<div class="row g-3 mb-4" id="kpi-row">
    <?php
    $kpis = [
        ['id'=>'kpi-andamento','label'=>'Em Andamento',   'icon'=>'bi-play-circle-fill',  'color'=>'info'],
        ['id'=>'kpi-clienteok','label'=>'Cliente OK',     'icon'=>'bi-check-circle',      'color'=>'primary'],
        ['id'=>'kpi-aguardando','label'=>'Aguard. Cliente','icon'=>'bi-hourglass-split',  'color'=>'warning'],
        ['id'=>'kpi-encerrado','label'=>'Encerrados',     'icon'=>'bi-check-circle-fill', 'color'=>'success'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div style="width:44px;height:44px;border-radius:12px;background:var(--<?=$k['color']?>-light);display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?=$k['icon']?>" style="font-size:1.3rem;color:var(--<?=$k['color']?>)"></i>
                </div>
                <div>
                    <div id="<?=$k['id']?>" style="font-size:1.6rem;font-weight:800;color:var(--text-dark);line-height:1">—</div>
                    <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em"><?=$k['label']?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- FILTROS -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" id="filtro-busca" class="form-control form-control-sm" placeholder="🔍  Buscar cliente, vendedor, serial...">
            </div>
            <div class="col-md-2">
                <div class="dropdown">
                    <button class="form-select form-select-sm dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" id="filtro-status-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="filtro-status-label" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Todos os status</span>
                    </button>
                    <ul class="dropdown-menu w-100 shadow-sm p-2" aria-labelledby="filtro-status-btn" style="border-radius: var(--radius-md); border-color: var(--border-color); font-size: 0.9rem;" id="filtro-status-menu">
                        <li>
                            <div class="form-check mb-1">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="ANDAMENTO" id="chk-status-andamento" checked>
                                <label class="form-check-label w-100" for="chk-status-andamento" style="cursor:pointer;">Em Andamento</label>
                            </div>
                        </li>
                        <li>
                            <div class="form-check mb-1">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="CLIENTE.OK" id="chk-status-cliente-ok" checked>
                                <label class="form-check-label w-100" for="chk-status-cliente-ok" style="cursor:pointer;">Cliente OK</label>
                            </div>
                        </li>
                        <li>
                            <div class="form-check mb-1">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="RISCO CANC" id="chk-status-risco-canc" checked>
                                <label class="form-check-label w-100" for="chk-status-risco-canc" style="cursor:pointer;">Risco Cancelamento</label>
                            </div>
                        </li>
                        <li>
                            <div class="form-check mb-1">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="AGUARD.CLI" id="chk-status-aguardando" checked>
                                <label class="form-check-label w-100" for="chk-status-aguardando" style="cursor:pointer;">Aguard. Cliente</label>
                            </div>
                        </li>
                        <li>
                            <div class="form-check mb-1">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="OUTROS" id="chk-status-outros" checked>
                                <label class="form-check-label w-100" for="chk-status-outros" style="cursor:pointer;">Outros / Sem Status</label>
                            </div>
                        </li>
                        <li>
                            <div class="form-check mb-0">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="ENCERRADA" id="chk-status-encerrada">
                                <label class="form-check-label w-100" for="chk-status-encerrada" style="cursor:pointer;">Encerrada</label>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-md-2">
                <select id="filtro-vendedor" class="form-select form-select-sm">
                    <option value="">Todos os vendedores</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="filtro-nuvem" class="form-select form-select-sm">
                    <option value="">Nuvem / Local</option>
                    <option value="T">Nuvem</option>
                    <option value="F">Local</option>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <span id="lbl-contagem" style="font-size:.8rem;color:var(--text-muted)">—</span>
            </div>
        </div>
    </div>
</div>

<!-- TABELA -->
<div class="card">
    <div class="card-body p-0">
        <div id="estado-carregando" class="text-center py-5">
            <div class="spinner-border" style="color:var(--primary)" role="status"></div>
            <p class="mt-3 mb-0" style="color:var(--text-muted)">Buscando dados da API...</p>
        </div>
        <div id="estado-erro" class="text-center py-5 d-none">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:2.5rem;color:var(--danger)"></i>
            <p class="mt-3 mb-1 fw-bold">Não foi possível carregar os dados</p>
            <p id="msg-erro" style="color:var(--text-muted);font-size:.85rem"></p>
        </div>
        <div id="wrapper-tabela" class="d-none">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabela-gp">
                    <thead>
                        <tr style="background:var(--bg-body);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">
                            <th class="px-4 py-3 sortable" data-col="FANTASIA">Cliente <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="STATUS_IMPLANTACAO">Status <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="SERIAL">Serial <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="VENDEDOR">Consultor <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="SERVIDOR">Servidor <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="NUM_LICENCAS" style="text-align:center">Licenças <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3" style="text-align:center">Chamados</th>
                            <th class="py-3" style="text-align:center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-gp"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div><!-- /container-fluid -->

<style>
.sortable { cursor:pointer; user-select:none; }
.sortable:hover { color:var(--primary) !important; }
.badge-status { font-size:.68rem; font-weight:700; padding:4px 10px; border-radius:20px; letter-spacing:.04em; }
.badge-andamento  { background:var(--info-light);    color:var(--info);    }
.badge-aguardando { background:var(--warning-light); color:var(--warning); }
.badge-clienteok  { background:var(--success-light); color:var(--success); }
.badge-encerrada  { background:var(--success-light); color:var(--success); }
.badge-outros     { background:var(--primary-light); color:var(--primary); }
.ddu-chip { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:8px; font-weight:700; font-size:.8rem; }
.ddu-neg  { background:var(--danger-light);  color:var(--danger);  }
.ddu-zero { background:var(--warning-light); color:var(--warning); }
.ddu-pos  { background:var(--success-light); color:var(--success); }
#tabela-gp tbody tr { transition:background .15s; }
#tabela-gp tbody tr td:first-child { padding-left:1.5rem; }

.btn-agendar-gp {
    background: rgba(124,58,237,0.12);
    color: #7c3aed;
    border: 1px solid rgba(124,58,237,0.3);
    font-size: .72rem;
    padding: 3px 8px;
    border-radius: 7px;
    white-space: nowrap;
    font-weight: 600;
    transition: all 0.2s;
}
.btn-agendar-gp:hover {
    background: #7c3aed;
    color: #fff;
}
[data-theme="dark"] .btn-agendar-gp {
    background: rgba(167, 139, 250, 0.15);
    color: #c4b5fd;
    border-color: rgba(167, 139, 250, 0.3);
}
[data-theme="dark"] .btn-agendar-gp:hover {
    background: #8b5cf6;
    color: #fff;
    border-color: #8b5cf6;
}
</style>

<script>
const MAPA_CLIENTES_LOCAL = <?php echo json_encode($mapa_clientes_local); ?>;
const MAPA_LINKS_CHAMADOS = <?php echo json_encode($mapa_links_chamados); ?>;
const CHAMADOS_BAIXADOS = <?php echo json_encode($chamados_retornos_local); ?>;
const CHAMADOS_STATUS_WHATSAPP = ['Aguardando Desenvolvimento','Aguardando Autorização','Aguardando Fila','Aguardando Cliente','Aguardando Testes','Resolvido','Encerrado'];
const CHAMADOS_STATUS_FECHADOS = ['Resolvido','Encerrado','Cancelado'];

(function(){
    let todosRegistros = [];
    let sortCol = 'FANTASIA', sortAsc = true;

    // ── helpers ────────────────────────────────────────────────────────────────
    function fmtData(iso){
        if (!iso) return '<span style="color:var(--text-muted)">—</span>';
        const d = new Date(iso);
        return d.toLocaleDateString('pt-BR');
    }

    function fmtDataTexto(iso){
        if (!iso) return 'Sem previsão de retorno';
        return new Date(iso).toLocaleDateString('pt-BR');
    }
    function escapeHtml(text){
        return String(text||'')
            .replace(/&/g,'&amp;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#39;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;');
    }
    function escapeHtmlAttribute(text){
        return String(text||'')
            .replace(/&/g,'&amp;')
            .replace(/"/g,'&quot;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/\r?\n/g,'&#13;&#10;');
    }

    function copiarTextoAreaTransferencia(texto, mensagemSucesso){
        if(navigator.clipboard && window.isSecureContext){
            navigator.clipboard.writeText(texto).then(()=>alert(mensagemSucesso)).catch(()=>fallbackCopy(texto, mensagemSucesso));
        } else {
            fallbackCopy(texto, mensagemSucesso);
        }
    }

    function fallbackCopy(texto, mensagemSucesso){
        const textarea = document.createElement('textarea');
        textarea.value = texto;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
            alert(mensagemSucesso);
        } catch (e) {
            alert('Erro ao copiar mensagem para o WhatsApp.');
        }
        document.body.removeChild(textarea);
    }

    function badgeStatus(s){
        if (!s || s === 'OUTROS') return '<span class="badge-status badge-outros">—</span>';
        const mapa = {
            'ANDAMENTO':'badge-andamento',
            'AGUARD.CLI':'badge-aguardando',
            'CLIENTE.OK':'badge-clienteok',
            'ENCERRADA':'badge-encerrada',
            'RISCO CANC':'badge-aguardando',
        };
        const cls = mapa[s] || 'badge-outros';
        let label = s.charAt(0)+s.slice(1).toLowerCase();
        if (s === 'AGUARD.CLI') label = 'Aguard. Cliente';
        if (s === 'CLIENTE.OK') label = 'Cliente OK';
        if (s === 'RISCO CANC') label = 'Risco Canc';
        return `<span class="badge-status ${cls}">${label}</span>`;
    }

    function chipDDU(v){
        const cls = v < 0 ? 'ddu-neg' : (v === 0 ? 'ddu-zero' : 'ddu-pos');
        return `<span class="ddu-chip ${cls}">${v}</span>`;
    }

    function iconeNuvem(v){
        if (v === 'T') return '<i class="bi bi-cloud-fill" style="color:var(--info)" title="Nuvem"></i>';
        if (v === 'F') return '<i class="bi bi-hdd-fill" style="color:var(--text-muted)" title="Local"></i>';
        return '<span style="color:var(--text-muted)">—</span>';
    }

    function obterNumeroLicencas(cliente){
        if ('NUMLIC' in cliente || 'NUMLIC_GPT' in cliente) {
            const numLic = Number(cliente.NUMLIC ?? 0);
            const numLicGpt = Number(cliente.NUMLIC_GPT ?? 0);
            return (Number.isFinite(numLic) ? numLic : 0) + (Number.isFinite(numLicGpt) ? numLicGpt : 0);
        }

        const campos = ['NUMLIC_GPT', 'NUMLIC', 'NUM_LICENCAS', 'LICENCAS', 'NUM_LICENCIAS'];
        let valorExplicito = null;
        for (const campo of campos) {
            const valor = cliente[campo];
            if (valor === null || valor === undefined || String(valor).trim() === '') continue;
            const numero = Number(valor);
            if (!Number.isFinite(numero)) continue;
            if (valorExplicito === null) valorExplicito = numero;
            if (numero !== 0) return numero;
        }
        return valorExplicito ?? 0;
    }
    function nomeConsultor(row){
        const v  = (row.VENDEDOR  || '').trim();
        const v2 = (row.VENDEDOR2 || '').trim();
        if (v && v2) return `${v} <small style="color:var(--text-muted)">/ ${v2}</small>`;
        return v || v2 || '<span style="color:var(--text-muted)">—</span>';
    }

    // ── render ─────────────────────────────────────────────────────────────────
    function renderTabela(lista){
        const tbody = document.getElementById('tbody-gp');
        if (!lista.length){
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-5" style="color:var(--text-muted)">Nenhum registro encontrado.</td></tr>`;
            document.getElementById('lbl-contagem').textContent = '0 registros';
            return;
        }

        // Ordenação
        lista.sort((a,b)=>{
            // Priorizar clientes com botão de chamados visível
            const aTemBtn = window.chamadosValidos && window.chamadosValidos[a.ID_CLIENTE] && MAPA_LINKS_CHAMADOS[a.ID_CLIENTE] ? 1 : 0;
            const bTemBtn = window.chamadosValidos && window.chamadosValidos[b.ID_CLIENTE] && MAPA_LINKS_CHAMADOS[b.ID_CLIENTE] ? 1 : 0;
            
            if (aTemBtn !== bTemBtn) {
                return bTemBtn - aTemBtn;
            }

            let va = a[sortCol] ?? '', vb = b[sortCol] ?? '';
            if (typeof va === 'number') return sortAsc ? va-vb : vb-va;
            return sortAsc ? String(va).localeCompare(String(vb),'pt-BR') : String(vb).localeCompare(String(va),'pt-BR');
        });

        tbody.innerHTML = lista.map(r => {
            const isVinculado = MAPA_CLIENTES_LOCAL[r.ID_CLIENTE];
            const btnLink = isVinculado ? 
                `<a href="clientes.php?busca=${encodeURIComponent(r.FANTASIA || r.RAZAOSOCIAL)}" target="_blank" class="btn btn-sm btn-outline-primary ms-auto" style="padding:2px 6px; font-size:.7rem" title="Acessar Cliente Local"><i class="bi bi-link-45deg"></i> Vinculado</a>` : '';
            
            const localId = MAPA_CLIENTES_LOCAL[r.ID_CLIENTE] || 0;
            const nomeCliente = r.FANTASIA || r.RAZAOSOCIAL || '';
            const vendedorCliente = r.VENDEDOR || r.RESPONSAVEL || '';
            const servidorCliente = r.SERVIDOR || r.SERVIDORNUVEM || '';
            const dataInicioPadrao = r.DATA_INICIO || r.INICIO || r.DATAINICIO || '';
            const licencasCliente = r.NUM_LICENCAS ?? obterNumeroLicencas(r);
            const licencasApi = licencasCliente;
            const dadosAtualizacao = {
                fantasia: nomeCliente,
                vendedor: r.VENDEDOR || r.VENDEDOR2 || r.RESPONSAVEL || '',
                servidor: servidorCliente,
                num_licencas: licencasApi,
                serial: r.SERIAL || '',
                cnpj: r.CNPJ || r.CNPJ_CPF || r.DOCUMENTO || ''
            };
            const btnAtualizar = isVinculado ? `<button type="button" class="btn btn-sm btn-outline-warning fw-bold btn-atualizar-cliente-gp" data-id-cliente-api="${escapeHtmlAttribute(String(r.ID_CLIENTE || ''))}" data-dados="${escapeHtmlAttribute(JSON.stringify(dadosAtualizacao))}" title="Atualizar cadastro local com os dados da API"><i class="bi bi-arrow-repeat me-1"></i>Atualizar</button>` : '';


            const btnCadastrar = !isVinculado ? `<button class="btn btn-sm btn-success fw-bold btn-cadastrar-gp" data-nome="${escapeHtmlAttribute(nomeCliente)}" data-vendedor="${escapeHtmlAttribute(vendedorCliente)}" data-servidor="${escapeHtmlAttribute(servidorCliente)}" data-id-cliente-api="${escapeHtmlAttribute(String(r.ID_CLIENTE || ''))}" data-data-inicio="${escapeHtmlAttribute(dataInicioPadrao)}" data-licencas="${escapeHtmlAttribute(String(licencasCliente))}" data-serial="${escapeHtmlAttribute(String(r.SERIAL || ''))}" data-cnpj="${escapeHtmlAttribute(String(r.CNPJ || r.CNPJ_CPF || r.DOCUMENTO || ''))}" title="Cadastrar cliente local">
                    <i class="bi bi-plus-circle me-1"></i>Cadastrar
                </button>` : '';
                
            const linkChamados = MAPA_LINKS_CHAMADOS[r.ID_CLIENTE];
            const temChamados = window.chamadosValidos && window.chamadosValidos[r.ID_CLIENTE];
            let btnChamados = '';
            if (temChamados && linkChamados) {
                btnChamados = `<a href="${linkChamados}" target="_blank"
                               class="btn btn-sm btn-outline-success fw-bold ms-1"
                               title="Abrir Link Chamados"
                               style="font-size: 0.72rem; white-space: nowrap; padding:3px 8px; border-radius:7px;">
                                <i class="bi bi-headset me-1"></i> Chamados
                            </a>`;
            }

            const chamadosSuporte = (window.chamadosByCliente && window.chamadosByCliente[r.ID_CLIENTE]) || [];
            const chamadosEmAberto = chamadosSuporte.filter(ch => !CHAMADOS_STATUS_FECHADOS.includes(ch.CHAMADO_STATUS));
            const totalChamados = chamadosEmAberto.length;
            const chamadosSuporteValidos = chamadosSuporte.filter(ch => CHAMADOS_STATUS_WHATSAPP.includes(ch.CHAMADO_STATUS));
            let btnWhatsapp = '';
            if (chamadosSuporteValidos.length > 0) {
                const mensagem = criarMensagemWhatsappChamados(r.FANTASIA || r.RAZAOSOCIAL, chamadosSuporteValidos);
                btnWhatsapp = `<button type="button" class="btn btn-sm btn-outline-success fw-bold copy-chamados-whatsapp ms-1"
                        style="font-size: 0.72rem; white-space: nowrap; padding:3px 8px; border-radius:7px;"
                        data-bs-toggle="tooltip" data-bs-title="Copiar WhatsApp"
                        data-message="${escapeHtmlAttribute(mensagem)}"
                        data-cliente="${escapeHtmlAttribute(r.FANTASIA || r.RAZAOSOCIAL)}"
                        title="Copiar WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                </button>`;
            }

            const chamadosAguardandoTesteValidados = chamadosSuporte.filter(ch => ch.CHAMADO_STATUS === 'Aguardando Testes' && CHAMADOS_BAIXADOS.includes(parseInt(ch.ID)));
            let btnEnviarResolvidos = '';
            if (chamadosAguardandoTesteValidados.length > 0) {
                const msgResolvidos = criarMensagemWhatsappResolvidos(r.FANTASIA || r.RAZAOSOCIAL, chamadosAguardandoTesteValidados);
                btnEnviarResolvidos = `<button type="button" class="btn btn-sm btn-outline-info fw-bold copy-chamados-whatsapp ms-1"
                        style="font-size: 0.72rem; white-space: nowrap; padding:3px 8px; border-radius:7px;"
                        data-bs-toggle="tooltip" data-bs-title="Enviar Chamados Resolvidos"
                        data-message="${escapeHtmlAttribute(msgResolvidos)}"
                        data-cliente="${escapeHtmlAttribute(r.FANTASIA || r.RAZAOSOCIAL)}"
                        title="Enviar Chamados Resolvidos">
                    <i class="bi bi-send"></i>
                </button>`;
            }

            return `
            <tr>
                <td class="py-3" style="padding-left:1.5rem">
                    <div class="d-flex align-items-center gap-2">
                        <div style="min-width:0; flex:1;">
                            <div style="font-weight:600;color:var(--text-dark);font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${nomeCliente || '—'}</div>
                            <div style="font-size:.72rem;color:var(--text-muted)">${r.SERIAL || ''}</div>
                        </div>
                        ${btnLink}
                    </div>
                </td>
                <td>${badgeStatus(r.STATUS_IMPLANTACAO)}</td>
                <td style="font-size:.82rem;font-family:monospace;">${escapeHtml(r.SERIAL || '—')}</td>
                <td style="font-size:.82rem">${nomeConsultor(r)}</td>
                <td><span style="font-size:.78rem;font-family:monospace;background:var(--bg-body);padding:2px 8px;border-radius:6px">${r.SERVIDOR || '—'}</span></td>
                <td style="text-align:center;font-size:.82rem;font-weight:600;">
                    ${licencasCliente}
                </td>
                <td style="text-align:center;font-size:.82rem;font-weight:600;color:var(--primary);">
                    ${totalChamados}
                </td>
                <td style="text-align:center">
                    <div class="d-flex justify-content-center align-items-center flex-nowrap gap-1">
                        ${btnCadastrar}                        
                        ${btnAtualizar}
                        ${btnChamados}
                        ${btnWhatsapp}
                        ${btnEnviarResolvidos}
                    </div>
                </td>
            </tr>
            `;
        }).join('');

        document.getElementById('lbl-contagem').textContent = `${lista.length} registro${lista.length!==1?'s':''}`;
    }

    // ── filtros ────────────────────────────────────────────────────────────────
    function aplicarFiltros(){
        const busca    = document.getElementById('filtro-busca').value.toLowerCase();
        const cbStatus = Array.from(document.querySelectorAll('.filtro-status-cb:checked')).map(cb => cb.value);
        const vendedor = document.getElementById('filtro-vendedor').value;
        const nuvem    = document.getElementById('filtro-nuvem').value;

        const filtrado = todosRegistros.filter(r => {
            const texto = `${r.FANTASIA} ${r.RAZAOSOCIAL} ${r.SERIAL} ${r.VENDEDOR} ${r.VENDEDOR2} ${r.SERVIDOR}`.toLowerCase();
            if (busca && !texto.includes(busca)) return false;
            if (cbStatus.length > 0 && !cbStatus.includes(r.STATUS_IMPLANTACAO)) return false;
            if (vendedor && r.VENDEDOR !== vendedor && r.VENDEDOR2 !== vendedor) return false;
            if (nuvem && r.NUVEM !== nuvem) return false;
            return true;
        });

        atualizarKPIs(filtrado);
        renderTabela(filtrado);
    }

    function popularFiltroVendedor(lista){
        const sel = document.getElementById('filtro-vendedor');
        sel.innerHTML = '<option value="">Todos os vendedores</option>';
        const nomes = new Set();
        lista.forEach(r => {
            if (r.VENDEDOR)  nomes.add(r.VENDEDOR.trim());
            if (r.VENDEDOR2) nomes.add(r.VENDEDOR2.trim());
        });
        [...nomes].sort().forEach(n => {
            const op = document.createElement('option');
            op.value = n; op.textContent = n;
            sel.appendChild(op);
        });
        if ([...nomes].includes('VINICIUS')) sel.value = 'VINICIUS';
    }

    // ── KPIs ───────────────────────────────────────────────────────────────────
    function atualizarKPIs(lista){
        document.getElementById('kpi-andamento').textContent = lista.filter(r=>r.STATUS_IMPLANTACAO==='ANDAMENTO').length;
        document.getElementById('kpi-clienteok').textContent = lista.filter(r=>r.STATUS_IMPLANTACAO==='CLIENTE.OK').length;
        document.getElementById('kpi-aguardando').textContent= lista.filter(r=>r.STATUS_IMPLANTACAO==='AGUARD.CLI' || r.STATUS_IMPLANTACAO==='RISCO CANC').length;
        document.getElementById('kpi-encerrado').textContent = todosRegistros.filter(r=>r.STATUS_IMPLANTACAO==='ENCERRADA' && (r.VENDEDOR2 === 'VINICIUS' || r.VENDEDOR === 'VINICIUS')).length;
    }

    function criarMensagemWhatsappChamados(nomeCliente, chamados){
        const cliente = nomeCliente ? `"${nomeCliente.replace(/"/g,'\"')}"` : 'o cliente';
        const linhas = chamados.map(ch => {
            const descricao = String(ch.DESCRICAO || 'Sem descrição')
                .replace(/\r\n/g,'\n')
                .replace(/\r/g,'\n')
                .split('\n')
                .map(line => line.trim())
                .join(' ');
            const resumo = descricao;
            return `📋 Chamado #${ch.ID}\r\n` +
                   `⏳ Status: ${ch.CHAMADO_STATUS || 'Não informado'}\r\n` +
                   `🏷️ Tipo: ${ch.TIPOACOMP || 'Não informado'}\r\n` +
                   `📅 Previsão de Retorno: ${fmtDataTexto(ch.DATAPREV_RETORNO)}\r\n` +
                   `📝 Descrição: ${resumo}`;
        });
        return `Olá! Gostaria de informar todos os chamados que estão em aberto para a empresa ${cliente}:\r\n\r\n` +
               linhas.join('\r\n\r\n') +
               `\r\n\r\nQualquer dúvida, estou à disposição! 🚀`;
    }

    function criarMensagemWhatsappResolvidos(nomeCliente, chamados){
        const cliente = nomeCliente ? `"${nomeCliente.replace(/"/g,'\"')}"` : 'o cliente';
        const linhas = chamados.map(ch => {
            const descricao = String(ch.DESCRICAO || 'Sem descrição')
                .replace(/\r\n/g,'\n')
                .replace(/\r/g,'\n')
                .split('\n')
                .map(line => line.trim())
                .join(' ');
            return `📋 Chamado #${ch.ID}\r\n` +
                   `🏷️ Tipo: ${ch.TIPOACOMP || 'Não informado'}\r\n` +
                   `📝 Descrição: ${descricao}`;
        });
        return `Olá! Gostaria de informar os chamados que estão *RESOLVIDOS* para a empresa ${cliente}:\r\n\r\n` +
               linhas.join('\r\n\r\n') +
               `\r\n\r\nE para que receba essa atualização, *deslogue do sistema e logue novamente.*`;
    }

    function getFonteAtiva() {
        return localStorage.getItem('gp_monitoramento_fonte') || 'mesa';
    }

    function atualizarBotoesFonte(fonte) {
        const btnMesa = document.getElementById('btn-fonte-mesa');
        const btnGeral = document.getElementById('btn-fonte-geral');
        const subtitulo = document.getElementById('subtitulo-monitoramento');

        if (fonte === 'mesa') {
            if (btnMesa) btnMesa.className = 'btn btn-sm btn-primary fw-bold';
            if (btnGeral) btnGeral.className = 'btn btn-sm btn-outline-secondary fw-bold';
            if (subtitulo) {
                subtitulo.innerHTML = 'Leitura em tempo real de <strong>interno.gestaopro.srv.br/api/mesa?usuario=53&secoes=clientes</strong>';
            }
        } else {
            if (btnMesa) btnMesa.className = 'btn btn-sm btn-outline-secondary fw-bold';
            if (btnGeral) btnGeral.className = 'btn btn-sm btn-primary fw-bold';
            if (subtitulo) {
                subtitulo.innerHTML = 'Leitura em tempo real de <strong>interno.gestaopro.srv.br/api/implantacoes</strong>';
            }
        }
    }

    // ── carregar dados ─────────────────────────────────────────────────────────
    function carregarDados(forcar, somenteCache = false){
        const fonte = getFonteAtiva();
        atualizarBotoesFonte(fonte);

        document.getElementById('estado-carregando').classList.remove('d-none');
        document.getElementById('estado-erro').classList.add('d-none');
        document.getElementById('wrapper-tabela').classList.add('d-none');
        document.getElementById('ico-refresh').className = 'bi bi-arrow-clockwise';

        let pClientes, pChamados, pLicencas;

        if (fonte === 'mesa') {
            const urlMesa = 'api_gestaopro_bridge.php?endpoint=mesa&usuario=53&secoes=clientes,chamados' + (forcar ? '&forcar=1' : (somenteCache ? '&somente_cache=1' : ''));
            pClientes = fetch(urlMesa).then(r => r.json());
            const urlImplantacoes = forcar
                ? 'api_gestaopro_bridge.php?endpoint=implantacoes&forcar=1'
                : (somenteCache ? 'api_gestaopro_bridge.php?endpoint=implantacoes&somente_cache=1' : 'api_gestaopro_bridge.php?endpoint=implantacoes');
            pLicencas = fetch(urlImplantacoes)
                .then(r => r.json())
                .catch(() => null);
            pChamados = Promise.resolve({ sucesso: true, dados: { chamados: [] } });
        } else {
            const url = forcar
                ? 'api_gestaopro_bridge.php?forcar=1'
                : (somenteCache ? 'api_gestaopro_bridge.php?somente_cache=1' : 'api_gestaopro_bridge.php');
            const urlChamados = forcar
                ? 'api_gestaopro_bridge.php?endpoint=chamados&forcar=1'
                : (somenteCache ? 'api_gestaopro_bridge.php?endpoint=chamados&somente_cache=1' : 'api_gestaopro_bridge.php?endpoint=chamados');

            pClientes = fetch(url).then(r => r.json());
            pLicencas = Promise.resolve(null);
            pChamados = fetch(urlChamados)
                .then(r => r.json())
                .catch(err => ({ sucesso: false, erro: err.message || 'Falha de conexão' }));
        }

        Promise.all([pClientes, pChamados, pLicencas])
            .then(([resp, respChamados, respLicencas]) => {
                if (!resp.sucesso) throw new Error(resp.erro || 'Erro ao carregar clientes');

                let clientes = resp.dados.clientes || resp.dados || [];
                const dadosImplantacoes = respLicencas && respLicencas.sucesso ? respLicencas.dados : null;
                const registrosImplantacoes = Array.isArray(dadosImplantacoes)
                    ? dadosImplantacoes
                    : (Array.isArray(dadosImplantacoes?.clientes)
                        ? dadosImplantacoes.clientes
                        : (Array.isArray(dadosImplantacoes?.implantacoes) ? dadosImplantacoes.implantacoes : []));
                const licencasPorCliente = new Map();
                const implantacaoPorCliente = new Map();
                const normalizarNomeCliente = nome => String(nome || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toUpperCase();
                registrosImplantacoes.forEach(registro => {
                    const idCliente = String(registro.ID_CLIENTE || registro.ID || registro.ID_CLIENTE_API || '');
                    if (idCliente) implantacaoPorCliente.set(idCliente, registro);
                    const licencas = obterNumeroLicencas(registro);
                    if (idCliente && licencas > 0) licencasPorCliente.set(idCliente, licencas);
                    const nomeCliente = normalizarNomeCliente(registro.FANTASIA || registro.RAZAOSOCIAL);
                    if (nomeCliente && licencas > 0) licencasPorCliente.set(`nome:${nomeCliente}`, licencas);
                });
                clientes = clientes.map(c => {
                    const idApi = c.ID_CLIENTE || c.ID;
                    const st = (c.STATUS_IMPLANTACAO || '').trim().toUpperCase();
                    const registroImplantacao = implantacaoPorCliente.get(String(idApi)) || {};
                    const nomeCliente = normalizarNomeCliente(c.FANTASIA || c.RAZAOSOCIAL);
                    const licencasApi = licencasPorCliente.get(String(idApi))
                        ?? licencasPorCliente.get(`nome:${nomeCliente}`)
                        ?? obterNumeroLicencas(c);
                    return {
                        ...c,
                        ID_CLIENTE: idApi,
                        ID: c.ID || idApi,
                        FANTASIA: c.FANTASIA || c.RAZAOSOCIAL || '',
                        RAZAOSOCIAL: c.RAZAOSOCIAL || c.FANTASIA || '',
                        VENDEDOR: c.VENDEDOR || registroImplantacao.VENDEDOR || '',
                        VENDEDOR2: c.VENDEDOR2 || registroImplantacao.VENDEDOR2 || '',
                        SERVIDOR: c.SERVIDOR || '',
                        NUVEM: c.NUVEM || ((c.SERVIDOR && c.SERVIDOR.toUpperCase() !== 'LOCAL') ? 'T' : 'F'),
                        NUM_LICENCAS: licencasApi,
                        STATUS_IMPLANTACAO: st || 'OUTROS',
                    };
                });
                todosRegistros = clientes;

                window.chamadosValidos = {};
                window.chamadosByCliente = {};

                const chamadosList = (fonte === 'mesa' && resp.dados && Array.isArray(resp.dados.chamados))
                    ? resp.dados.chamados
                    : ((respChamados && respChamados.sucesso && respChamados.dados) ? (respChamados.dados.chamados || respChamados.dados || []) : []);

                chamadosList.forEach(ch => {
                    if (!window.chamadosByCliente[ch.ID_CLIENTE]) {
                        window.chamadosByCliente[ch.ID_CLIENTE] = [];
                    }
                    window.chamadosByCliente[ch.ID_CLIENTE].push(ch);
                    if (CHAMADOS_STATUS_WHATSAPP.includes(ch.CHAMADO_STATUS)) {
                        window.chamadosValidos[ch.ID_CLIENTE] = true;
                    }
                });

                clientes.forEach(c => {
                    const chamadosDoCliente = window.chamadosByCliente[c.ID_CLIENTE] || [];
                    const tipos = [...new Set(chamadosDoCliente.map(ch => (ch.TIPOACOMP || '').trim()).filter(Boolean))];
                    c.TIPOACOMP = tipos.join(' / ');
                });

                popularFiltroVendedor(clientes);
                aplicarFiltros();

                const origemBadge = document.getElementById('badge-origem');
                origemBadge.textContent = resp.origem === 'cache' ? '⚡ Cache' : '🌐 API';
                origemBadge.style.background = resp.origem === 'cache' ? 'var(--success-light)' : 'var(--primary-light)';
                origemBadge.style.color = resp.origem === 'cache' ? 'var(--success)' : 'var(--primary)';
                document.getElementById('badge-atualizacao').textContent = 'Atualizado: ' + (resp.gerado_em || '—');

                document.getElementById('estado-carregando').classList.add('d-none');
                document.getElementById('wrapper-tabela').classList.remove('d-none');
            })
            .catch(err => {
                document.getElementById('estado-carregando').classList.add('d-none');
                document.getElementById('estado-erro').classList.remove('d-none');
                let msg = err.message;
                if (fonte === 'implantacoes') {
                    msg += ' <br><br><button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="alternarParaMesaMonitoramento()"><i class="bi bi-kanban me-1"></i> Carregar Clientes da Mesa (#53)</button>';
                }
                document.getElementById('msg-erro').innerHTML = msg;
            })
            .finally(() => {
                document.getElementById('ico-refresh').className = 'bi bi-arrow-clockwise';
                document.getElementById('btn-refresh').disabled = false;
            });
    }

    window.alternarParaMesaMonitoramento = function() {
        localStorage.setItem('gp_monitoramento_fonte', 'mesa');
        carregarDados(false);
    };

    // ── eventos ────────────────────────────────────────────────────────────────
    const btnMesa = document.getElementById('btn-fonte-mesa');
    if (btnMesa) {
        btnMesa.addEventListener('click', function(){
            localStorage.setItem('gp_monitoramento_fonte', 'mesa');
            carregarDados(false);
        });
    }

    const btnGeral = document.getElementById('btn-fonte-geral');
    if (btnGeral) {
        btnGeral.addEventListener('click', function(){
            localStorage.setItem('gp_monitoramento_fonte', 'implantacoes');
            carregarDados(false);
        });
    }
    document.getElementById('btn-refresh').addEventListener('click', function(){
        this.disabled = true;
        document.getElementById('ico-refresh').className = 'bi bi-arrow-clockwise spin';
        carregarDados(true);
    });

    ['filtro-busca','filtro-vendedor','filtro-nuvem'].forEach(id => {
        document.getElementById(id).addEventListener('input', aplicarFiltros);
        document.getElementById(id).addEventListener('change', aplicarFiltros);
    });

    function atualizarLabelStatus() {
        const selecionados = Array.from(document.querySelectorAll('.filtro-status-cb:checked'));
        const btnLabel = document.getElementById('filtro-status-label');
        if (selecionados.length === 0) {
            btnLabel.textContent = 'Todos os status';
        } else if (selecionados.length === 1) {
            btnLabel.textContent = selecionados[0].nextElementSibling.textContent;
        } else {
            btnLabel.textContent = selecionados.length + ' status selecionados';
        }
    }

    document.querySelectorAll('.filtro-status-cb').forEach(cb => {
        cb.addEventListener('change', function() {
            atualizarLabelStatus();
            aplicarFiltros();
        });
    });

    // Inicializar o label com base nos checkboxes "checked" no HTML
    atualizarLabelStatus();

    // Impede que o dropdown feche ao clicar nas opções de checkbox
    document.getElementById('filtro-status-menu').addEventListener('click', function (e) {
        e.stopPropagation();
    });

    document.querySelectorAll('.sortable').forEach(th => {
        th.addEventListener('click', function(){
            const col = this.dataset.col;
            if (sortCol === col) sortAsc = !sortAsc;
            else { sortCol = col; sortAsc = true; }
            document.querySelectorAll('.sortable i').forEach(i=>i.className='bi bi-chevron-expand ms-1');
            this.querySelector('i').className = `bi bi-chevron-${sortAsc?'up':'down'} ms-1`;
            aplicarFiltros();
        });
    });

    document.addEventListener('click', function(event){
        const atualizarBtn = event.target.closest('.btn-atualizar-cliente-gp');
        if (atualizarBtn) {
            event.preventDefault();
            let dados = {};
            try { dados = JSON.parse(atualizarBtn.dataset.dados || '{}'); }
            catch (error) { alert('Não foi possível ler os dados da API para atualização.'); return; }
            const idApi = (atualizarBtn.dataset.idClienteApi || '').trim();
            if (!idApi || !confirm(`Atualizar o cadastro local de ${dados.fantasia || 'este cliente'} com os dados da API GestãoPro?`)) return;

            const textoOriginal = atualizarBtn.innerHTML;
            atualizarBtn.disabled = true;
            atualizarBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Atualizando';
            fetch('api_atualizar_cliente_gestaopro.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({id_cliente_api: idApi, dados})
            }).then(async response => {
                const resultado = await response.json();
                if (!response.ok || !resultado.sucesso) throw new Error(resultado.erro || 'Falha ao atualizar o cadastro.');
                alert(resultado.mensagem || 'Cadastro local atualizado com sucesso.');
                window.location.reload();
            }).catch(error => {
                alert(error.message || 'Falha ao atualizar o cadastro local.');
                atualizarBtn.disabled = false;
                atualizarBtn.innerHTML = textoOriginal;
            });
            return;
        }

        const btn = event.target.closest('.copy-chamados-whatsapp');
        if (btn) {
            const msg = btn.dataset.message;
            if (msg) {
                copiarTextoAreaTransferencia(msg, 'Mensagem copiada e arquivo TXT gerado com sucesso!');
                
                const clienteNome = btn.dataset.cliente ? btn.dataset.cliente.replace(/[^a-z0-9]/gi, '_').toLowerCase() : 'cliente';
                const blob = new Blob([msg], { type: 'text/plain;charset=utf-8' });
                const urlObj = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = urlObj;
                a.download = `chamados_${clienteNome}.txt`;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => {
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(urlObj);
                }, 100);
            }
            return;
        }

        const cadastrarBtn = event.target.closest('.btn-cadastrar-gp');
        if (cadastrarBtn) {
            event.preventDefault();
            abrirModalCadastroCliente(
                cadastrarBtn.dataset.nome,
                cadastrarBtn.dataset.vendedor,
                cadastrarBtn.dataset.servidor,
                cadastrarBtn.dataset.idClienteApi,
                cadastrarBtn.dataset.dataInicio,
                cadastrarBtn.dataset.licencas,
                cadastrarBtn.dataset.serial,
                cadastrarBtn.dataset.cnpj
            );
            return;
        }
    });

    // Init: mantém a última leitura da API em tela até que o usuário force a atualização manual.
    carregarDados(false, true);
})();
</script>

<style>
@keyframes spin { to { transform:rotate(360deg); } }
.spin { animation:spin .7s linear infinite; display:inline-block; }
</style>

<!-- Modal Cadastro de Cliente (GestãoPro) -->
<div class="modal fade" id="modalCadastroClienteGP" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="clientes.php" class="modal-content border-0 shadow-lg" id="formCadastroClienteGP" style="border-radius: 24px; overflow: hidden;">
            <input type="hidden" name="redirect_to" value="monitoramento_gestaopro.php">
            <input type="hidden" name="abrir_ficha_contato" value="1">
            <div class="modal-header p-4 border-0" style="background: linear-gradient(135deg, #16a34a, #22c55e);">
                <div>
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                        <i class="bi bi-person-plus fs-4"></i> Cadastrar Cliente
                    </h5>
                    <p class="mb-0 text-white opacity-75 small">Cliente novo na API</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Nome Fantasia / Empresa</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-building text-success"></i></span>
                        <input type="text" name="fantasia" id="cadastroNomeFantasia" class="form-control border-start-0 ps-0" required placeholder="Ex: V.M MATÉRIAS DE LIMPEZA">
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Vendedor</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-person-badge text-success"></i></span>
                            <input type="text" name="vendedor" id="cadastroVendedor" class="form-control border-start-0 ps-0" placeholder="Nome do vendedor">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Servidor</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-server text-success"></i></span>
                            <input type="text" name="servidor" id="cadastroServidor" class="form-control border-start-0 ps-0" placeholder="Ex: LOCAL / NUVEM">
                        </div>
                    </div>
                </div>
                <div class="row g-3 mt-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Data de Início</label>
                        <input type="date" name="data_inicio" id="cadastroDataInicio" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Nº de Licenças</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-key text-success"></i></span>
                            <input type="number" name="num_licencas" id="cadastroNumLicencas" class="form-control border-start-0 ps-0" placeholder="0">
                        </div>
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label small fw-bold text-muted">SERIAL</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-upc-scan text-success"></i></span>
                        <input type="text" name="serial" id="cadastroSerial" class="form-control border-start-0 ps-0" placeholder="Ex: GP-00001">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">CNPJ</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-card-text text-success"></i></span>
                        <input type="text" name="cnpj" id="cadastroCnpj" class="form-control border-start-0 ps-0" placeholder="CNPJ do cliente">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">ID Cliente API (GestãoPro)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-braces-asterisk text-success"></i></span>
                        <input type="number" name="id_cliente_api" id="cadastroIdClienteApi" class="form-control border-start-0 ps-0" placeholder="Ex: 6547" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Link do Cadastro</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-link-45deg text-success"></i></span>
                        <input type="text" name="anexo" id="cadastroLinkCadastro" class="form-control border-start-0 ps-0" placeholder="Ex: https://interno.gestaopro.srv.br/clientes/7618" required>
                    </div>
                </div>
                <!-- campo oculto para link do Drive → salvo em Link Vídeos Passo a Passo -->
                <input type="hidden" name="link_videos" id="cadastroLinkDrive">
                <!-- Alerta de status da criação da pasta -->
                <div id="alertDriveCadastro" class="alert d-none py-2 px-3 small border-0 rounded-3" role="alert"></div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" id="btnSubmitCadastroGP" class="btn btn-success px-5 fw-bold" style="border-radius: 12px; height: 46px;">
                    <i class="bi bi-check-lg me-2"></i> Cadastrar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Novo Agendamento (GestãoPro) -->
<div class="modal fade" id="modalAgendamentoGP" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="salvar_treinamento.php" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden;">
            <input type="hidden" name="id_cliente" id="gp_id_cliente">
            <input type="hidden" name="redirect_to" value="monitoramento_gestaopro.php">
            <div class="modal-header p-4 border-0" style="background: linear-gradient(135deg, #7c3aed, #4361ee);">
                <div>
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-plus fs-4"></i> Agendar Treinamento
                    </h5>
                    <p class="mb-0 text-white opacity-75 small" id="gp_cliente_label">Cliente</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Cliente</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:rgba(124,58,237,0.1); border-color:rgba(124,58,237,0.3);"><i class="bi bi-building" style="color:#7c3aed"></i></span>
                        <input type="text" class="form-control fw-bold" id="gp_cliente_display" readonly
                               style="background:rgba(124,58,237,0.05); border-color:rgba(124,58,237,0.3); cursor:default;">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Contato / Interlocutor</label>
                    <input type="text" name="nome_contato" id="gp_nome_contato" class="form-control" placeholder="Digite o nome do contato..." required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Módulo / Tema</label>
                    <select name="tema" id="gp_tema" class="form-select" required>
                        <option value="INSTALAÇÃO SISTEMA">INSTALAÇÃO SISTEMA</option>
                        <option value="CADASTROS/ESTOQUE">CADASTROS/ESTOQUE</option>
                        <option value="VENDAS">VENDAS</option>
                        <option value="COMPRAS">COMPRAS</option>
                        <option value="FATURAMENTO/NF">FATURAMENTO/NF</option>
                        <option value="FINANCEIRO/CAIXA">FINANCEIRO/CAIXA</option>
                        <option value="PRODUÇÃO/OS">PRODUÇÃO/OS</option>
                        <option value="RELATÓRIOS">RELATÓRIOS</option>
                        <option value="ATENDIMENTOS">ATENDIMENTOS</option>
                        <option value="DUVIDAS">DUVIDAS</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-7">
                        <label class="form-label small fw-bold text-muted">Data e Horário</label>
                        <input type="datetime-local" name="data_treinamento" id="gp_data_treinamento" class="form-control" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status" id="gp_status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="AGENDADO">AGENDADO</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Cancelar</button>                
            </div>
        </form>
    </div>
</div>

<script>

function abrirModalCadastroCliente(nome, vendedor, servidor, idClienteApi, dataInicio, numLicencas, serial, cnpj) {
    const idApi = idClienteApi ? String(idClienteApi).trim() : '';
    const linkCadastro = idApi ? `https://interno.gestaopro.srv.br/clientes/${idApi}` : '';

    document.getElementById('cadastroNomeFantasia').value = nome || '';
    document.getElementById('cadastroVendedor').value = vendedor || '';
    document.getElementById('cadastroServidor').value = servidor || '';
    document.getElementById('cadastroDataInicio').value = dataInicio || new Date().toISOString().slice(0, 10);
    document.getElementById('cadastroNumLicencas').value = numLicencas ? Number(numLicencas) : 0;
    document.getElementById('cadastroIdClienteApi').value = idApi;
    document.getElementById('cadastroLinkCadastro').value = linkCadastro;
    document.getElementById('cadastroSerial').value = serial || '';
    document.getElementById('cadastroCnpj').value = cnpj || '';
    document.getElementById('cadastroLinkDrive').value = '';
    // Limpa alerta
    const alerta = document.getElementById('alertDriveCadastro');
    alerta.className = 'alert d-none py-2 px-3 small border-0 rounded-3';
    alerta.textContent = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCadastroClienteGP')).show();
}

const cadastroIdClienteApiInput = document.getElementById('cadastroIdClienteApi');
if (cadastroIdClienteApiInput) {
    cadastroIdClienteApiInput.addEventListener('input', function () {
        const idApi = this.value.trim();
        const linkInput = document.getElementById('cadastroLinkCadastro');
        if (linkInput) {
            linkInput.value = idApi ? `https://interno.gestaopro.srv.br/clientes/${idApi}` : '';
        }
    });
}

// ── Intercepta o submit do modal para criar pasta no Drive antes de cadastrar ──
const formCadastroGP = document.getElementById('formCadastroClienteGP');
if (formCadastroGP) {
    formCadastroGP.addEventListener('submit', async function(e) {
        const serial = document.getElementById('cadastroSerial').value.trim();
        // Se não tiver serial, envia direto sem criar pasta
        if (!serial) { return; }

        e.preventDefault();
        const btn = document.getElementById('btnSubmitCadastroGP');
        const alerta = document.getElementById('alertDriveCadastro');

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Criando pasta no Drive...';
        alerta.className = 'alert alert-info py-2 px-3 small border-0 rounded-3';
        alerta.textContent = 'Criando pasta no Google Drive...';

        try {
            const fd = new FormData();
            fd.append('serial', serial);
            const resp = await fetch('criar_pasta_drive.php', { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.sucesso) {
                document.getElementById('cadastroLinkDrive').value = data.link || '';
                alerta.className = 'alert alert-success py-2 px-3 small border-0 rounded-3';
                alerta.textContent = '✅ Pasta criada no Google Drive! Salvando cadastro...';
                // Aguarda 600ms para o usuário ver o sucesso, depois envia o form
                setTimeout(() => { formCadastroGP.submit(); }, 600);
            } else {
                alerta.className = 'alert alert-warning py-2 px-3 small border-0 rounded-3';
                alerta.textContent = '⚠️ Não foi possível criar a pasta no Drive: ' + (data.erro || 'Erro desconhecido') + '. O cadastro será salvo sem o link.';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-2"></i> Cadastrar mesmo assim';
                // Permite que o usuário tente enviar manualmente
                formCadastroGP.removeEventListener('submit', arguments.callee);
            }
        } catch (err) {
            alerta.className = 'alert alert-warning py-2 px-3 small border-0 rounded-3';
            alerta.textContent = '⚠️ Erro de conexão ao criar pasta no Drive. O cadastro será salvo sem o link.';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-2"></i> Cadastrar mesmo assim';
            formCadastroGP.removeEventListener('submit', arguments.callee);
        }
    });
}
</script>

<?php
$js_extra = '';
include 'footer.php';
?>
