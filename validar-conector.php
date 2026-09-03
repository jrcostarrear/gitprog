#!/usr/bin/env php
<?php
declare(strict_types=1);

$erros = [];
$avisos = [];
$oks = [];

function addErro(string $m): void { global $erros; $erros[] = $m; }
function addAviso(string $m): void { global $avisos; $avisos[] = $m; }
function addOk(string $m): void { global $oks; $oks[] = $m; }

function rel(string $base, string $path): string {
    $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $path2 = str_replace('\\', '/', realpath($path) ?: $path);
    return str_starts_with($path2, $base) ? substr($path2, strlen($base)) : $path;
}

function lerJson(string $arquivo): ?array {
    if (!is_file($arquivo)) { addErro("Arquivo ausente: $arquivo"); return null; }
    if (!is_readable($arquivo)) { addErro("Arquivo sem leitura: $arquivo"); return null; }
    $txt = file_get_contents($arquivo);
    if ($txt === false || trim($txt) === '') { addErro("Arquivo vazio/ilegivel: $arquivo"); return null; }
    try { $j = json_decode($txt, true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { addErro("JSON invalido em $arquivo: " . $e->getMessage()); return null; }
    if (!is_array($j)) { addErro("JSON deve ser objeto/array em $arquivo"); return null; }
    return $j;
}

function seguroRel(string $p): bool {
    if ($p === '' || str_starts_with($p, '/') || str_contains($p, "\0") || str_contains($p, '\\')) return false;
    $partes = explode('/', $p);
    foreach ($partes as $parte) {
        if ($parte === '' || $parte === '.' || $parte === '..') return false;
        if (preg_match('/[\x00-\x1F]/', $parte) === 1) return false;
    }
    return true;
}

function limparRelDeclarado(string $p): string {
    while (str_starts_with($p, './')) $p = substr($p, 2);
    return $p;
}

function nomeConectorValido(string $nome): bool {
    return preg_match('/^conector-[a-z0-9][a-z0-9-]*$/', $nome) === 1;
}

function temPlaceholder(mixed $v): bool {
    if (is_string($v)) return preg_match('/PREENCHER|TODO|EXEMPLO_INVALIDO|VALOR_AJUSTAR/', $v) === 1;
    if (is_array($v)) foreach ($v as $x) if (temPlaceholder($x)) return true;
    return false;
}

function listarArquivos(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out[] = $f->getPathname();
    sort($out);
    return $out;
}

function validarEntradasSemLinks(string $baseReal): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $path = $f->getPathname();
        $r = rel($baseReal, $path);
        if ($f->isLink()) addErro("Link simbolico proibido no pacote: $r");
        elseif (!$f->isDir() && !$f->isFile()) addErro("Entrada nao regular proibida no pacote: $r");
    }
}

function normalizarNome(string $nome): string {
    $nome = strtolower(trim($nome));
    $nome = preg_replace('/[^a-z0-9_-]+/', '-', $nome) ?? $nome;
    $nome = trim($nome, '-_');
    if (!str_starts_with($nome, 'conector-')) $nome = 'conector-' . $nome;
    return $nome;
}

