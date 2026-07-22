<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Predis\Client;

function obterVariavelObrigatoria(string $nome): string
{
    $valor = getenv($nome);

    if ($valor === false || trim($valor) === '') {
        throw new RuntimeException("A variável de ambiente {$nome} não foi configurada.");
    }

    return $valor;
}

function conectarRedis(): Client
{
    return new Client([
        'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
        'host' => obterVariavelObrigatoria('REDIS_HOST'),
        'port' => (int) obterVariavelObrigatoria('REDIS_PORT'),
        'username' => getenv('REDIS_USERNAME') ?: 'default',
        'password' => obterVariavelObrigatoria('REDIS_PASSWORD'),
        'timeout' => 10,
    ]);
}