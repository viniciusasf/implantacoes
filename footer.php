</div> <!-- Fim do #content -->
</div> <!-- Fim da .wrapper -->

<footer class="footer mt-auto py-3 bg-white border-top text-center" style="margin-left: var(--sidebar-width);">
    <div class="container">
        <span class="text-muted small">© 2026 Implantação Pro - Gestão Inteligente de Treinamentos</span>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script para toggle da sidebar em dispositivos móveis
    document.addEventListener('DOMContentLoaded', function () {
        // Adicionar botão de toggle se necessário ou outras interações
    });        
</script>

<script>
    document.querySelectorAll('.btn-google-sync').forEach(btn => {
        btn.addEventListener('click', function () {
            const btnElement = this;
            const originalContent = btnElement.innerHTML;

            // Desabilita o botão para evitar cliques duplos
            btnElement.disabled = true;
            btnElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            const formData = new FormData();
            formData.append('sync_google', '1');
            formData.append('cliente', this.dataset.cliente);
            formData.append('tema', this.dataset.tema);
            formData.append('contato', this.dataset.contato);
            formData.append('telefone', this.dataset.telefone);
            formData.append('data', this.dataset.data);

            fetch('google_calendar_sync.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.auth_url) {
                        // Se precisar logar, abre a janela do Google
                        window.location.href = data.auth_url;
                    } else if (data.success) {
                        alert('Evento salvo na Google Agenda com sucesso!');
                        btnElement.classList.replace('btn-outline-danger', 'btn-success');
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Erro na requisição.'))
                .finally(() => {
                    btnElement.disabled = false;
                    btnElement.innerHTML = originalContent;
                });
        });
    });
</script>
</body>

</html>