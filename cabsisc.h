<?php
declare(strict_types=1);

require_once __DIR__ . '/sisc-api-cliente.php';

/* Configuração única do consumidor/testador de conectores SISC.
 * Ajuste somente estes parâmetros e o arquivo token-externo/<login>.txt.
 * Não altere sisc-api-cliente.php para trocar sistema, login, URL, origem ou token.
 */
$sisc = new sisc('siscore', 'meu-login');
