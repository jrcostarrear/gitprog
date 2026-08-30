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
    if ($p === '' || str_starts_with($p, '/') || str_contains($p, "\0")) return false;
    $partes = preg_split('#[\\/]+#', $p) ?: [];
    foreach ($partes as $parte) if ($parte === '..') return false;
    return true;
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

function normalizarNome(string $nome): string {
    $nome = strtolower(trim($nome));
    $nome = preg_replace('/[^a-z0-9_-]+/', '-', $nome) ?? $nome;
    $nome = trim($nome, '-_');
    if (!str_starts_with($nome, 'conector-')) $nome = 'conector-' . $nome;
    return $nome;
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
if ($nome !== '' && $nome !== normalizarNome($nome)) addErro("Nome de diretorio invalido: $nome. Use conector-<nome> com letras minusculas, numeros e hifens.");

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
        addAviso('Manifesto sem testeSandbox; o pacote pode validar localmente, mas nao recebera selo-sandbox automatico no servidor.');
    } elseif (($testeSandbox['permitido'] ?? null) !== true || ($testeSandbox['semEfeitoReal'] ?? null) !== true) {
        addAviso('testeSandbox deve declarar permitido=true e semEfeitoReal=true para habilitar execucao automatica no testesis.');
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
        $handlerRelLimpo = preg_replace('#^\./#', '', $handlerRel) ?? $handlerRel;
        if (!seguroRel($handlerRelLimpo)) addErro('controlador.handlerLerMensagem deve ser caminho relativo seguro.');
        if (!str_starts_with($handlerRelLimpo, "conectores/$nome/handlers/")) addErro('handlerLerMensagem deve ficar dentro de conectores/<nome>/handlers/.');
        $handlerAbs = $baseReal . '/' . $handlerRelLimpo;
        if (!is_file($handlerAbs)) addErro("Handler declarado nao encontrado: $handlerRelLimpo");
        else {
            if (!is_executable($handlerAbs)) addErro("Handler deve estar executavel (chmod +x): $handlerRelLimpo");
            $h = file_get_contents($handlerAbs) ?: '';
            if (trim($h) === '') addErro("Handler vazio: $handlerRelLimpo");
            if (temPlaceholder($h)) addErro("Handler contem placeholders/TODO: $handlerRelLimpo");
            foreach (['espaco/entrada', '../espaco', '/espaco/', 'pp --api', 'shell_exec(', 'system(', 'passthru(', 'eval('] as $termo) {
                if (stripos($h, $termo) !== false) addErro("Handler contem termo proibido ou perigoso '$termo'. Use somente POST HTTP para api.php e evite execucao de shell.");
            }
            if (!preg_match('/payload.*dados|dados.*payload/s', $h)) addAviso('Nao encontrei leitura clara de payload.dados no handler; confirme que a identidade e dados vêm do JSON recebido.');
            addOk("Handler localizado: $handlerRelLimpo");
        }
    }

    $fmtRel = (string)($manifesto['formatoConector'] ?? '');
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
    if (count($arqs) === 0) addErro('dependencias.arquivos deve listar manifesto, formato, handler, catalogo e manual de usuario.');
    $papeis = [];
    foreach ($arqs as $i => $a) {
        if (!is_array($a)) { addErro("dependencias.arquivos[$i] deve ser objeto."); continue; }
        $papel = (string)($a['papel'] ?? '');
        if ($papel) $papeis[$papel] = true;
        foreach (['origem','destino'] as $k) {
            $r = (string)($a[$k] ?? '');
            if ($r === '' || !seguroRel($r)) addErro("dependencias.arquivos[$i].$k invalido/inseguro.");
            if (str_starts_with($r, 'secretos/')) addErro('Nunca inclua secretos reais no pacote; use apenas secretos/*.sample.json.');
        }
        $orig = (string)($a['origem'] ?? '');
        if ($orig && seguroRel($orig) && ($a['obrigatorio'] ?? false) === true && !is_file($baseReal . '/' . $orig)) addErro("Dependencia obrigatoria ausente: $orig");
    }
    foreach (['manifesto','formato','handler','catalogo-mensagens','manual-usuario'] as $p) if (!isset($papeis[$p])) addErro("dependencias.arquivos deve conter papel '$p'.");

    $manualRel = is_string($manifesto['manualUsuario'] ?? null) ? (string)$manifesto['manualUsuario'] : "conectores/$nome/manual-$nome.html";
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
                if (stripos($html, '<html') === false || stripos($html, '</html>') === false) addErro("Manual do usuario deve ser HTML completo com <html>...</html>: $manualRel");
                foreach (['destino', 'payload', 'credenciais', 'erros'] as $termo) {
                    if (stripos($html, $termo) === false) addAviso("Manual do usuario deveria explicar '$termo': $manualRel");
                }
                if (temPlaceholder($html)) addErro("Manual do usuario contem placeholders/TODO: $manualRel");
                addOk("Manual HTML do usuario validado: $manualRel");
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
    }
}

$testePath = $baseReal . '/testes/mensagem-exemplo.json';
$teste = lerJson($testePath);
if ($teste) {
    foreach (['_sistema','_protocolo','payload'] as $c) if (!array_key_exists($c, $teste)) addErro("Mensagem de teste sem campo obrigatorio: $c");
    $dest = $teste['_protocolo']['destino'] ?? '';
    if ($nome && $dest !== "conector__$nome") addErro("Mensagem de teste _protocolo.destino deve ser conector__$nome.");
    if (!isset($teste['payload']['dados']) || !is_array($teste['payload']['dados'])) addErro('Mensagem de teste deve conter payload.dados como objeto.');
    if (temPlaceholder($teste)) addErro('Mensagem de teste contem placeholders; preencha com valores de teste seguros.');
    addOk('Mensagem de teste validada: testes/mensagem-exemplo.json');
}

foreach (listarArquivos($baseReal) as $f) {
    $r = rel($baseReal, $f);
    if (preg_match('#(^|/)secretos/(?!.*\.sample\.json$)#', $r)) addErro("Arquivo proibido no pacote: $r. Envie apenas exemplos .sample.json, nunca segredos reais.");
    if (preg_match('/\.(php|sh|py|js|json|md|html|txt)$/i', $r)) {
        $txt = file_get_contents($f);
        if (is_string($txt) && preg_match('/(AKIA[0-9A-Z]{16}|-----BEGIN (RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----|xox[baprs]-|sk-[A-Za-z0-9]{20,})/', $txt)) {
            addErro("Possivel segredo/token encontrado em $r");
        }
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
