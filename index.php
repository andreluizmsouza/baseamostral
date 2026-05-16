<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerador de Base Amostral</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f1117;
            --surface: #1a1d27;
            --surface2: #232733;
            --border: #2e3345;
            --text: #e4e7f1;
            --text-dim: #8a90a5;
            --accent: #4f8cff;
            --accent-dim: #3a6bd4;
            --success: #34d399;
            --error: #f87171;
            --warn: #fbbf24;
            --mono: 'JetBrains Mono', monospace;
            --sans: 'DM Sans', sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--sans);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        .header {
            margin-bottom: 40px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.5px;
        }

        .header p {
            color: var(--text-dim);
            margin-top: 8px;
            font-size: 15px;
        }

        .header-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 16px;
            color: var(--text-dim);
            font-size: 14px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .header-link:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title::before {
            content: '';
            width: 3px;
            height: 16px;
            background: var(--accent);
            border-radius: 2px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-dim);
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            color: var(--text);
            font-family: var(--mono);
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            border-color: var(--accent);
        }

        .form-group input::placeholder {
            color: var(--text-dim);
            opacity: 0.5;
        }

        .file-upload {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-btn {
            background: var(--surface2);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 10px 20px;
            color: var(--text-dim);
            font-family: var(--sans);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            flex: 1;
            text-align: center;
        }

        .file-upload-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .file-upload-btn.has-file {
            border-color: var(--success);
            color: var(--success);
            border-style: solid;
        }

        .tipo-toggle {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .tipo-toggle button {
            flex: 1;
            padding: 12px 20px;
            background: var(--surface2);
            color: var(--text-dim);
            border: none;
            font-family: var(--sans);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tipo-toggle button:not(:last-child) {
            border-right: 1px solid var(--border);
        }

        .tipo-toggle button.active {
            background: var(--accent);
            color: #fff;
        }

        .tipo-toggle button:hover:not(.active) {
            background: var(--border);
            color: var(--text);
        }

        .tipo-desc {
            font-size: 13px;
            color: var(--text-dim);
            margin-bottom: 16px;
            padding: 10px 14px;
            background: var(--surface2);
            border-radius: 8px;
            border-left: 3px solid var(--accent);
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            background: rgba(248, 113, 113, 0.05);
            border: 1px solid rgba(248, 113, 113, 0.15);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .checkbox-group:hover {
            background: rgba(248, 113, 113, 0.1);
            border-color: rgba(248, 113, 113, 0.3);
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--error);
            cursor: pointer;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .checkbox-group .cb-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--error);
        }

        .checkbox-group .cb-desc {
            font-size: 12px;
            color: var(--text-dim);
            margin-top: 2px;
        }

        .btn-execute {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-family: var(--sans);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 8px;
        }

        .btn-execute:hover:not(:disabled) {
            background: var(--accent-dim);
            transform: translateY(-1px);
        }

        .btn-execute:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .progress-section {
            display: none;
        }

        .progress-section.active {
            display: block;
        }

        .progress-bar-container {
            background: var(--surface2);
            border-radius: 6px;
            height: 8px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--success));
            border-radius: 6px;
            width: 0%;
            transition: width 0.3s ease;
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--text-dim);
            margin-bottom: 20px;
        }

        .progress-info .percent {
            font-family: var(--mono);
            color: var(--accent);
            font-weight: 600;
        }

        .log-container {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            max-height: 450px;
            overflow-y: auto;
            font-family: var(--mono);
            font-size: 12.5px;
            line-height: 1.8;
        }

        .log-container::-webkit-scrollbar {
            width: 6px;
        }

        .log-container::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 3px;
        }

        .log-line {
            padding: 1px 0;
        }

        .log-line.info { color: var(--text-dim); }
        .log-line.success { color: var(--success); }
        .log-line.error { color: var(--error); }
        .log-line.warn { color: var(--warn); }

        .log-line .timestamp {
            color: var(--text-dim);
            opacity: 0.5;
            margin-right: 8px;
        }

        .result-banner {
            display: none;
            border-radius: 10px;
            padding: 20px 24px;
            margin-top: 20px;
            font-weight: 600;
            font-size: 15px;
            text-align: center;
        }

        .result-banner.success {
            display: block;
            background: rgba(52, 211, 153, 0.1);
            border: 1px solid rgba(52, 211, 153, 0.3);
            color: var(--success);
        }

        .result-banner.error {
            display: block;
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: var(--error);
        }

        @media (max-width: 640px) {
            .form-grid { grid-template-columns: 1fr; }
            .container { padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>&#9889; Gerador de Base Amostral</h1>
            <p>Extrai dados filtrados por Regi&#227;o/N&#250;cleo/Contrato do SQL Server para um novo banco de dados.</p>
            <a href="documentacao.html" class="header-link">&#128203; Documenta&#231;&#227;o das Tabelas</a>
            <a href="compare.php" class="header-link" style="margin-left: 8px;">&#128270; Comparar Cliente vs SGH</a>
        </div>

        <form id="mainForm" enctype="multipart/form-data">
            <div class="card">
                <div class="card-title">Tipo de Processamento</div>
                <div class="tipo-toggle">
                    <button type="button" data-tipo="cliente" class="active" onclick="setTipo('cliente')">&#128230; Cliente</button>
                    <button type="button" data-tipo="sgh" onclick="setTipo('sgh')">&#127970; SGH Elogica</button>
                </div>
                <div class="tipo-desc" id="tipoDesc">Cliente: tabelas renomeadas com colunas selecionadas, estrutura otimizada para entrega ao cliente.</div>
                <input type="hidden" name="tipo" id="tipoInput" value="cliente">
            </div>

            <div class="card">
                <div class="card-title">Conex&#227;o SQL Server</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Servidor</label>
                        <input type="text" name="servidor" placeholder="localhost\SQLEXPRESS" required>
                    </div>
                    <div class="form-group">
                        <label>CODEMP (Empresa) <span style="font-weight: normal; opacity: 0.7; font-size: 11px;">&mdash; um valor ou lista (ex: 2,5,11)</span></label>
                        <input type="text" name="codemp" placeholder="1 ou 2,5,11,12,21,22,25"
                               pattern="^\s*\d+(\s*,\s*\d+)*\s*$"
                               title="Um numero ou varios separados por virgula. Ex: 5 ou 2,5,11">
                        <small style="display:block; opacity:0.7; font-size:11px; margin-top: 4px;">
                            Opcional se o TXT j&aacute; tiver CODEMP por linha (4 colunas: CODEMP;REGIAO;NUCLEO;CONTRATO).
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Login</label>
                        <input type="text" name="login" placeholder="sa" required>
                    </div>
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="senha" required>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Bancos de Dados</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Banco Origem</label>
                        <input type="text" name="bd_origem" placeholder="COHABMG" required>
                    </div>
                    <div class="form-group">
                        <label>Banco Destino (ser&#225; criado)</label>
                        <input type="text" name="bd_destino" placeholder="FIDC_202504_AMOSTRA" required 
                               pattern="[a-zA-Z0-9_]+" title="Apenas letras, numeros e underscore">
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <label class="checkbox-group" for="chkDropDB">
                        <input type="checkbox" id="chkDropDB" name="drop_destino" value="1">
                        <div>
                            <div class="cb-label">&#9888;&#65039; Apagar e recriar banco destino</div>
                            <div class="cb-desc">Se o banco destino j&#225; existir, ser&#225; EXCLU&#205;DO e recriado do zero. Todos os dados ser&#227;o perdidos.</div>
                        </div>
                    </label>
                </div>
                <div style="margin-top: 12px;">
                    <label class="checkbox-group" for="chkSkipBlobs">
                        <input type="checkbox" id="chkSkipBlobs" name="skip_blobs" value="1">
                        <div>
                            <div class="cb-label">&#128190; Pular dados de tabelas com VARBINARY(MAX)</div>
                            <div class="cb-desc">A estrutura da tabela &#233; criada normalmente, mas os registros n&#227;o s&#227;o copiados (&#250;til para tabelas de PDFs/imagens muito grandes). Aplica-se apenas ao modo SGH El&#243;gica.</div>
                        </div>
                    </label>
                </div>
                <div style="margin-top: 12px;">
                    <label class="checkbox-group" for="chkOnlyFinalizers">
                        <input type="checkbox" id="chkOnlyFinalizers" name="only_finalizers" value="1">
                        <div>
                            <div class="cb-label">&#9203; Modo retomada: s&#243; finalizar (IDENTITY/Constraints/&#205;ndices/Views/Procs)</div>
                            <div class="cb-desc">Pula o processamento de tabelas e roda apenas as fases finais. &#218;til quando uma execu&#231;&#227;o anterior morreu no meio dessas etapas. Aplica-se apenas ao modo SGH El&#243;gica.</div>
                        </div>
                    </label>
                </div>
                <div style="margin-top: 12px;">
                    <label class="checkbox-group" for="chkOnlyExceptions">
                        <input type="checkbox" id="chkOnlyExceptions" name="only_exceptions" value="1">
                        <div>
                            <div class="cb-label">&#128260; Reprocessar apenas exce&#231;&#245;es (SE1/DEP/SE2)</div>
                            <div class="cb-desc">Apaga e recria somente as 3 tabelas de exce&#231;&#227;o, <b>processando empresa por empresa</b> (loop sobre os CODEMPs presentes na CON_FIDC). Cada empresa e isolada do filtro das outras. <b>Cliente:</b> ficha_socio_economica, DEPENDENTES_CLIENTE, CADASTRO_INSCRICOES. <b>SGH:</b> mttbse1, mttbdep, mttbse2. Exige banco destino e CON_FIDC j&#225; existindo. Voc&#234; pode deixar o CODEMP vazio acima (processa todas da CON_FIDC) ou preencher para restringir a uma/algumas empresas.</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Arquivo de Contratos</div>
                <div class="form-group">
                    <label>Arquivo TXT (REGIAO;NUCLEO;CONTRATO)</label>
                    <div class="file-upload">
                        <label class="file-upload-btn" id="fileLabel">
                            &#128196; Clique para selecionar o arquivo .txt
                            <input type="file" name="arquivo_txt" id="fileInput" accept=".txt,.csv">
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-execute" id="btnExecute">
                &#9654; Iniciar Extra&#231;&#227;o
            </button>
        </form>

        <div class="progress-section" id="progressSection">
            <div class="card">
                <div class="card-title">Progresso da Execu&#231;&#227;o</div>
                <div class="progress-bar-container">
                    <div class="progress-bar" id="progressBar"></div>
                </div>
                <div class="progress-info">
                    <span id="progressPhase">Iniciando...</span>
                    <span class="percent" id="progressPercent">0%</span>
                </div>
                <div class="log-container" id="logContainer"></div>
                <div class="result-banner" id="resultBanner"></div>
                <div id="reportArea" style="display:none; margin-top: 16px;">
                    <a id="reportLink" href="#" target="_blank"
                       style="display:inline-block; padding: 12px 20px; background: linear-gradient(90deg,#3b5998,#1a3a5c); color:#fff; text-decoration:none; border-radius: 8px; font-weight: 600;">
                        &#128196; Abrir Relatorio de Extracao (PDF-ready)
                    </a>
                </div>
                <div id="nextRunArea" style="display:none; margin-top: 16px; padding: 16px; background: rgba(255,255,255,0.05); border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                    <div style="font-weight: 600; margin-bottom: 8px;">&#128257; Pr&#243;xima execu&#231;&#227;o</div>
                    <div style="font-size: 13px; opacity: 0.8; margin-bottom: 12px;">
                        Mantenha servidor, login, bancos de origem/destino preenchidos e rode de novo mudando apenas o que precisar.
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" id="btnNovaEmpresa" onclick="prepararNovaExecucao('empresa')"
                                style="padding: 10px 16px; background: rgba(99,102,241,0.2); color: #fff; border: 1px solid rgba(99,102,241,0.5); border-radius: 6px; cursor: pointer;">
                            &#127970; Outra empresa (mesmo arquivo)
                        </button>
                        <button type="button" id="btnNovoArquivo" onclick="prepararNovaExecucao('arquivo')"
                                style="padding: 10px 16px; background: rgba(34,197,94,0.2); color: #fff; border: 1px solid rgba(34,197,94,0.5); border-radius: 6px; cursor: pointer;">
                            &#128196; Novo arquivo de contratos
                        </button>
                        <button type="button" id="btnNovoTudo" onclick="prepararNovaExecucao('ambos')"
                                style="padding: 10px 16px; background: rgba(251,146,60,0.2); color: #fff; border: 1px solid rgba(251,146,60,0.5); border-radius: 6px; cursor: pointer;">
                            &#128260; Trocar empresa + arquivo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        var form = document.getElementById('mainForm');
        var fileInput = document.getElementById('fileInput');
        var fileLabel = document.getElementById('fileLabel');
        var btnExecute = document.getElementById('btnExecute');
        var progressSection = document.getElementById('progressSection');
        var progressBar = document.getElementById('progressBar');
        var progressPhase = document.getElementById('progressPhase');
        var progressPercent = document.getElementById('progressPercent');
        var logContainer = document.getElementById('logContainer');
        var resultBanner = document.getElementById('resultBanner');
        var nextRunArea = document.getElementById('nextRunArea');
        var reportArea = document.getElementById('reportArea');
        var reportLink = document.getElementById('reportLink');

        // ========== PERSISTENCIA DE CAMPOS (localStorage) ==========
        // Senha nunca e salva por seguranca.
        var STORAGE_KEY = 'baseAmostral_v1';
        var camposPersistidos = ['servidor', 'login', 'codemp', 'bd_origem', 'bd_destino'];

        function carregarCampos() {
            try {
                var dados = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                camposPersistidos.forEach(function(nome) {
                    if (dados[nome] !== undefined && form[nome]) {
                        form[nome].value = dados[nome];
                    }
                });
                if (dados.tipo) {
                    setTipo(dados.tipo);
                }
            } catch (e) { /* silencioso */ }
        }

        function salvarCampos() {
            try {
                var dados = {};
                camposPersistidos.forEach(function(nome) {
                    if (form[nome]) dados[nome] = form[nome].value;
                });
                dados.tipo = document.getElementById('tipoInput').value;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(dados));
            } catch (e) { /* silencioso */ }
        }

        // Salvar em toda mudanca de campo persistido
        camposPersistidos.forEach(function(nome) {
            if (form[nome]) {
                form[nome].addEventListener('change', salvarCampos);
                form[nome].addEventListener('blur', salvarCampos);
            }
        });

        // Carregar no inicio
        carregarCampos();

        // ========== MUTUA EXCLUSAO ENTRE FLAGS DE MODO ==========
        // Apagar/Retomada/Excecoes/SkipBlobs tem combinacoes invalidas.
        // Mapa: marcar a flag-chave desmarca as flags listadas como incompativeis.
        (function setupMutexFlags() {
            var incompat = {
                chkDropDB:          ['chkOnlyFinalizers', 'chkOnlyExceptions'],
                chkOnlyFinalizers:  ['chkDropDB', 'chkOnlyExceptions', 'chkSkipBlobs'],
                chkOnlyExceptions:  ['chkDropDB', 'chkOnlyFinalizers', 'chkSkipBlobs'],
                chkSkipBlobs:       ['chkOnlyFinalizers', 'chkOnlyExceptions']
            };
            Object.keys(incompat).forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('change', function () {
                    if (!el.checked) return;
                    incompat[id].forEach(function (otherId) {
                        var other = document.getElementById(otherId);
                        if (other && other.checked) other.checked = false;
                    });
                });
            });
        })();

        function setTipo(tipo) {
            document.getElementById('tipoInput').value = tipo;
            document.querySelectorAll('[data-tipo]').forEach(function(b) {
                b.classList.toggle('active', b.dataset.tipo === tipo);
            });
            var descEl = document.getElementById('tipoDesc');
            if (tipo === 'sgh') {
                descEl.textContent = 'SGH Elogica: mesmas tabelas da origem, filtro dinamico por contrato/empresa. Suporta adicionar empresas.';
            } else {
                descEl.textContent = 'Cliente: tabelas renomeadas com colunas selecionadas, estrutura otimizada para entrega ao cliente.';
            }
        }

        fileInput.addEventListener('change', function() {
            if (fileInput.files.length > 0) {
                fileLabel.textContent = '\u2714 ' + fileInput.files[0].name;
                fileLabel.classList.add('has-file');
            }
        });

        function addLog(level, message) {
            var line = document.createElement('div');
            line.className = 'log-line ' + level;
            var now = new Date().toLocaleTimeString('pt-BR');
            line.innerHTML = '<span class="timestamp">' + now + '</span>' + escapeHtml(message);
            logContainer.appendChild(line);
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            var onlyFinCheck = document.getElementById('chkOnlyFinalizers');
            var isRetomada = onlyFinCheck && onlyFinCheck.checked;
            var onlyExcCheck = document.getElementById('chkOnlyExceptions');
            var isOnlyExc = onlyExcCheck && onlyExcCheck.checked;

            if (!isRetomada && !isOnlyExc && (!fileInput.files || fileInput.files.length === 0)) {
                alert('Selecione o arquivo TXT de contratos.');
                return;
            }

            var dropCheck = document.getElementById('chkDropDB');
            if (dropCheck.checked) {
                var bdDest = form.bd_destino.value;
                if (!confirm('ATENCAO!\n\nO banco [' + bdDest + '] sera EXCLUIDO e todos os dados serao perdidos.\n\nDeseja continuar?')) {
                    return;
                }
            }

            btnExecute.disabled = true;
            btnExecute.textContent = '\u23f3 Processando...';
            progressSection.classList.add('active');
            logContainer.innerHTML = '';
            resultBanner.style.display = 'none';
            resultBanner.className = 'result-banner';
            nextRunArea.style.display = 'none';
            reportArea.style.display = 'none';

            // Persistir campos antes do envio
            salvarCampos();

            var formData = new FormData();
            formData.append('servidor', form.servidor.value);
            formData.append('login', form.login.value);
            formData.append('senha', form.senha.value);
            formData.append('codemp', form.codemp.value);
            formData.append('bd_origem', form.bd_origem.value);
            formData.append('bd_destino', form.bd_destino.value);
            // Arquivo so vai se existir (em modo retomada e opcional)
            if (fileInput.files && fileInput.files.length > 0) {
                formData.append('arquivo_txt', fileInput.files[0]);
            }
            formData.append('drop_destino', dropCheck.checked ? '1' : '0');
            var skipBlobsCheck = document.getElementById('chkSkipBlobs');
            formData.append('skip_blobs', skipBlobsCheck && skipBlobsCheck.checked ? '1' : '0');
            formData.append('only_finalizers', isRetomada ? '1' : '0');
            formData.append('only_exceptions', isOnlyExc ? '1' : '0');
            formData.append('tipo', document.getElementById('tipoInput').value);

            if (fileInput.files && fileInput.files.length > 0) {
                addLog('info', 'Enviando: arquivo=' + fileInput.files[0].name + ' (' + fileInput.files[0].size + ' bytes)');
            } else if (isRetomada) {
                addLog('info', 'Enviando: sem arquivo (modo retomada).');
            }
            if (dropCheck.checked) {
                addLog('warn', 'Opcao APAGAR E RECRIAR banco destino ativada.');
            }
            if (skipBlobsCheck && skipBlobsCheck.checked) {
                addLog('warn', 'Opcao PULAR DADOS de tabelas com VARBINARY(MAX) ativada (so estrutura sera criada).');
            }
            if (isRetomada) {
                addLog('warn', 'Modo RETOMADA ativado: tabelas serao puladas; rodar apenas IDENTITY/Constraints/Indices/Views/Procs.');
            }
            if (isOnlyExc) {
                addLog('warn', 'Modo REPROCESSAR EXCECOES ativado: somente SE1/DEP/SE2 serao recriadas; demais tabelas preservadas.');
            }

            try {
                var response = await fetch('process.php', {
                    method: 'POST',
                    body: formData
                });

                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';

                while (true) {
                    var result = await reader.read();
                    if (result.done) break;

                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        if (lines[i].startsWith('data: ')) {
                            try {
                                var data = JSON.parse(lines[i].substring(6));
                                handleEvent(data);
                            } catch (err) {}
                        }
                    }
                }

                if (buffer.startsWith('data: ')) {
                    try {
                        var data = JSON.parse(buffer.substring(6));
                        handleEvent(data);
                    } catch (err) {}
                }

            } catch (err) {
                addLog('error', 'Erro de conexao: ' + err.message);
                resultBanner.className = 'result-banner error';
                resultBanner.textContent = '\u2717 Erro na comunicacao com o servidor.';
                resultBanner.style.display = 'block';
            }

            btnExecute.disabled = false;
            btnExecute.textContent = '\u25b6 Iniciar Extracao';
        });

        function handleEvent(data) {
            switch (data.type) {
                case 'log':
                    addLog(data.level, data.message);
                    break;
                case 'progress':
                    progressBar.style.width = data.percent + '%';
                    progressPercent.textContent = data.percent + '%';
                    progressPhase.textContent = data.phase;
                    break;
                case 'done':
                    if (data.success) {
                        progressBar.style.width = '100%';
                        progressPercent.textContent = '100%';
                        resultBanner.className = 'result-banner success';
                        resultBanner.textContent = '\u2714 Concluido! ' + data.totalTabelas + ' tabelas criadas no banco [' + data.banco + '].';
                        resultBanner.style.display = 'block';
                        // Link para o relatorio
                        if (data.hasReport) {
                            reportLink.href = 'generate_report.php?banco=' + encodeURIComponent(data.banco);
                            reportArea.style.display = 'block';
                        }
                        // Mostrar opcoes de proxima execucao
                        nextRunArea.style.display = 'block';
                        // Desligar "apagar e recriar" por seguranca apos sucesso
                        var chk = document.getElementById('chkDropDB');
                        if (chk) chk.checked = false;
                    } else {
                        resultBanner.className = 'result-banner error';
                        resultBanner.textContent = '\u2717 Processamento encerrado com erro. Verifique o log acima.';
                        resultBanner.style.display = 'block';
                    }
                    break;
            }
        }

        // ========== PREPARAR PROXIMA EXECUCAO ==========
        // modo: 'empresa' (muda so codemp) | 'arquivo' (muda so arquivo) | 'ambos'
        function prepararNovaExecucao(modo) {
            // Garantir que drop_destino esta desligado (nao queremos apagar o banco ao adicionar outra empresa!)
            var chk = document.getElementById('chkDropDB');
            if (chk) chk.checked = false;

            if (modo === 'empresa' || modo === 'ambos') {
                form.codemp.value = '';
                form.codemp.focus();
            }
            if (modo === 'arquivo' || modo === 'ambos') {
                fileInput.value = '';
                fileLabel.innerHTML = '&#128196; Clique para selecionar o arquivo .txt';
                fileLabel.classList.remove('has-file');
                // input type=file nao pode ser preenchido via JS, entao abrimos o seletor
                if (modo === 'arquivo') fileInput.click();
            }

            // Limpar UI de execucao anterior mas preservar campos de conexao
            logContainer.innerHTML = '';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressPhase.textContent = 'Aguardando...';
            resultBanner.style.display = 'none';
            nextRunArea.style.display = 'none';
            reportArea.style.display = 'none';
            progressSection.classList.remove('active');

            // Scroll ate o topo do form
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    </script>
</body>
</html>
