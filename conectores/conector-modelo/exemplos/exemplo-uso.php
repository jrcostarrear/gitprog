#!/usr/bin/env php
<?php
declare(strict_types=1);

/* Exemplo de uso do conector-modelo.
 * Este programa demonstra o recurso do conector montando a mensagem que um
 * consumidor enviaria pelo catalogo SISC. O modo --self-test é usado pelo
 * validador para comprovar que o exemplo produz o que promete.
 */

$conector = 'conector-modelo';
$idmensagem = 'conector-modelo.eco';
$dados = [
    'operacao' => 'eco',
    'texto' => 'mensagem de validacao local'
];

$mensagem = [
    '_protocolo' => [
        'origem' => 'sistema__operador',
        'destino' => 'conector__' . $conector,
        'idmensagem' => $idmensagem,
        'idempotencia' => [
            'chave' => 'modelo-eco-001',
            'escopo' => $idmensagem
        ]
    ],
    'payload' => [
        'dados' => $dados
    ]
];

$saidaEsperada = [
    'sucesso' => true,
    'conector' => $conector,
    'operacao' => 'eco',
    'texto' => $dados['texto']
];

if (($argv[1] ?? '') === '--self-test') {
    echo json_encode([
        'sucesso' => true,
        'conector' => $conector,
        'idmensagem' => $idmensagem,
        'descricao' => 'Demonstra a operação eco e o payload.dados aceito pelo conector-modelo.',
        'payload' => $mensagem['payload'],
        'mensagem' => $mensagem,
        'saidaEsperada' => $saidaEsperada
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

echo "Exemplo de uso do {$conector}\n";
echo "idmensagem: {$idmensagem}\n\n";
echo json_encode($mensagem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
