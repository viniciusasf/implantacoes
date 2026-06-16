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

<!-- HEADER -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="mb-1" style="font-size:1.6rem;">
            <i class="bi bi-headset me-2" style="color:var(--danger)"></i>Chamados de Suporte
        </h1>
        <p class="mb-0" style="color:var(--text-muted);font-size:.85rem;">
            Leitura em tempo real de <strong>interno.gestaopro.srv.br/api/chamados</strong>
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="badge-origem" class="badge rounded-pill" style="font-size:.75rem;background:var(--primary-light);color:var(--primary)">—</span>
        <span id="badge-hora" style="font-size:.75rem;color:var(--text-muted)"></span>
        <button id="btn-refresh" class="btn btn-primary btn-sm">
            <i class="bi bi-arrow-clockwise" id="ico-refresh"></i> Atualizar
        </button>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['id'=>'kpi-aguard-dev',   'label'=>'Aguard. Desenvolvimento','icon'=>'bi-code-slash',       'color'=>'warning'],
        ['id'=>'kpi-aguard-testes','label'=>'Aguardando Testes',     'icon'=>'bi-check2-circle',     'color'=>'success'],
        ['id'=>'kpi-aguard-suporte','label'=>'Aguardando Suporte',   'icon'=>'bi-headset',           'color'=>'purple'],
        ['id'=>'kpi-total',        'label'=>'Total',                'icon'=>'bi-ticket-detailed',   'color'=>'primary'],
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
                <input type="text" id="filtro-busca" class="form-control form-control-sm" placeholder="🔍  Buscar cliente, responsável, descrição...">
            </div>
            <div class="col-md-2">
                <div class="dropdown">
                    <button class="form-select form-select-sm dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" id="filtro-status-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="filtro-status-label" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Todos os status</span>
                    </button>
                    <ul class="dropdown-menu w-100 shadow-sm p-2" aria-labelledby="filtro-status-btn" style="border-radius: var(--radius-md); border-color: var(--border-color); font-size: 0.9rem; max-height: 300px; overflow-y: auto;" id="filtro-status-menu">
                        <!-- Gerado via JS -->
                    </ul>
                </div>
            </div>
            <div class="col-md-2">
                <div class="dropdown">
                    <button class="form-select form-select-sm dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" id="filtro-tipo-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="filtro-tipo-label" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Todos os tipos</span>
                    </button>
                    <ul class="dropdown-menu w-100 shadow-sm p-2" aria-labelledby="filtro-tipo-btn" style="border-radius: var(--radius-md); border-color: var(--border-color); font-size: 0.9rem; max-height: 300px; overflow-y: auto;" id="filtro-tipo-menu">
                        <!-- Gerado via JS -->
                    </ul>
                </div>
            </div>
            <div class="col-md-2">
                <select id="filtro-responsavel" class="form-select form-select-sm">
                    <option value="">Todos os responsáveis</option>
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
            <div class="spinner-border" style="color:var(--danger)" role="status"></div>
            <p class="mt-3 mb-0" style="color:var(--text-muted)">Buscando chamados da API...</p>
        </div>
        <div id="estado-erro" class="d-none text-center py-5">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:2.5rem;color:var(--danger)"></i>
            <p class="mt-3 mb-1 fw-bold">Não foi possível carregar os chamados</p>
            <p id="msg-erro" style="color:var(--text-muted);font-size:.85rem"></p>
        </div>
        <div id="wrapper-tabela" class="d-none">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr style="background:var(--bg-body);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">
                            <th class="px-4 py-3 sortable" data-col="ID">#ID</th>
                            <th class="py-3 sortable" data-col="FANTASIA">Cliente</th>
                            <th class="py-3 sortable" data-col="CHAMADO_STATUS">Status</th>
                            <th class="py-3 sortable" data-col="TIPOACOMP">Tipo</th>
                            <th class="py-3 sortable" data-col="RESPONSAVEL">Responsável</th>
                            <th class="py-3 sortable" data-col="PESO" style="text-align:center">Peso</th>
                            <th class="py-3 sortable" data-col="DATA">Abertura</th>
                            <th class="py-3 sortable" data-col="DATAPREV_RETORNO">Prev. Retorno</th>
                            <th class="py-3 sortable" data-col="EXCEDIDO" style="text-align:center">Excedido</th>
                            <th class="py-3 text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-chamados"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div>

