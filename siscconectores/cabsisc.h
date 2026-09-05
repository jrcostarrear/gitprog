<?php
declare(strict_types=1);

require_once __DIR__ . '/sisc-api-cliente.php';

/*
 * Configuração base do consumidor SISC.
 * Ao criar um conector, troque conector-nome pelo nome real,
 * por exemplo: conector-email, conector-crm-leads etc.
 */
$sisc = new sisc('siscore', 'meu-login', 'conector-nome');
