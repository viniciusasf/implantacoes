        </div> <!-- Fecha container-fluid -->
    </div> <!-- Fecha main-content -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Scripts personalizados -->
    <script>
        // Inicializar tooltips e popovers
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        });
    </script>
    
    <?php if (isset($js_extra)) echo $js_extra; ?>
    
    <!-- Script Modo Escuro -->
    <script src="js/dark-mode.js"></script>

    <?php
    // Buscar os treinamentos agendados para hoje para dar contexto à IA
    $contexto_ia = "";
    try {
        if (isset($pdo)) {
            $sql_hoje = "SELECT t.data_treinamento, t.tema, c.fantasia 
                         FROM treinamentos t
                         LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
                         WHERE DATE(t.data_treinamento) = CURDATE() AND UPPER(t.status) = 'PENDENTE'
                         ORDER BY t.data_treinamento ASC";
            $stmt_hoje = $pdo->query($sql_hoje);
            $treinamentos_hoje = $stmt_hoje->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($treinamentos_hoje) > 0) {
                $contexto_ia .= "Treinamentos agendados para HOJE (" . date('d/m/Y') . "):\n";
                foreach ($treinamentos_hoje as $t) {
                    $hora = date('H:i', strtotime($t['data_treinamento']));
                    $tema = $t['tema'] ?? 'Sem tema';
                    $cliente = $t['fantasia'] ?? 'Sem cliente';
                    $contexto_ia .= "- {$hora} | Cliente: {$cliente} | Tema: {$tema}\n";
                }
            } else {
                $contexto_ia .= "Não há treinamentos pendentes agendados para HOJE (" . date('d/m/Y') . ").\n";
            }
        }
    } catch (Exception $e) {
        $contexto_ia .= "Não foi possível carregar a agenda de hoje.\n";
    }
    ?>

    <!-- Puter.js para IA -->
    <script src="https://js.puter.com/v2/"></script>

    <!-- Estilos do Chatbot IA -->
    <style>
        #ai-chat-widget {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        #ai-chat-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            transition: transform 0.3s ease;
        }

        #ai-chat-btn:hover {
            transform: scale(1.05);
        }

        #ai-chat-window {
            display: none;
            position: absolute;
            bottom: 80px;
            right: 0;
            width: 350px;
            height: 500px;
            background: var(--bg-color, #ffffff);
            border: 1px solid var(--border-color, #e0e0e0);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: opacity 0.3s ease;
            opacity: 0;
            pointer-events: none;
        }

        #ai-chat-window.open {
            opacity: 1;
            pointer-events: all;
        }

        #ai-chat-header {
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
            padding: 15px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        #ai-chat-header button {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
        }

        #ai-chat-messages {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: var(--bg-light, #f8f9fa);
        }

        .ai-message {
            max-width: 80%;
            padding: 10px 15px;
            border-radius: 15px;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .ai-message.user {
            background: #6366f1;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 5px;
        }

        .ai-message.bot {
            background: white;
            color: #333;
            border: 1px solid #e0e0e0;
            align-self: flex-start;
            border-bottom-left-radius: 5px;
        }

        .ai-message.system {
            background: none;
            color: #888;
            font-size: 12px;
            text-align: center;
            align-self: center;
            border: none;
        }

        #ai-chat-input-area {
            padding: 15px;
            border-top: 1px solid var(--border-color, #e0e0e0);
            display: flex;
            gap: 10px;
            background: var(--bg-color, #ffffff);
        }

        #ai-chat-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid var(--border-color, #ccc);
            border-radius: 20px;
            outline: none;
            font-size: 14px;
            background: var(--bg-light, #f8f9fa);
            color: var(--text-color, #333);
        }

        #ai-chat-send {
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }
        
        #ai-chat-send:hover {
            background: #4f46e5;
        }

        #ai-chat-send:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* Dark mode compatibility */
        body.dark-mode #ai-chat-window {
            --bg-color: #1e1e2d;
            --bg-light: #151521;
            --border-color: #2b2b40;
            --text-color: #e4e6eb;
        }
        body.dark-mode .ai-message.bot {
            background: #2b2b40;
            color: #e4e6eb;
            border-color: #3f3f5a;
        }
    </style>

    <!-- HTML do Chatbot -->
    <div id="ai-chat-widget">
        <div id="ai-chat-window">
            <div id="ai-chat-header">
                <span>🤖 Assistente de Implantação</span>
                <button id="ai-chat-close">&times;</button>
            </div>
            <div id="ai-chat-messages">
                <div class="ai-message bot">Olá! Sou o assistente de IA do sistema. Como posso ajudar você hoje com dúvidas rápidas ou processos?</div>
            </div>
            <div id="ai-chat-input-area">
                <input type="text" id="ai-chat-input" placeholder="Digite sua pergunta..." autocomplete="off">
                <button id="ai-chat-send">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
        <button id="ai-chat-btn">
            <i class="fas fa-robot"></i>
        </button>
    </div>

    <!-- Script de Lógica do Chatbot -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chatBtn = document.getElementById('ai-chat-btn');
            const chatWindow = document.getElementById('ai-chat-window');
            const chatClose = document.getElementById('ai-chat-close');
            const messagesContainer = document.getElementById('ai-chat-messages');
            const chatInput = document.getElementById('ai-chat-input');
            const sendBtn = document.getElementById('ai-chat-send');

            let isWindowOpen = false;

            // Inicializa escondido, o display block está no CSS, controlamos por classe
            chatWindow.style.display = 'flex';

            // Toggle window
            chatBtn.addEventListener('click', () => {
                isWindowOpen = !isWindowOpen;
                if(isWindowOpen) {
                    chatWindow.classList.add('open');
                    chatInput.focus();
                } else {
                    chatWindow.classList.remove('open');
                }
            });

            chatClose.addEventListener('click', () => {
                isWindowOpen = false;
                chatWindow.classList.remove('open');
            });

            // Handle send
            const sendMessage = () => {
                const text = chatInput.value.trim();
                if(!text) return;

                // Add user message
                appendMessage('user', text);
                chatInput.value = '';
                
                // Disable input while thinking
                chatInput.disabled = true;
                sendBtn.disabled = true;
                
                // Add loading indicator
                const loadingId = 'msg-' + Date.now();
                appendMessage('system', 'A IA está pensando...', loadingId);

                // Buscar contexto dinâmico no banco de dados via AJAX
                fetch('api_chat_contexto.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mensagem: text })
                })
                .then(res => res.json())
                .then(data => {
                    const infoContextoHoje = <?php echo json_encode($contexto_ia); ?>;
                    const infoContextoDinamico = data.contexto || "";
                    
                    const systemPrompt = "Você é um assistente virtual interno para uma equipe de implantação de software. Responda de forma clara, prestativa e em Português do Brasil. Seja objetivo e profissional nas suas respostas relacionadas a treinamentos, processos de implantação e dúvidas gerais da equipe. IMPORTANTE: NÃO mostre o seu processo de pensamento, retorne apenas a resposta final direta ao ponto. Use apenas markdown (**) para formatação, NUNCA use tags HTML explícitas.\n\nInformações em tempo real do sistema:\n" + infoContextoHoje + "\n" + infoContextoDinamico;
                    const fullPrompt = systemPrompt + "\n\nUsuário: " + text;

                    return puter.ai.chat(fullPrompt, { model: "minimax/minimax-m2.5" });
                })
                .then(response => {
                    // Remove loading
                    const loadingEl = document.getElementById(loadingId);
                    if(loadingEl) loadingEl.remove();

                    // Add bot message
                    appendMessage('bot', response.message.content);
                })
                .catch(err => {
                    console.error('Erro na IA ou na API:', err);
                    const loadingEl = document.getElementById(loadingId);
                    if(loadingEl) loadingEl.remove();
                    appendMessage('system', 'Houve um erro ao se comunicar com a IA. Tente novamente mais tarde.');
                })
                .finally(() => {
                    chatInput.disabled = false;
                    sendBtn.disabled = false;
                    chatInput.focus();
                });
            };

            sendBtn.addEventListener('click', sendMessage);
            chatInput.addEventListener('keypress', (e) => {
                if(e.key === 'Enter') sendMessage();
            });

            function appendMessage(role, text, id = null) {
                const msgDiv = document.createElement('div');
                msgDiv.className = `ai-message ${role}`;
                if (id) msgDiv.id = id;
                
                // Remover o bloco de "pensamento" (tags <think>)
                let cleanText = text.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();
                
                // Escapar HTML para evitar que tags como <b> apareçam de forma bruta
                let formattedText = cleanText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                
                // Tratar quebras de linha reais
                formattedText = formattedText.replace(/\n/g, '<br>');
                
                // Formatar negrito markdown **texto**
                formattedText = formattedText.replace(/\*\*(.*?)\*\*/g, '<b>$1</b>');
                
                msgDiv.innerHTML = formattedText;
                
                messagesContainer.appendChild(msgDiv);
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
            
            // Check if FontAwesome is loaded (for icons)
            if(!document.querySelector('link[href*="font-awesome"]')) {
                const faLink = document.createElement('link');
                faLink.rel = 'stylesheet';
                faLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
                document.head.appendChild(faLink);
            }
        });
    </script>
</body>
</html>