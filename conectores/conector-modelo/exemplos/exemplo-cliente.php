#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Consumo simples do conector-modelo via cliente SISC.
 * A configuração de sistema/login fica na raiz do kit, em cabsisc.h.
 * Para outro conector, adapte somente idmensagem e payload de negócio.
 */

if (($argv[1] ?? '') === '--self-test') {
    echo json_encode([
        'sucesso' => true,
        'conector' => 'conector-modelo',
        'idmensagem' => 'conector-modelo.eco',
        'descricao' => 'Demonstra consumo do conector-modelo usando cabsisc.h e sisc-api-cliente.php.',
        'uso' => 'php conectores/conector-modelo/exemplos/exemplo-cliente.php "mensagem de teste"',
        'payload' => [
            'dados' => [
                'operacao' => 'eco',
                'texto' => 'mensagem de teste'
            ]
        ],
        'saidaEsperada' => [
            'sucesso' => true,
            'conector' => 'conector-modelo',
            'operacao' => 'eco'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$texto = trim(implode(' ', array_slice($argv, 1)));
if ($texto === '') {
    fwrite(STDERR, "Uso: php conectores/conector-modelo/exemplos/exemplo-cliente.php \"mensagem de teste\"\n");
    fwrite(STDERR, "Configure antes cabsisc.h e token-externo/<login>.txt.\n");
    exit(2);
}

try {
    require dirname(__DIR__, 3) . '/cabsisc.h';

    $resposta = $sisc->enviar('conector-modelo.eco', [
        'operacao' => 'eco',
        'texto' => $texto,
    ], 'executar');

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $erro) {
    fwrite(STDERR, 'Erro ao consumir conector-modelo: ' . $erro->getMessage() . PHP_EOL);
    exit(1);
}
