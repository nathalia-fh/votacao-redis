<?php
declare(strict_types=1);

require_once __DIR__ . '/redis.php';

try {
    $redis = conectarRedis();
    $redis->ping();
} catch (Throwable $erro) {
    exit('Não foi possível conectar ao Redis. Verifique a conexão.');
}

const DURACAO_VOTACAO = 600;

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
    // cria chave temporária ao inicializar
    $redis->setex('votacao:aberta', DURACAO_VOTACAO, '1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'votar';

    if ($acao === 'zerar') {
        $redis->del([
            'votacao:bancos',
            'votacao:total',
            'votacao:participantes',
            'votacao:historico',
            'votacao:aberta',
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

    // recusar voto se votação encerrada
    if (!$redis->exists('votacao:aberta')) {
        header('Location: index.php?status=encerrada');
        exit;
    }

    // tenta adicionar participante; se já existia, retorna 0
    $novoParticipante = (int) $redis->sadd('votacao:participantes', $participante);

    if ($novoParticipante === 0) {
        header('Location: index.php?status=duplicado');
        exit;
    }

    // incrementa ranking e total
    $redis->zincrby('votacao:bancos', 1, $opcao);
    $redis->incr('votacao:total');

    // registra histórico: formato "participante votou em opção"
    $registro = "{$participante} votou em {$opcao}";
    $redis->lpush('votacao:historico', $registro);
    $redis->ltrim('votacao:historico', 0, 9);

    header('Location: index.php?status=registrado');
    exit;
}

// consultas para exibir na página
$ranking = $redis->zrange('votacao:bancos', 0, -1, [
    'rev' => true,
    'withscores' => true,
]);

$totalVotos = (int) ($redis->get('votacao:total') ?? 0);
$historico = $redis->lrange('votacao:historico', 0, 9);
$totalParticipantes = (int) $redis->scard('votacao:participantes');
$totalOpcoes = (int) $redis->zcard('votacao:bancos');
$totalHistorico = (int) $redis->llen('votacao:historico');

$tempoRestante = (int) $redis->ttl('votacao:aberta');
$minutos = $tempoRestante > 0 ? intdiv($tempoRestante, 60) : 0;
$segundos = $tempoRestante > 0 ? $tempoRestante % 60 : 0;

$nomeLider = null;
$votosLider = 0;
foreach ($ranking as $banco => $votos) {
    $nomeLider = (string) $banco;
    $votosLider = (int) $votos;
    break;
}

$status = $_GET['status'] ?? '';

$mensagens = [
    'registrado' => 'Voto registrado com sucesso!',
    'invalida' => 'Selecione uma opção válida.',
    'participante-invalido' => 'Informe um código de participante válido.',
    'duplicado' => 'Este participante já registrou um voto.',
    'encerrada' => 'O prazo desta votação foi encerrado.',
    'zerada' => 'A votação foi reiniciada.',
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

      <?php if ($tempoRestante > 0): ?>
        <p class="tempo">Tempo restante: <?= $minutos ?>min <?= $segundos ?>s</p>

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

      <?php else: ?>
        <p class="encerrada">A votação está encerrada. Reinicie para abrir um novo prazo.</p>
      <?php endif; ?>

    </section>

    <section class="cartao">
      <h2>Ranking atual</h2>

      <?php if ($nomeLider !== null && $votosLider > 0): ?>
        <p class="lider">Líder atual: <strong><?= htmlspecialchars($nomeLider) ?></strong> com <?= $votosLider ?> voto(s).</p>
      <?php endif; ?>

      <div class="painel">
        <div class="indicador">
          <strong><?= $totalVotos ?></strong>
          <span>votos</span>
        </div>
        <div class="indicador">
          <strong><?= $totalParticipantes ?></strong>
          <span>participantes</span>
        </div>
        <div class="indicador">
          <strong><?= $totalOpcoes ?></strong>
          <span>opções</span>
        </div>
        <div class="indicador">
          <strong><?= $totalHistorico ?></strong>
          <span>itens no histórico</span>
        </div>
      </div>

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

    </section>

    <section class="cartao">
      <h2>Últimos votos</h2>

      <?php if ($historico === []): ?>
        <p>Nenhum voto foi registrado.</p>
      <?php else: ?>
        <ol class="historico">
          <?php foreach ($historico as $registro): ?>
            <li><?= htmlspecialchars((string) $registro) ?></li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <form method="post" class="formulario-zerar">
        <input type="hidden" name="acao" value="zerar">
        <button type="submit" class="botao-secundario">Reiniciar votação</button>
      </form>
    </section>

  </main>
</body>
</html>