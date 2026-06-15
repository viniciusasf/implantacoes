<?php require_once 'header.php'; ?>
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
        ['id'=>'kpi-total',        'label'=>'Total',                'icon'=>'bi-ticket-detailed',   'color'=>'primary'],
        ['id'=>'kpi-aguard-dev',   'label'=>'Aguard. Desenvolvimento','icon'=>'bi-code-slash',       'color'=>'warning'],
        ['id'=>'kpi-customizacao', 'label'=>'Customização de Tela',  'icon'=>'bi-brush',             'color'=>'purple'],
        ['id'=>'kpi-excedido',     'label'=>'Prazo Excedido',        'icon'=>'bi-alarm-fill',        'color'=>'danger'],
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
                <select id="filtro-status" class="form-select form-select-sm">
                    <option value="">Todos os status</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="filtro-tipo" class="form-select form-select-sm">
                    <option value="">Todos os tipos</option>
                </select>
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

    function populaSelect(id, valores){
        const sel=document.getElementById(id);
        [...new Set(valores)].filter(Boolean).sort().forEach(v=>{
            const o=document.createElement('option'); o.value=v; o.textContent=v; sel.appendChild(o);
        });
    }

    function renderTabela(lista){
        const tbody=document.getElementById('tbody-chamados');
        document.getElementById('lbl-contagem').textContent=lista.length+' registro'+(lista.length!==1?'s':'');
        if(!lista.length){
            tbody.innerHTML='<tr><td colspan="9" class="text-center py-5" style="color:var(--text-muted)">Nenhum chamado encontrado.</td></tr>';
            return;
        }
        lista.sort((a,b)=>{
            let va=a[sortCol]??'', vb=b[sortCol]??'';
            if(typeof va==='number') return sortAsc?va-vb:vb-va;
            return sortAsc?String(va).localeCompare(String(vb),'pt-BR'):String(vb).localeCompare(String(va),'pt-BR');
        });
        tbody.innerHTML=lista.map(r=>`
        <tr>
            <td class="px-4 py-3" style="font-size:.78rem;font-family:monospace;color:var(--text-muted)">#${r.ID}</td>
            <td>
                <div style="font-weight:600;font-size:.88rem">${r.FANTASIA||'—'}</div>
                <div style="font-size:.72rem;color:var(--text-muted)">${r.SERIAL||''}</div>
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
        </tr>`).join('');
    }

    function aplicarFiltros(){
        const busca=document.getElementById('filtro-busca').value.toLowerCase();
        const status=document.getElementById('filtro-status').value;
        const tipo=document.getElementById('filtro-tipo').value;
        const resp=document.getElementById('filtro-responsavel').value;

        const fil=todos.filter(r=>{
            const txt=`${r.FANTASIA} ${r.SERIAL} ${r.DESCRICAO} ${r.RESPONSAVEL} ${r.CHAMADO_USUARIO}`.toLowerCase();
            if(busca&&!txt.includes(busca)) return false;
            if(status&&r.CHAMADO_STATUS!==status) return false;
            if(tipo&&r.TIPOACOMP!==tipo) return false;
            if(resp&&r.RESPONSAVEL!==resp) return false;
            return true;
        });
        renderTabela(fil);
    }

    function atualizarKPIs(lista){
        document.getElementById('kpi-total').textContent=lista.length;
        document.getElementById('kpi-aguard-dev').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Desenvolvimento').length;
        document.getElementById('kpi-customizacao').textContent=lista.filter(r=>(r.TIPOACOMP||'').includes('Customiza')).length;
        document.getElementById('kpi-excedido').textContent=lista.filter(r=>r.EXCEDIDO).length;
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

            atualizarKPIs(lista);
            populaSelect('filtro-status', lista.map(r=>r.CHAMADO_STATUS));
            populaSelect('filtro-tipo',   lista.map(r=>r.TIPOACOMP));
            populaSelect('filtro-responsavel', lista.map(r=>r.RESPONSAVEL));
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

    ['filtro-busca','filtro-status','filtro-tipo','filtro-responsavel'].forEach(id=>{
        document.getElementById(id).addEventListener('input',aplicarFiltros);
        document.getElementById(id).addEventListener('change',aplicarFiltros);
    });

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
