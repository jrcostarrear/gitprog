#!/usr/bin/env php
<?php
declare(strict_types=1);

function falhar(string $erro, int $codigo = 65): never {
    fwrite(STDERR, "conector-modelo: ERRO: {$erro}\n");
    exit($codigo);
}

function finalizar(array $saida): never {
    fwrite(STDOUT, json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

if ($argc !== 2) {
    falhar('uso: conector-modelo.php <arquivo-mensagem>', 64);
}

$arquivo = $argv[1];
if (!is_file($arquivo) || !is_readable($arquivo)) {
    falhar('arquivo de mensagem nao encontrado ou sem leitura: ' . $arquivo, 66);
}

$conteudo = file_get_contents($arquivo);
if ($conteudo === false || trim($conteudo) === '') {
    falhar('arquivo de mensagem vazio ou ilegivel');
}

try {
    $msg = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    falhar('JSON invalido: ' . $e->getMessage());
}

if (!is_array($msg)) {
    falhar('mensagem deve ser objeto JSON');
}
if (!is_array($msg['_protocolo'] ?? null)) {
    falhar('_protocolo ausente ou invalido');
}
if (!is_array($msg['payload'] ?? null)) {
    falhar('payload ausente ou invalido');
}

$dados = $msg['payload']['dados'] ?? null;
if (!is_array($dados)) {
    falhar('payload.dados ausente ou invalido');
}

$operacao = $dados['operacao'] ?? null;
$texto = $dados['texto'] ?? null;
if ($operacao !== 'eco') {
    falhar('operacao invalida; esperado: eco');
}
if (!is_string($texto) || trim($texto) === '') {
    falhar('campo texto e obrigatorio');
}

finalizar([
    'sucesso' => true,
    'conector' => 'conector-modelo',
    'operacao' => 'eco',
    'texto' => trim($texto),
    'mensagemId' => $msg['_protocolo']['mensagemId'] ?? null,
    'processoId' => $msg['_protocolo']['processoId'] ?? null
]);
