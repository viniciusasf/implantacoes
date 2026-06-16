<?php 
require_once 'config.php';
require_once 'header.php'; 

// Buscar mapeamento de clientes
$stmt_map = $pdo->query("SELECT id_cliente, id_cliente_api FROM clientes WHERE id_cliente_api IS NOT NULL AND id_cliente_api != ''");
$mapa_clientes_local = [];
while ($row_map = $stmt_map->fetch(PDO::FETCH_ASSOC)) {
    $mapa_clientes_local[$row_map['id_cliente_api']] = $row_map['id_cliente'];
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
        <p class="mb-0" style="color:var(--text-muted);font-size:.85rem;">
            Dados em tempo real da API <strong>interno.gestaopro.srv.br</strong>
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
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
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="AGUARD.CLI" id="chk-status-aguardando" checked>
                                <label class="form-check-label w-100" for="chk-status-aguardando" style="cursor:pointer;">Aguard. Cliente</label>
                            </div>
                        </li>
                        <li>
                            <div class="form-check mb-1">
                                <input class="form-check-input filtro-status-cb" type="checkbox" value="CLIENTE.OK" id="chk-status-cliente-ok" checked>
                                <label class="form-check-label w-100" for="chk-status-cliente-ok" style="cursor:pointer;">Cliente OK</label>
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
                            <th class="py-3 sortable" data-col="VENDEDOR">Consultor <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="SERVIDOR">Servidor <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="DDU" style="text-align:center">DDU <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="QTD_FOLLOW_UP" style="text-align:center">Follow-ups <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="ULTIMO_TREINAMENTO">Último Trei. <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3 sortable" data-col="TREINAMENTO_AGENDADO">Próximo Trei. <i class="bi bi-chevron-expand ms-1"></i></th>
                            <th class="py-3" style="text-align:center">Nuvem</th>
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
</style>

<script>
const MAPA_CLIENTES_LOCAL = <?php echo json_encode($mapa_clientes_local); ?>;

(function(){
    let todosRegistros = [];
    let sortCol = 'FANTASIA', sortAsc = true;

    // ── helpers ────────────────────────────────────────────────────────────────
    function fmtData(iso){
        if (!iso) return '<span style="color:var(--text-muted)">—</span>';
        const d = new Date(iso);
        return d.toLocaleDateString('pt-BR');
    }

    function badgeStatus(s){
        if (!s) return '<span class="badge-status badge-outros">—</span>';
        const mapa = {
            'ANDAMENTO':'badge-andamento',
            'AGUARD.CLI':'badge-aguardando',
            'CLIENTE.OK':'badge-clienteok',
            'ENCERRADA':'badge-encerrada',
        };
        const cls = mapa[s] || 'badge-outros';
        let label = s.charAt(0)+s.slice(1).toLowerCase();
        if (s === 'AGUARD.CLI') label = 'Aguard. Cliente';
        if (s === 'CLIENTE.OK') label = 'Cliente OK';
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
            tbody.innerHTML = `<tr><td colspan="9" class="text-center py-5" style="color:var(--text-muted)">Nenhum registro encontrado.</td></tr>`;
            document.getElementById('lbl-contagem').textContent = '0 registros';
            return;
        }

        // Ordenação
        lista.sort((a,b)=>{
            let va = a[sortCol] ?? '', vb = b[sortCol] ?? '';
            if (typeof va === 'number') return sortAsc ? va-vb : vb-va;
            return sortAsc ? String(va).localeCompare(String(vb),'pt-BR') : String(vb).localeCompare(String(va),'pt-BR');
        });

        tbody.innerHTML = lista.map(r => {
            const isVinculado = MAPA_CLIENTES_LOCAL[r.ID_CLIENTE];
            const btnLink = isVinculado ? 
                `<a href="clientes.php?busca=${encodeURIComponent(r.FANTASIA || r.RAZAOSOCIAL)}" target="_blank" class="btn btn-sm btn-outline-primary ms-auto" style="padding:2px 6px; font-size:.7rem" title="Acessar Cliente Local"><i class="bi bi-link-45deg"></i> Vinculado</a>` : '';
                
            return `
            <tr>
                <td class="py-3" style="padding-left:1.5rem">
                    <div class="d-flex align-items-center gap-2">
                        <div style="min-width:0; flex:1;">
                            <div style="font-weight:600;color:var(--text-dark);font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${r.FANTASIA || r.RAZAOSOCIAL || '—'}</div>
                            <div style="font-size:.72rem;color:var(--text-muted)">${r.SERIAL || ''}</div>
                        </div>
                        ${btnLink}
                    </div>
                </td>
                <td>${badgeStatus(r.STATUS_IMPLANTACAO)}</td>
                <td style="font-size:.82rem">${nomeConsultor(r)}</td>
                <td><span style="font-size:.78rem;font-family:monospace;background:var(--bg-body);padding:2px 8px;border-radius:6px">${r.SERVIDOR || '—'}</span></td>
                <td style="text-align:center">${chipDDU(r.DDU ?? 0)}</td>
                <td style="text-align:center;font-weight:600;color:var(--text-dark)">${r.QTD_FOLLOW_UP ?? 0}</td>
                <td style="font-size:.82rem">${fmtData(r.ULTIMO_TREINAMENTO)}</td>
                <td style="font-size:.82rem">${fmtData(r.TREINAMENTO_AGENDADO)}</td>
                <td style="text-align:center">${iconeNuvem(r.NUVEM)}</td>
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
        document.getElementById('kpi-aguardando').textContent= lista.filter(r=>r.STATUS_IMPLANTACAO==='AGUARD.CLI').length;
        document.getElementById('kpi-encerrado').textContent = todosRegistros.filter(r=>r.STATUS_IMPLANTACAO==='ENCERRADA' && r.VENDEDOR2 === 'VINICIUS').length;
    }

    // ── carregar dados ─────────────────────────────────────────────────────────
    function carregarDados(forcar){
        document.getElementById('estado-carregando').classList.remove('d-none');
        document.getElementById('estado-erro').classList.add('d-none');
        document.getElementById('wrapper-tabela').classList.add('d-none');
        document.getElementById('ico-refresh').className = 'bi bi-arrow-clockwise';

        const url = forcar ? 'api_gestaopro_bridge.php?forcar=1' : 'api_gestaopro_bridge.php';

        fetch(url)
            .then(r => r.json())
            .then(resp => {
                if (!resp.sucesso) throw new Error(resp.erro || 'Erro desconhecido');

                const clientes = resp.dados.clientes || resp.dados || [];
                todosRegistros = clientes;

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
                document.getElementById('msg-erro').textContent = err.message;
            })
            .finally(() => {
                document.getElementById('ico-refresh').className = 'bi bi-arrow-clockwise';
                document.getElementById('btn-refresh').disabled = false;
            });
    }

    // ── eventos ────────────────────────────────────────────────────────────────
    document.getElementById('btn-refresh').addEventListener('click', function(){
        this.disabled = true;
        document.getElementById('ico-refresh').className = 'bi bi-arrow-clockwise spin';
        // Invalida cache deletando arquivo via parâmetro
        fetch('api_gestaopro_bridge.php?forcar=1').then(()=>carregarDados(false));
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

    // Init
    carregarDados(false);
})();
</script>

<style>
@keyframes spin { to { transform:rotate(360deg); } }
.spin { animation:spin .7s linear infinite; display:inline-block; }
</style>

<?php
$js_extra = '';
include 'footer.php';
?>