<style>
.sortable{cursor:pointer;user-select:none}.sortable:hover{color:var(--primary)!important}
.badge-ch{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:20px;letter-spacing:.03em;white-space:nowrap}
.peso-chip{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;font-weight:700;font-size:.8rem}
</style>

<script>
const MAPA_CLIENTES_LOCAL = <?php echo json_encode($mapa_clientes_local); ?>;

(function(){
    let todos=[], sortCol='DATA', sortAsc=false;

    function fmtData(iso){
        if(!iso) return '<span style="color:var(--text-muted)">—</span>';
        return new Date(iso).toLocaleDateString('pt-BR');
    }

    const STATUS_COR = {
        'Aguardando Desenvolvimento': ['var(--warning-light)','var(--warning)'],
        'Em Desenvolvimento':          ['var(--info-light)',   'var(--info)'],
        'Aguardando Cliente':          ['var(--purple-light)', 'var(--purple)'],
        'Aguardando Testes':           ['var(--success-light)','var(--success)'],
        'Resolvido':                   ['var(--success-light)','var(--success)'],
        'Cancelado':                   ['#f1f5f9',             'var(--text-muted)'],
    };

    function badgeStatus(s){
        if(!s) return '—';
        const [bg,fg] = STATUS_COR[s] || ['var(--primary-light)','var(--primary)'];
        return `<span class="badge-ch" style="background:${bg};color:${fg}">${s}</span>`;
    }

    function chipPeso(v){
        const n=parseInt(v)||0;
        const cls = n>=8?'danger': n>=5?'warning':'success';
        return `<span class="peso-chip" style="background:var(--${cls}-light);color:var(--${cls})">${n}</span>`;
    }

    function populaSelect(id, valores, padrao){
        const sel=document.getElementById(id);
        sel.innerHTML = `<option value="">Todos os ${id==='filtro-responsavel'?'responsáveis':'itens'}</option>`;
        [...new Set(valores)].filter(Boolean).sort().forEach(v=>{
            const o=document.createElement('option'); o.value=v; o.textContent=v; sel.appendChild(o);
        });
        if (padrao && [...sel.options].some(o => o.value === padrao)) sel.value = padrao;
    }

    function atualizarLabelDropdown(idLabel, classeCb, textoTodos) {
        const selecionados = Array.from(document.querySelectorAll('.' + classeCb + ':checked'));
        const btnLabel = document.getElementById(idLabel);
        if (selecionados.length === 0) {
            btnLabel.textContent = textoTodos;
        } else if (selecionados.length === 1) {
            btnLabel.textContent = selecionados[0].nextElementSibling.textContent;
        } else {
            btnLabel.textContent = selecionados.length + ' selecionados';
        }
    }

    function populaDropdownCheckboxes(idMenu, idLabel, classeCb, valores, padroes, textoTodos) {
        const menu = document.getElementById(idMenu);
        menu.innerHTML = '';
        [...new Set(valores)].filter(Boolean).sort().forEach((v, i) => {
            const isChecked = padroes.includes(v) ? 'checked' : '';
            const chkId = idMenu + '-chk-' + i;
            const li = document.createElement('li');
            li.innerHTML = `
                <div class="form-check mb-1">
                    <input class="form-check-input ${classeCb}" type="checkbox" value="${v}" id="${chkId}" ${isChecked}>
                    <label class="form-check-label w-100" for="${chkId}" style="cursor:pointer;">${v}</label>
                </div>
            `;
            menu.appendChild(li);
        });
        
        document.querySelectorAll('.' + classeCb).forEach(cb => {
            cb.addEventListener('change', function() {
                atualizarLabelDropdown(idLabel, classeCb, textoTodos);
                aplicarFiltros();
            });
        });
        
        atualizarLabelDropdown(idLabel, classeCb, textoTodos);
    }

    function renderTabela(lista){
        const tbody=document.getElementById('tbody-chamados');
        document.getElementById('lbl-contagem').textContent=lista.length+' registro'+(lista.length!==1?'s':'');
        if(!lista.length){
            tbody.innerHTML='<tr><td colspan="10" class="text-center py-5" style="color:var(--text-muted)">Nenhum chamado encontrado.</td></tr>';
            return;
        }
        lista.sort((a,b)=>{
            let va=a[sortCol]??'', vb=b[sortCol]??'';
            if(typeof va==='number') return sortAsc?va-vb:vb-va;
            return sortAsc?String(va).localeCompare(String(vb),'pt-BR'):String(vb).localeCompare(String(va),'pt-BR');
        });
        tbody.innerHTML=lista.map(r=>{
            const isVinculado = MAPA_CLIENTES_LOCAL[r.ID_CLIENTE];
            const btnLink = isVinculado ? 
                `<a href="clientes.php?busca=${encodeURIComponent(r.FANTASIA || r.RAZAOSOCIAL || '')}" target="_blank" class="btn btn-sm btn-outline-primary ms-auto" style="padding:2px 6px; font-size:.7rem; margin-top:2px;" title="Acessar Cliente Local"><i class="bi bi-link-45deg"></i> Vinculado</a>` : '';
                
            return `
        <tr>
            <td class="px-4 py-3" style="font-size:.78rem;font-family:monospace;color:var(--text-muted)">#${r.ID}</td>
            <td>
                <div class="d-flex align-items-start gap-2">
                    <div style="min-width:0; flex:1;">
                        <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${r.FANTASIA||'—'}</div>
                        <div style="font-size:.72rem;color:var(--text-muted)">${r.SERIAL||''}</div>
                    </div>
                    ${btnLink}
                </div>
            </td>
            <td>${badgeStatus(r.CHAMADO_STATUS)}</td>
            <td style="font-size:.82rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r.TIPOACOMP||''}">${r.TIPOACOMP||'—'}</td>
            <td style="font-size:.82rem">${r.RESPONSAVEL||'—'}</td>
            <td style="text-align:center">${chipPeso(r.PESO)}</td>
            <td style="font-size:.82rem">${fmtData(r.DATA)}</td>
            <td style="font-size:.82rem">${fmtData(r.DATAPREV_RETORNO)}</td>
            <td style="text-align:center">
                ${r.EXCEDIDO?'<i class="bi bi-alarm-fill" style="color:var(--danger)" title="Prazo excedido"></i>':'<span style="color:var(--text-muted)">—</span>'}
            </td>
            <td style="text-align:center">
                <a href="https://interno.gestaopro.srv.br/chamados/${r.ID}" target="_blank" class="btn btn-sm btn-primary fw-bold shadow-sm" style="padding: 0.2rem 0.6rem; font-size: 0.75rem;" title="Abrir chamado">
                    <i class="bi bi-box-arrow-up-right"></i> Abrir
                </a>
            </td>
        </tr>`;
        }).join('');
    }

    function aplicarFiltros(){
        const busca=document.getElementById('filtro-busca').value.toLowerCase();
        const statusList = Array.from(document.querySelectorAll('.filtro-status-cb:checked')).map(cb => cb.value);
        const tipoList   = Array.from(document.querySelectorAll('.filtro-tipo-cb:checked')).map(cb => cb.value);
        const resp=document.getElementById('filtro-responsavel').value;

        const fil=todos.filter(r=>{
            const txt=`${r.FANTASIA} ${r.SERIAL} ${r.DESCRICAO} ${r.RESPONSAVEL} ${r.CHAMADO_USUARIO}`.toLowerCase();
            if(busca&&!txt.includes(busca)) return false;
            if(statusList.length > 0 && !statusList.includes(r.CHAMADO_STATUS)) return false;
            if(tipoList.length > 0 && !tipoList.includes(r.TIPOACOMP)) return false;
            if(resp&&r.RESPONSAVEL!==resp) return false;
            return true;
        });
        atualizarKPIs(fil);
        renderTabela(fil);
    }

    function atualizarKPIs(lista){
        document.getElementById('kpi-aguard-dev').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Desenvolvimento').length;
        document.getElementById('kpi-aguard-testes').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Testes').length;
        document.getElementById('kpi-aguard-suporte').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Suporte').length;
        document.getElementById('kpi-total').textContent=lista.length;
    }

    function carregarDados(){
        document.getElementById('estado-carregando').classList.remove('d-none');
        document.getElementById('estado-erro').classList.add('d-none');
        document.getElementById('wrapper-tabela').classList.add('d-none');
        document.getElementById('btn-refresh').disabled=true;

        const forcar=window._forcar||false; window._forcar=false;
        const url='api_gestaopro_bridge.php?endpoint=chamados'+(forcar?'&forcar=1':'');

        fetch(url).then(r=>r.json()).then(resp=>{
            if(!resp.sucesso) throw new Error(resp.erro||'Erro desconhecido');
            const lista=resp.dados.chamados||[];
            todos=lista;

            populaDropdownCheckboxes('filtro-status-menu', 'filtro-status-label', 'filtro-status-cb', lista.map(r=>r.CHAMADO_STATUS), ['Aguardando Desenvolvimento', 'Aguardando Suporte', 'Aguardando Testes'], 'Todos os status');
            populaDropdownCheckboxes('filtro-tipo-menu', 'filtro-tipo-label', 'filtro-tipo-cb', lista.map(r=>r.TIPOACOMP), [], 'Todos os tipos');
            populaSelect('filtro-responsavel', lista.map(r=>r.RESPONSAVEL), 'VINICIUS');
            aplicarFiltros();

            const ob=document.getElementById('badge-origem');
            ob.textContent=resp.origem==='cache'?'⚡ Cache':'🌐 API';
            ob.style.background=resp.origem==='cache'?'var(--success-light)':'var(--primary-light)';
            ob.style.color=resp.origem==='cache'?'var(--success)':'var(--primary)';
            document.getElementById('badge-hora').textContent='Atualizado: '+(resp.gerado_em||'—');

            document.getElementById('estado-carregando').classList.add('d-none');
            document.getElementById('wrapper-tabela').classList.remove('d-none');
        }).catch(err=>{
            document.getElementById('estado-carregando').classList.add('d-none');
            document.getElementById('estado-erro').classList.remove('d-none');
            document.getElementById('msg-erro').textContent=err.message;
        }).finally(()=>{
            document.getElementById('btn-refresh').disabled=false;
            document.getElementById('ico-refresh').className='bi bi-arrow-clockwise';
        });
    }

    document.getElementById('btn-refresh').addEventListener('click',function(){
        window._forcar=true;
        document.getElementById('ico-refresh').className='bi bi-arrow-clockwise spin';
        carregarDados();
    });

    ['filtro-busca','filtro-responsavel'].forEach(id=>{
        document.getElementById(id).addEventListener('input',aplicarFiltros);
        document.getElementById(id).addEventListener('change',aplicarFiltros);
    });

    document.getElementById('filtro-status-menu').addEventListener('click', function (e) { e.stopPropagation(); });
    document.getElementById('filtro-tipo-menu').addEventListener('click', function (e) { e.stopPropagation(); });

    document.querySelectorAll('.sortable').forEach(th=>{
        th.addEventListener('click',function(){
            const col=this.dataset.col;
            if(sortCol===col) sortAsc=!sortAsc; else{sortCol=col;sortAsc=true;}
            document.querySelectorAll('.sortable').forEach(t=>t.style.color='');
            this.style.color='var(--primary)';
            aplicarFiltros();
        });
    });

    carregarDados();
})();
</script>

<style>
@keyframes spin{to{transform:rotate(360deg)}}
.spin{animation:spin .7s linear infinite;display:inline-block}
</style>

<?php $js_extra=''; include 'footer.php'; ?>
