#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../cabsisc.h';

$idmensagem = getenv('SISC_IDMENSAGEM') ?: ($argv[1] ?? 'conector-modelo.eco');
$operacao = getenv('SISC_OPERACAO') ?: ($argv[2] ?? 'eco');

$dados = [
    'operacao' => $operacao,
    'texto' => 'teste de homologacao no testesis'
];

$resultado = $sisc->enviar($idmensagem, $dados, 'executar');

echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
