# 📄 Especificação de Desenvolvimento: Sistema de Transcrição para Google Meet

## 1. Visão Geral
Este documento especifica a arquitetura e a implementação da funcionalidade de captura, detecção de voz, transcrição assíncrona e exportação para `.txt` de reuniões do Google Meet agendadas via sistema PHP, integradas ao ecossistema **Antigravit**.

---

## 2. Arquitetura do Sistema

```
┌─────────────────┐       1. INSERT        ┌────────────────────────┐
│  Aplicação PHP  │ ─────────────────────> │      Banco MySQL       │
└─────────────────┘                        └────────────────────────┘
                                                       ▲
                                                       │ 2. Polling (Status: PENDENTE)
                                                       │ 5. UPDATE (Status: CONCLUIDO)
                                                       ▼
                                           ┌────────────────────────┐
                                           │ Worker Python (Cron)   │
                                           └────────────────────────┘
                                                       │
                                  ┌────────────────────┴────────────────────┐
                                  ▼                                         ▼
                     ┌────────────────────────┐               ┌────────────────────────┐
                     │ Captura Áudio / VAD    │               │  Processamento Whisper │
                     └────────────────────────┘               └────────────────────────┘
                                                                            │
                                                                            ▼
                                                              ┌────────────────────────┐
                                                              │ Arq. Transcrição (.TXT)│
                                                              └────────────────────────┘
```

---

## 3. Modelo de Dados (MySQL)

```sql
CREATE TABLE IF NOT EXISTS `reunioes_transcricao` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `meet_link` VARCHAR(255) NOT NULL,
    `data_agendada` DATETIME NOT NULL,
    `duracao_estimada_min` INT DEFAULT 60,
    `status` ENUM('PENDENTE', 'EM_PROCESSAMENTO', 'CONCLUIDO', 'ERRO') DEFAULT 'PENDENTE',
    `caminho_arquivo_txt` VARCHAR(255) NULL,
    `log_erro` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status_data` (`status`, `data_agendada`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Módulos e Componentes

### 4.1. Módulo PHP (Producer)
Responsável por salvar o registro da reunião no banco de dados após a integração com a API do Google Calendar/Meet.

* **Arquivo:** `src/Services/ReuniaoService.php`
* **Ação:** Inserir registro com status `'PENDENTE'`.

### 4.2. Módulo Python Worker (Consumer)
Serviço executado periodicamente via Cron Job no servidor.

* **Arquivo Principal:** `workers/transcription_worker.py`
* **Dependências Python:**
  ```text
  mysql-connector-python
  sounddevice
  numpy
  scipy
  torch
  openai-whisper
  ```

---

## 5. Implementação do Worker (`workers/transcription_worker.py`)

```python
import os
import time
import datetime
import mysql.connector
import whisper

DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'user': os.getenv('DB_USER', 'root'),
    'password': os.getenv('DB_PASS', ''),
    'database': os.getenv('DB_NAME', 'antigravit_db')
}

STORAGE_DIR = os.getenv('TRANSCRIPTION_STORAGE', './storage/transcriptions/')

def get_db_connection():
    return mysql.connector.connect(**DB_CONFIG)

def fetch_pending_meeting():
    conn = get_db_connection()
    cursor = conn.cursor(dictionary=True)
    agora = datetime.datetime.now()
    
    query = """
        SELECT id, meet_link, duracao_estimada_min 
        FROM reunioes_transcricao 
        WHERE status = 'PENDENTE' AND data_agendada <= %s
        ORDER BY data_agendada ASC 
        LIMIT 1
        FOR UPDATE
    """
    cursor.execute(query, (agora,))
    reuniao = cursor.fetchone()
    
    if reuniao:
        cursor.execute(
            "UPDATE reunioes_transcricao SET status = 'EM_PROCESSAMENTO' WHERE id = %s",
            (reuniao['id'],)
        )
        conn.commit()
        
    cursor.close()
    conn.close()
    return reuniao

def process_transcription(reuniao):
    reuniao_id = reuniao['id']
    os.makedirs(STORAGE_DIR, exist_ok=True)
    
    txt_path = os.path.join(STORAGE_DIR, f"transcricao_{reuniao_id}.txt")
    audio_temp = f"/tmp/audio_{reuniao_id}.wav"
    
    try:
        # 1. Executa a gravação/captura de áudio durante o período da reunião
        # Note: Substituir o tempo fixo pela lógica de integração com bot ou captura de áudio da máquina
        print(f"[+] Processando reunião ID: {reuniao_id}")
        
        # 2. Executa transcrição utilizando o Whisper
        model = whisper.load_model("base")
        result = model.transcribe(audio_temp, language="pt")
        
        # 3. Exporta para arquivo TXT
        with open(txt_path, "w", encoding="utf-8") as f:
            f.write(f"=== TRANSCRIÇÃO REUNIÃO ID {reuniao_id} ===\n")
            f.write(f"Data de Processamento: {datetime.datetime.now()}\n\n")
            for segment in result.get("segments", []):
                inicio = f"{int(segment['start'])}s"
                fim = f"{int(segment['end'])}s"
                f.write(f"[{inicio} -> {fim}] {segment['text'].strip()}\n")
                
        # 4. Atualiza banco com o status CONCLUIDO
        update_status(reuniao_id, 'CONCLUIDO', caminho_txt=txt_path)
        
        if os.path.exists(audio_temp):
            os.remove(audio_temp)
            
    except Exception as e:
        update_status(reuniao_id, 'ERRO', erro=str(e))

def update_status(reuniao_id, status, caminho_txt=None, erro=None):
    conn = get_db_connection()
    cursor = conn.cursor()
    if status == 'CONCLUIDO':
        cursor.execute(
            "UPDATE reunioes_transcricao SET status = %s, caminho_arquivo_txt = %s WHERE id = %s",
            (status, caminho_txt, reuniao_id)
        )
    else:
        cursor.execute(
            "UPDATE reunioes_transcricao SET status = %s, log_erro = %s WHERE id = %s",
            (status, erro, reuniao_id)
        )
    conn.commit()
    cursor.close()
    conn.close()

if __name__ == "__main__":
    reuniao = fetch_pending_meeting()
    if reuniao:
        process_transcription(reuniao)
```

---

## 6. Configuração do Cron Job

Adicionar no Crontab do servidor Linux:

```bash
* * * * * cd /var/www/antigravit && /usr/bin/python3 workers/transcription_worker.py >> /var/log/antigravit_transcription.log 2>&1
```

---

## 7. Checklist de Validação

- [ ] Tabela `reunioes_transcricao` criada no banco MySQL.
- [ ] Agendamento em PHP realizando o `INSERT` com status `'PENDENTE'`.
- [ ] Ambiente Python configurado com PyTorch + Whisper e suporte a áudio.
- [ ] Cron Job ativo executando o script sem erros de permissão de escrita no diretório de destino (`STORAGE_DIR`).
- [ ] Arquivo `.txt` gerado com marcação de tempo e codificação UTF-8.