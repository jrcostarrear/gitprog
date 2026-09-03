<?php
declare(strict_types=1);

require_once __DIR__ . '/sisc-api-cliente.php';

/* Configuração única do consumidor/testador de conectores SISC.
 * O kit usa testesis por padrão para homologação segura.
 * Ajuste somente estes parâmetros e o arquivo token-externo/<login>.txt.
 * Não altere sisc-api-cliente.php para trocar sistema, login, URL, origem ou token.
 * A instalação em siscore é responsabilidade do servidor após os selos válidos.
 */
$sisc = new sisc('testesis', 'meu-login');
