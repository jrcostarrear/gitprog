#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../sisc-api-cliente.php';

$login = getenv('SISC_LOGIN') ?: ($argv[1] ?? 'programador-login');
$idmensagem = getenv('SISC_IDMENSAGEM') ?: ($argv[2] ?? 'conector-modelo.eco');
$operacao = getenv('SISC_OPERACAO') ?: ($argv[3] ?? 'eco');

if ($login === 'programador-login') {
    fwrite(STDERR, "Informe o login recebido: php exemplos/testar-no-testesis.php <login> <idmensagem> [operacao]\n");
    fwrite(STDERR, "Crie antes token-externo/<login>.txt com o token de teste.\n");
    exit(2);
}

$dados = [
    'operacao' => $operacao,
    'texto' => 'teste de homologacao no testesis'
];

$api = new sisc('testesis', $login);
$resultado = $api->enviar($idmensagem, $dados, 'executar');

echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
