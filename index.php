<?php
declare(strict_types=1);

require_once __DIR__ . '/redis.php';

try {
    $redis = conectarRedis();
    $redis->ping();
} catch (Throwable $erro) {
    exit('Não foi possível conectar ao Redis. Verifique a conexão.');
}

$opcoesPermitidas = [
    'MySQL',
    'MongoDB',
    'Redis',
    'Cassandra',
];

if (!$redis->exists('votacao:bancos')) {
    foreach ($opcoesPermitidas as $opcao) {
        $redis->zadd('votacao:bancos', [$opcao => 0]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'votar';

    if ($acao === 'zerar') {
        $redis->del([
            'votacao:bancos',
            'votacao:total',
            'votacao:participantes',
        ]);
        header('Location: index.php?status=zerada');
        exit;
    }

    $opcao = trim($_POST['opcao'] ?? '');
    $participante = strtolower(trim($_POST['participante'] ?? ''));

    if (!in_array($opcao, $opcoesPermitidas, true)) {
        header('Location: index.php?status=invalida');
        exit;
    }

    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $participante)) {
        header('Location: index.php?status=participante-invalido');
        exit;
    }

    $novoParticipante = (int) $redis->sadd('votacao:participantes', $participante);

    if ($novoParticipante === 0) {
        header('Location: index.php?status=duplicado');
        exit;
    }

    $redis->zincrby('votacao:bancos', 1, $opcao);
    $redis->incr('votacao:total');

    header('Location: index.php?status=registrado');
    exit;
}

$ranking = $redis->zrange('votacao:bancos', 0, -1, [
    'rev' => true,
    'withscores' => true,
]);

$totalVotos = (int) ($redis->get('votacao:total') ?? 0);

$status = $_GET['status'] ?? '';

$mensagens = [
    'registrado' => 'Voto registrado com sucesso!',
    'invalida' => 'Selecione uma opção válida.',
    'participante-invalido' => 'Informe um código de participante válido.',
    'duplicado' => 'Este participante já registrou um voto.',
    'zerada' => 'A votação foi zerada.',
];

$mensagem = $mensagens[$status] ?? '';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Votação com PHP e Redis</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
  <main class="container">
    <section class="cartao">
      <h1>Votação em tempo real</h1>
      <p>Qual banco de dados você gostaria de estudar mais?</p>

      <?php if ($mensagem !== ''): ?>
        <p class="mensagem"><?= htmlspecialchars($mensagem) ?></p>
      <?php endif; ?>

      <form method="post" class="formulario">
        <label for="participante">Código do participante</label>
        <input
          class="campo-texto"
          type="text"
          id="participante"
          name="participante"
          minlength="3"
          maxlength="30"
          pattern="[A-Za-z0-9._-]{3,30}"
          placeholder="Ex.: aluno07"
          required
        >

        <?php foreach ($opcoesPermitidas as $opcao): ?>
          <label class="opcao">
            <input type="radio" name="opcao" value="<?= htmlspecialchars($opcao) ?>" required>
            <?= htmlspecialchars($opcao) ?>
          </label>
        <?php endforeach; ?>

        <button type="submit">Registrar voto</button>
      </form>
    </section>

    <section class="cartao">
      <h2>Ranking atual</h2>
      <p class="total">Total de votos: <?= $totalVotos ?></p>

      <table>
        <thead>
          <tr>
            <th>Posição</th>
            <th>Banco</th>
            <th>Votos</th>
            <th>Percentual</th>
          </tr>
        </thead>
        <tbody>
          <?php $posicao = 1; ?>
          <?php foreach ($ranking as $banco => $votos): ?>
            <?php
              $quantidade = (int) $votos;
              $percentual = $totalVotos > 0 ? ($quantidade / $totalVotos) * 100 : 0;
            ?>
            <tr>
              <td><?= $posicao ?>&ordm;</td>
              <td><?= htmlspecialchars((string) $banco) ?></td>
              <td><?= $quantidade ?></td>
              <td><?= number_format($percentual, 1, ',', '.') ?>%</td>
            </tr>
            <?php $posicao++; ?>
          <?php endforeach; ?>
        </tbody>
      </table>

      <form method="post" class="formulario-zerar">
        <input type="hidden" name="acao" value="zerar">
        <button type="submit" class="botao-secundario">Zerar votação</button>
      </form>
    </section>
  </main>
</body>
</html>