function validarExemploUso(string $baseReal, string $nome, string $catalogoTexto): void {
    global $erros;
    $errosAntes = count($erros);
    $rel = "conectores/$nome/exemplos/exemplo-uso.php";
    $abs = "$baseReal/$rel";
    if (!is_file($abs)) { addErro("Programa de exemplo obrigatório ausente: $rel"); return; }
    if (!is_readable($abs)) { addErro("Programa de exemplo sem leitura: $rel"); return; }
    $txt = file_get_contents($abs);
    if (!is_string($txt) || trim($txt) === '') { addErro("Programa de exemplo vazio: $rel"); return; }
    if (!str_starts_with($txt, "#!") && stripos($txt, '<?php') === false) addErro("Programa de exemplo PHP deve ter shebang ou bloco <?php: $rel");
    if (temPlaceholder($txt)) addErro("Programa de exemplo contem placeholders/TODO: $rel");
    if (stripos($txt, 'payload.dados') === false && stripos($txt, "'dados'") === false && stripos($txt, '"dados"') === false) addErro("Programa de exemplo deve montar payload/dados do conector: $rel");
    if (stripos($txt, 'idmensagem') === false) addErro("Programa de exemplo deve demonstrar idmensagem do catalogo: $rel");
    if (stripos($txt, $nome) === false) addErro("Programa de exemplo deve referenciar o conector $nome: $rel");

    $cmdLint = 'php -n -l ' . escapeshellarg($abs) . ' 2>&1';
    exec($cmdLint, $lintOut, $lintRc);
    if ($lintRc !== 0) addErro("Programa de exemplo PHP com erro de sintaxe: $rel: " . implode(' ', $lintOut));

    $cmdRun = 'php -n ' . escapeshellarg($abs) . ' --self-test 2>&1';
    exec($cmdRun, $runOut, $runRc);
    $saida = trim(implode("\n", $runOut));
    if ($runRc !== 0) { addErro("Programa de exemplo falhou no --self-test: $rel: $saida"); return; }
    try { $json = json_decode($saida, true, 128, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { addErro("Programa de exemplo --self-test deve retornar JSON valido: $rel: " . $e->getMessage()); return; }
    if (!is_array($json)) { addErro("Programa de exemplo --self-test deve retornar objeto JSON: $rel"); return; }
    if (($json['sucesso'] ?? null) !== true) addErro("Programa de exemplo --self-test deve retornar sucesso=true: $rel");
    if (($json['conector'] ?? null) !== $nome) addErro("Programa de exemplo --self-test deve retornar conector=$nome: $rel");
    $idm = $json['idmensagem'] ?? '';
    if (!is_string($idm) || $idm === '') addErro("Programa de exemplo --self-test deve retornar idmensagem: $rel");
    elseif (stripos($catalogoTexto, $idm) === false) addErro("Programa de exemplo retornou idmensagem não presente no catalogo: $idm");
    if (!isset($json['payload']['dados']) || !is_array($json['payload']['dados'])) addErro("Programa de exemplo --self-test deve retornar payload.dados de exemplo: $rel");
    if (count($erros) === $errosAntes) addOk("Programa de exemplo validado: $rel");
}

function validarExemploCliente(string $baseReal, string $nome, string $catalogoTexto): void {
    global $erros;
    $errosAntes = count($erros);
    $rel = "conectores/$nome/exemplos/exemplo-cliente.php";
    $abs = "$baseReal/$rel";
    if (!is_file($abs)) { addErro("Programa consumidor obrigatório ausente: $rel"); return; }
    if (!is_readable($abs)) { addErro("Programa consumidor sem leitura: $rel"); return; }
    $txt = file_get_contents($abs);
    if (!is_string($txt) || trim($txt) === '') { addErro("Programa consumidor vazio: $rel"); return; }
    if (!str_starts_with($txt, "#!") && stripos($txt, '<?php') === false) addErro("Programa consumidor PHP deve ter shebang ou bloco <?php: $rel");
    if (temPlaceholder($txt)) addErro("Programa consumidor contem placeholders/TODO: $rel");
    if (stripos($txt, $nome) === false) addErro("Programa consumidor deve referenciar o conector $nome: $rel");
    if (stripos($txt, 'idmensagem') === false) addErro("Programa consumidor deve demonstrar idmensagem do catalogo: $rel");
    if (stripos($txt, 'http') === false && stripos($txt, 'api') === false) addErro("Programa consumidor deve consumir a API HTTP publica do SISC: $rel");
    if (stripos($txt, 'curl_') === false && stripos($txt, 'file_get_contents') === false && stripos($txt, 'stream_context_create') === false && stripos($txt, '->enviar(') === false) addErro("Programa consumidor deve demonstrar chamada HTTP/API real: $rel");
    foreach (['handlers/', '/handlers/', 'espaco/', '/espaco', '/var/www/html'] as $termo) {
        if (stripos($txt, $termo) !== false) addErro("Programa consumidor nao deve acessar/revelar caminho interno '$termo'; consuma somente via API SISC: $rel");
    }

    $cmdLint = 'php -n -l ' . escapeshellarg($abs) . ' 2>&1';
    exec($cmdLint, $lintOut, $lintRc);
    if ($lintRc !== 0) addErro("Programa consumidor PHP com erro de sintaxe: $rel: " . implode(' ', $lintOut));

    $cmdRun = 'php -n ' . escapeshellarg($abs) . ' --self-test 2>&1';
    exec($cmdRun, $runOut, $runRc);
    $saida = trim(implode("\n", $runOut));
    if ($runRc !== 0) { addErro("Programa consumidor falhou no --self-test: $rel: $saida"); return; }
    try { $json = json_decode($saida, true, 128, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { addErro("Programa consumidor --self-test deve retornar JSON valido: $rel: " . $e->getMessage()); return; }
    if (!is_array($json)) { addErro("Programa consumidor --self-test deve retornar objeto JSON: $rel"); return; }
    if (($json['sucesso'] ?? null) !== true) addErro("Programa consumidor --self-test deve retornar sucesso=true: $rel");
    if (($json['conector'] ?? null) !== $nome) addErro("Programa consumidor --self-test deve retornar conector=$nome: $rel");
    $idm = $json['idmensagem'] ?? '';
    if (!is_string($idm) || $idm === '') addErro("Programa consumidor --self-test deve retornar idmensagem: $rel");
    elseif (stripos($catalogoTexto, $idm) === false) addErro("Programa consumidor retornou idmensagem não presente no catalogo: $idm");
    if (!isset($json['payload']['dados']) && !isset($json['dados'])) addErro("Programa consumidor --self-test deve retornar dados de exemplo: $rel");
    if (count($erros) === $errosAntes) addOk("Programa consumidor validado: $rel");
}

function validarQualidadeManualUsuario(string $html, string $manualRel, string $nome): void {
    $texto = trim(strip_tags($html));
    $minBytes = 6000;
    if (strlen($html) < $minBytes) addErro("Manual HTML do usuario curto demais: $manualRel. Use o padrao de qualidade do conector-email, com identificacao, tabelas, exemplos, saida, credenciais, erros, limites, seguranca de uso e boas praticas.");
    if (stripos($html, '<html') === false || stripos($html, '</html>') === false) addErro("Manual do usuario deve ser HTML completo com <html>...</html>: $manualRel");
    if (stripos($html, '<style') === false) addErro("Manual do usuario deve conter CSS proprio de leitura, no padrao visual do manual do conector-email: $manualRel");
    if (substr_count(strtolower($html), '<table') < 2) addErro("Manual do usuario deve usar tabelas para operacoes/campos/erros, como o conector-email: $manualRel");
    if (substr_count(strtolower($html), '<pre') < 3) addErro("Manual do usuario deve conter exemplos completos em blocos <pre><code>: $manualRel");
    if (stripos($html, $nome) === false || stripos($html, "conector__$nome") === false) addErro("Manual do usuario deve identificar conector e destino SISC conector__$nome: $manualRel");

    $secoes = [
        'Identificação', 'Objetivo', 'Operações', 'Payload de entrada', 'Exemplo',
        'Saída esperada', 'Programa de exemplo', 'Credenciais', 'Erros comuns', 'Limites', 'Segurança de uso', 'Boas práticas'
    ];
    foreach ($secoes as $secao) {
        if (stripos($html, $secao) === false) addErro("Manual do usuario sem seção obrigatoria '$secao': $manualRel");
    }
    foreach (['payload.dados', 'idmensagem', 'catalogo', 'destino SISC', 'idempotencia'] as $termo) {
        if (stripos($html, $termo) === false) addErro("Manual do usuario deve explicar '$termo': $manualRel");
    }
    foreach (['sandbox-handler', 'validar-recebidos', 'testar-sandbox', 'instalar-aprovados', '/var/www/html/gitconectores'] as $termoInterno) {
        if (stripos($html, $termoInterno) !== false) addErro("Manual do usuario nao deve citar detalhe operacional interno do servidor SISC ('$termoInterno'); ele deve documentar apenas o conector, seu catalogo e suas mensagens: $manualRel");
    }
    if (strlen($texto) < 1700) addErro("Manual do usuario tem pouco conteúdo textual util: $manualRel");
    if (temPlaceholder($html)) addErro("Manual do usuario contem placeholders/TODO: $manualRel");
}

$base = $argv[1] ?? getcwd();
$base = rtrim($base, '/');
if (!is_dir($base)) {
    fwrite(STDERR, "Uso: ./validar-conector [diretorio-do-projeto]\nDiretorio invalido: $base\n");
    exit(2);
}
$baseReal = realpath($base) ?: $base;

$conectoresDir = $baseReal . '/conectores';
if (!is_dir($conectoresDir)) addErro('Diretorio obrigatorio ausente: conectores/');

$dirs = [];
if (is_dir($conectoresDir)) {
    foreach (glob($conectoresDir . '/*', GLOB_ONLYDIR) ?: [] as $d) $dirs[] = basename($d);
}
if (count($dirs) === 0) addErro('Nenhum conector encontrado em conectores/<nome>.');
if (count($dirs) > 1) addErro('A submissao deve conter apenas um conector por pacote. Encontrados: ' . implode(', ', $dirs));

$nome = $dirs[0] ?? '';
if ($nome !== '' && (!nomeConectorValido($nome) || $nome !== normalizarNome($nome))) {
    addErro("Nome de diretorio invalido: $nome. Use conector-<nome> somente com letras minusculas, numeros e hifens; underscore nao e aceito.");
}
validarEntradasSemLinks($baseReal);

$conectorBase = $nome ? "$conectoresDir/$nome" : '';
$manifestoPath = $nome ? "$conectorBase/$nome.json" : '';
$manifesto = $manifestoPath ? lerJson($manifestoPath) : null;

if ($manifesto) {
    if (($manifesto['nome'] ?? '') !== $nome) addErro('manifesto.nome deve ser exatamente igual ao diretorio: ' . $nome);
    foreach (['titulo','descricao','versao','tipo','controlador','formatoConector','dependencias'] as $c) {
        if (!array_key_exists($c, $manifesto)) addErro("Campo obrigatorio ausente no manifesto: $c");
    }
    if (($manifesto['tipo'] ?? '') !== 'conector') addErro('manifesto.tipo deve ser "conector".');
    if (!preg_match('/^\d+\.\d+\.\d+$/', (string)($manifesto['versao'] ?? ''))) addErro('manifesto.versao deve seguir semver simples: 1.0.0');
    if (!is_bool($manifesto['ativo'] ?? null)) addErro('manifesto.ativo deve ser booleano true/false.');
    if (temPlaceholder($manifesto)) addErro('Manifesto contem placeholders (PREENCHER/TODO/<...>). Preencha antes de submeter.');

    $testeSandbox = is_array($manifesto['testeSandbox'] ?? null) ? $manifesto['testeSandbox'] : null;
    if (!$testeSandbox) {
        addErro('Manifesto sem testeSandbox; o servidor nao emitira selo-sandbox e o SISC real recusara a instalacao.');
    } elseif (($testeSandbox['permitido'] ?? null) !== true || ($testeSandbox['semEfeitoReal'] ?? null) !== true) {
        addErro('testeSandbox deve declarar permitido=true e semEfeitoReal=true para habilitar selo-sandbox no testesis.');
    } elseif (!is_string($testeSandbox['descricao'] ?? null) || trim((string)$testeSandbox['descricao']) === '') {
        addErro('testeSandbox.descricao deve justificar por que a mensagem de teste nao causa efeito real.');
    }

    $ctrl = is_array($manifesto['controlador'] ?? null) ? $manifesto['controlador'] : [];
    $checks = [
        'executavel' => './escuta/runtime-conector',
        'metodoLeitura' => 'ler-mensagem',
        'metodoEnvio' => 'POST api.php',
    ];
    foreach ($checks as $k => $v) if (($ctrl[$k] ?? null) !== $v) addErro("controlador.$k deve ser '$v'.");
    $handlerRel = (string)($ctrl['handlerLerMensagem'] ?? '');
    if ($handlerRel === '') addErro('controlador.handlerLerMensagem e obrigatorio.');
    else {
        $handlerRelLimpo = limparRelDeclarado($handlerRel);
        if (!seguroRel($handlerRelLimpo)) addErro('controlador.handlerLerMensagem deve ser caminho relativo seguro, sem / inicial, .., ., //, barra invertida ou controles.');
        if (!str_starts_with($handlerRelLimpo, "conectores/$nome/handlers/")) addErro('handlerLerMensagem deve ficar dentro de conectores/<nome>/handlers/.');
        $handlerAbs = $baseReal . '/' . $handlerRelLimpo;
        if (!is_file($handlerAbs)) addErro("Handler declarado nao encontrado: $handlerRelLimpo");
        else {
            if (!is_executable($handlerAbs)) addErro("Handler deve estar executavel (chmod +x): $handlerRelLimpo");
            $h = file_get_contents($handlerAbs) ?: '';
            if (trim($h) === '') addErro("Handler vazio: $handlerRelLimpo");
            if (!str_starts_with($h, "#!") && substr($h, 0, 4) !== "\x7FELF") addErro("Handler deve ser executavel direto: script com shebang na primeira linha ou binario ELF: $handlerRelLimpo");
            if (temPlaceholder($h)) addErro("Handler contem placeholders/TODO: $handlerRelLimpo");
            foreach (['espaco/', '/espaco', '../espaco', 'pp --api', 'shell_exec(', 'system(', 'passthru(', 'eval(', 'proc_open(', 'popen(', 'os.system', 'subprocess.', 'child_process', 'ProcessBuilder', 'Runtime.getRuntime'] as $termo) {
                if (stripos($h, $termo) !== false) addErro("Handler contem termo proibido ou perigoso '$termo'. Use somente POST HTTP para api.php e evite execucao de shell/acesso direto ao espaco.");
            }
            if (stripos($h, 'secretos/') !== false && stripos($h, "secretos/$nome.json") === false) addAviso("Handler referencia secretos/; no runtime novo somente secretos/$nome.json fica visivel no sandbox.");
            if (!preg_match('/payload.*dados|dados.*payload/s', $h)) addAviso('Nao encontrei leitura clara de payload.dados no handler; confirme que a identidade e dados vêm do JSON recebido.');
            addOk("Handler localizado: $handlerRelLimpo");
        }
    }

    $fmtRel = limparRelDeclarado((string)($manifesto['formatoConector'] ?? ''));
    if ($fmtRel === '' || !seguroRel($fmtRel)) addErro('formatoConector deve ser caminho relativo seguro.');
    else {
        $fmtAbs = $baseReal . '/' . $fmtRel;
        $fmt = lerJson($fmtAbs);
        if ($fmt) {
            if (($fmt['tipo'] ?? '') !== 'formato-conector') addErro('Formato: campo tipo deve ser formato-conector.');
            if (($fmt['conector'] ?? '') !== $nome) addErro('Formato: campo conector deve apontar para ' . $nome);
            if (!isset($fmt['entrada']) || !isset($fmt['saida'])) addErro('Formato deve declarar entrada e saida.');
            if (temPlaceholder($fmt)) addErro('Formato contem placeholders/TODO; preencha o contrato real.');
            addOk("Formato validado: $fmtRel");
        }
    }

    $deps = is_array($manifesto['dependencias'] ?? null) ? $manifesto['dependencias'] : [];
    if (($deps['fonteSeguraImportacao'] ?? null) !== true) addErro('dependencias.fonteSeguraImportacao deve ser true.');
    $arqs = is_array($deps['arquivos'] ?? null) ? $deps['arquivos'] : [];
    if (count($arqs) === 0) addErro('dependencias.arquivos deve listar manifesto, formato, handler, catalogo, manual de usuario e exemplos.');
    $papeis = [];
    foreach ($arqs as $i => $a) {
        if (!is_array($a)) { addErro("dependencias.arquivos[$i] deve ser objeto."); continue; }
        $papel = (string)($a['papel'] ?? '');
        if ($papel) $papeis[$papel] = true;
        foreach (['origem','destino'] as $k) {
            $r = (string)($a[$k] ?? '');
            $r = limparRelDeclarado($r);
            if ($r === '' || !seguroRel($r)) addErro("dependencias.arquivos[$i].$k invalido/inseguro.");
            if (str_starts_with($r, 'secretos/')) addErro('Nunca inclua secretos reais em dependencias.arquivos; use apenas secretos/*.sample.json fora das dependencias de instalacao.');
            if ($k === 'destino' && !str_starts_with($r, "conectores/$nome/") && !str_starts_with($r, 'web-api/')) addErro("dependencias.arquivos[$i].destino fora das areas instalaveis permitidas: conectores/$nome/ ou web-api/.");
        }
        $orig = limparRelDeclarado((string)($a['origem'] ?? ''));
        if ($orig && seguroRel($orig) && ($a['obrigatorio'] ?? false) === true && !is_file($baseReal . '/' . $orig)) addErro("Dependencia obrigatoria ausente: $orig");
    }
    foreach (['manifesto','formato','handler','catalogo-mensagens','manual-usuario','exemplo-uso','exemplo-consumidor'] as $p) if (!isset($papeis[$p])) addErro("dependencias.arquivos deve conter papel '$p'.");

    $manualRel = is_string($manifesto['manualUsuario'] ?? null) ? limparRelDeclarado((string)$manifesto['manualUsuario']) : "conectores/$nome/manual-$nome.html";
    if (!seguroRel($manualRel)) {
        addErro('manualUsuario deve ser caminho relativo seguro.');
    } else {
        if ($manualRel !== "conectores/$nome/manual-$nome.html") addAviso("Caminho recomendado para manual do usuario: conectores/$nome/manual-$nome.html");
        $manualAbs = $baseReal . '/' . $manualRel;
        if (!is_file($manualAbs)) {
            addErro("Manual HTML do usuario ausente: $manualRel");
        } else {
            $html = file_get_contents($manualAbs);
            if (!is_string($html) || trim($html) === '') addErro("Manual HTML do usuario vazio: $manualRel");
            else {
                validarQualidadeManualUsuario($html, $manualRel, $nome);
                addOk("Manual HTML do usuario validado com padrao completo: $manualRel");
            }
        }
    }
}

if ($nome) {
    $catalogoRel = "web-api/catalogo-$nome.json";
    $catalogo = lerJson($baseReal . '/' . $catalogoRel);
    if ($catalogo) {
        if (($catalogo['tipo'] ?? '') !== 'catalogo-modulo-mensagens-sisc') addErro('Catalogo: tipo deve ser catalogo-modulo-mensagens-sisc.');
        if (($catalogo['destino'] ?? '') !== "conector__$nome") addErro("Catalogo: destino deve ser conector__$nome.");
        $msgs = is_array($catalogo['mensagens'] ?? null) ? $catalogo['mensagens'] : [];
        if (count($msgs) === 0) addErro('Catalogo deve conter ao menos uma mensagem.');
        $ativas = 0;
        foreach ($msgs as $i => $m) {
            if (!is_array($m)) { addErro("catalogo.mensagens[$i] deve ser objeto."); continue; }
            foreach (['idmensagem','destino','tipoMensagem','ativo','publica'] as $c) if (!array_key_exists($c, $m)) addErro("catalogo.mensagens[$i] sem campo $c.");
            if (($m['destino'] ?? '') !== "conector__$nome") addErro("catalogo.mensagens[$i].destino deve ser conector__$nome.");
            if (($m['idempotenciaObrigatoria'] ?? null) !== true) addErro("catalogo.mensagens[$i].idempotenciaObrigatoria deve ser true.");
            if (($m['correlacaoObrigatoria'] ?? null) !== true) addErro("catalogo.mensagens[$i].correlacaoObrigatoria deve ser true.");
            if (($m['ativo'] ?? false) === true) $ativas++;

            $frontDefs = $m['front-api'] ?? null;
            if ($frontDefs !== null) {
                if (is_array($frontDefs) && array_is_list($frontDefs)) $listaFront = $frontDefs;
                elseif (is_array($frontDefs)) $listaFront = [$frontDefs];
                else { addErro("catalogo.mensagens[$i].front-api deve ser objeto ou lista."); $listaFront = []; }
                foreach ($listaFront as $j => $front) {
                    if (!is_array($front)) { addErro("catalogo.mensagens[$i].front-api[$j] deve ser objeto."); continue; }
                    $respostaFront = is_array($front['resposta'] ?? null) ? $front['resposta'] : [];
                    $alvoVisual = $respostaFront['atualizar'] ?? null;
                    if ($alvoVisual !== $nome) addErro("front-api em catalogo.mensagens[$i][$j] deve declarar resposta.atualizar exatamente igual ao nome do conector: $nome.");
                    if (($front['ativo'] ?? false) !== true) continue;
                    $acaoFront = $front['acao'] ?? '';
                    if (!is_string($acaoFront) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $acaoFront)) addErro("front-api ativo em catalogo.mensagens[$i][$j] precisa de acao valida.");
                    if (strtoupper((string)($front['metodo'] ?? '')) !== 'POST') addErro("front-api ativo em catalogo.mensagens[$i][$j] deve usar metodo POST.");
                    if (($front['csrf'] ?? null) !== true) addErro("front-api ativo em catalogo.mensagens[$i][$j] deve declarar csrf=true.");
                    if (!is_string($front['autenticacao'] ?? null) || trim((string)$front['autenticacao']) === '') addErro("front-api ativo em catalogo.mensagens[$i][$j] deve declarar autenticacao.");
                    if (!isset($front['entrada']) || !is_array($front['entrada'])) addErro("front-api ativo em catalogo.mensagens[$i][$j] deve declarar entrada.");
                    if (!array_key_exists('dadosSisc', $front)) addErro("front-api ativo em catalogo.mensagens[$i][$j] deve declarar dadosSisc.");
                    if (!isset($front['resposta']) || !is_array($front['resposta'])) addAviso("front-api ativo em catalogo.mensagens[$i][$j] deveria declarar resposta.");
                }
            }
        }
        if ($ativas === 0) addErro('Catalogo nao possui nenhuma mensagem ativa; ative pelo menos uma mensagem antes da submissao.');
        if (temPlaceholder($catalogo)) addErro('Catalogo contem placeholders/TODO; preencha antes de submeter.');
        addOk("Catalogo validado: $catalogoRel");
        $catalogoTexto = file_get_contents($baseReal . '/' . $catalogoRel);
        validarExemploUso($baseReal, $nome, is_string($catalogoTexto) ? $catalogoTexto : '');
        validarExemploCliente($baseReal, $nome, is_string($catalogoTexto) ? $catalogoTexto : '');
    }
}

$testePath = $baseReal . '/testes/mensagem-exemplo.json';
$teste = lerJson($testePath);
if ($teste) {
    foreach (['_sistema','_protocolo','payload'] as $c) if (!array_key_exists($c, $teste)) addErro("Mensagem de teste sem campo obrigatorio: $c");
    $dest = $teste['_protocolo']['destino'] ?? '';
    if ($nome && $dest !== "conector__$nome") addErro("Mensagem de teste _protocolo.destino deve ser conector__$nome.");
    if (!isset($teste['payload']['dados']) || !is_array($teste['payload']['dados'])) addErro('Mensagem de teste deve conter payload.dados como objeto.');

    $proto = is_array($teste['_protocolo'] ?? null) ? $teste['_protocolo'] : [];
    if (($proto['nome'] ?? null) !== 'siscore-protocolo-objetos') addErro('Mensagem de teste: _protocolo.nome deve ser "siscore-protocolo-objetos".');
    if (($proto['versao'] ?? null) !== 1) addErro('Mensagem de teste: _protocolo.versao deve ser 1.');
    $tiposValidos = ['solicitacao', 'resposta', 'evento', 'erro', 'comando', 'consulta'];
    if (!in_array($proto['tipo'] ?? null, $tiposValidos, true)) addErro('Mensagem de teste: _protocolo.tipo invalido.');
    $prio = $proto['prioridade'] ?? null;
    if ($prio !== null && $prio !== '' && !in_array($prio, ['baixa', 'normal', 'alta', 'critica'], true)) addErro('Mensagem de teste: _protocolo.prioridade invalida.');
    $idm = $teste['payload']['idmensagem'] ?? null;
    if (!is_string($idm) || $idm === '') addErro('Mensagem de teste: payload.idmensagem deve ser informado.');

    if (temPlaceholder($teste)) addErro('Mensagem de teste contem placeholders; preencha com valores de teste seguros.');
    addOk('Mensagem de teste validada: testes/mensagem-exemplo.json');
}

foreach (listarArquivos($baseReal) as $f) {
    $r = rel($baseReal, $f);
    if (preg_match('#(^|/)secretos/(?!.*\.sample\.json$)#', $r)) addErro("Arquivo proibido no pacote: $r. Envie apenas exemplos .sample.json, nunca segredos reais.");
    if (preg_match('#(^|/)token-externo/.*\.txt$#', $r)) addErro("Arquivo proibido no pacote: $r. Tokens de cliente devem ficar somente no ambiente local/servidor e nunca no GitHub ou pacote.");
    if (preg_match('/\.(php|sh|py|js|json|md|html|txt)$/i', $r)) {
        $txt = file_get_contents($f);
        if (is_string($txt) && preg_match('/(AKIA[0-9A-Z]{16}|-----BEGIN (RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----|xox[baprs]-|sk-[A-Za-z0-9]{20,})/', $txt)) {
            addErro("Possivel segredo/token encontrado em $r");
        }
    }
}

$cabsiscPath = $baseReal . '/cabsisc.h';
if (is_file($cabsiscPath)) {
    $cabsiscTxt = file_get_contents($cabsiscPath);
    if (is_string($cabsiscTxt) && preg_match('/new\s+sisc\s*\(\s*[\'\"]siscore[\'\"]/', $cabsiscTxt) === 1) {
        addErro('cabsisc.h nao deve apontar para siscore no kit de homologacao; use testesis. A instalacao em siscore e feita somente pelo servidor apos selos validos.');
    } elseif (is_string($cabsiscTxt) && preg_match('/new\s+sisc\s*\(\s*[\'\"]testesis[\'\"]/', $cabsiscTxt) === 1) {
        addOk('cabsisc.h configurado para testesis.');
    } else {
        addAviso('cabsisc.h encontrado, mas sem configuracao new sisc("testesis", ... ) claramente identificada.');
    }
}

if (is_file($baseReal . '/README.md')) addOk('README.md encontrado.'); else addAviso('Recomenda-se incluir README.md com objetivo, instalacao e operacoes do conector.');
if (is_file($baseReal . '/manual-conector.html')) addOk('manual-conector.html encontrado.'); else addAviso('manual-conector.html nao encontrado na raiz do pacote.');

printf("\nVALIDACAO LOCAL DO CONECTOR SISC\n");
printf("Diretorio: %s\n", $baseReal);
printf("Conector: %s\n\n", $nome ?: '(nao identificado)');

foreach ($oks as $m) echo "[OK] $m\n";
foreach ($avisos as $m) echo "[AVISO] $m\n";
foreach ($erros as $m) echo "[ERRO] $m\n";

printf("\nResumo: %d OK, %d aviso(s), %d erro(s).\n", count($oks), count($avisos), count($erros));
if (count($erros) > 0) {
    echo "Status: REPROVADO. Corrija os erros antes de compactar e enviar.\n";
    exit(1);
}
echo "Status: APROVADO PARA EMPACOTAMENTO LOCAL.\n";
exit(0);
