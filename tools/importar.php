<?php
/**
 * Carga inicial do PCA no plugin (linha de comando, dentro do servidor do GLPI).
 *
 * Uso:  php plugins/pca/tools/importar.php [--pca=arquivo.json] [--pncp=arquivo.json] [--semente] [--simular]
 * A carga-semente do PNCP (dados/pncp_tic_2027_semente.json) só é gravada com --semente e apenas se ainda não
 * houver itens lidos do PNCP no exercício: misturada com a leitura real, ela duplica os valores na conciliação.
 * Sem argumentos, usa os arquivos de plugins/pca/dados/.
 * Idempotente: a chave é exercício + apelido. Linhas existentes são atualizadas, EXCETO as que já foram alteradas
 * por uma pessoa (histórico com usuário) ou marcadas como conferidas: essas são preservadas e listadas.
 * Use --forcar para sobrescrever também as preservadas.
 */

use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Pncp;

if (PHP_SAPI !== 'cli') {
    exit("Somente linha de comando.\n");
}
$raiz = null;
foreach ([getenv('GLPI_ROOT') ?: '', dirname(__DIR__, 3), '/var/www/glpi', '/var/www/html/glpi'] as $cand) {
    if ($cand !== '' && is_file($cand . '/vendor/autoload.php') && is_file($cand . '/bin/console')) {
        $raiz = $cand;
        break;
    }
}
if ($raiz === null) {
    exit("Não encontrei a raiz do GLPI. Informe a variável de ambiente GLPI_ROOT.\n");
}
chdir($raiz);
require $raiz . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpilanguage']              = 'pt_BR';
$_SESSION['glpiname']                  = 'carga-pca';

$op = ['pca' => dirname(__DIR__) . '/dados/pca_2027.json', 'pncp' => dirname(__DIR__) . '/dados/pncp_tic_2027_semente.json', 'simular' => false, 'semente' => false, 'forcar' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--simular') {
        $op['simular'] = true;
    } elseif ($a === '--semente') {
        $op['semente'] = true;
    } elseif ($a === '--forcar') {
        $op['forcar'] = true;
    } elseif (preg_match('/^--(pca|pncp)=(.*)$/', $a, $m)) {
        $op[$m[1]] = $m[2];
    }
}

if (!is_file($op['pca'])) {
    exit("Arquivo de contratações não encontrado: {$op['pca']} (informe --pca=/caminho/arquivo.json; formato em dados/README.md).\n");
}

global $DB;
$grupos = [];
foreach ($DB->request(['SELECT' => ['id', 'code', 'name'], 'FROM' => 'glpi_groups']) as $g) {
    if ($g['code'] !== null && $g['code'] !== '') {
        $grupos[mb_strtoupper($g['code'])] = (int) $g['id'];
    }
}

$dados = json_decode(file_get_contents($op['pca']), true);
$ex    = (int) $dados['exercicio'];
$novas = $atual = $semgrupo = $erros = $preservadas = 0;
$div   = [];
$preservadas_lista = [];
foreach ($dados['contratacoes'] as $c) {
    $in = ['exercicio' => $ex, 'entities_id' => 0, 'is_recursive' => 1];
    foreach (['prioridade', 'destinacao', 'numero_pca', 'requisitante', 'name', 'objeto', 'tipo', 'justificativa', 'processo_sei',
        'status', 'origem', 'necessidade', 'data_limite', 'vigencia_meses', 'pagamento', 'valor_unico', 'valor_cheio_ano',
        'observacoes', 'nota_revisao', 'valor_anual_original', 'valor_global_original', 'pncp_grupo_codigo'] as $k) {
        $in[$k] = $c[$k] ?? null;
        if ($in[$k] === null) {
            $in[$k] = in_array($k, ['prioridade', 'data_limite', 'vigencia_meses', 'valor_unico', 'valor_cheio_ano',
                'valor_anual_original', 'valor_global_original'], true) ? 'NULL' : '';
        }
    }
    // Requisitante composto (ex.: AREA1/AREA2/DEPTO): vale o primeiro código que corresponda a um grupo.
    $gid = 0;
    foreach (array_merge([(string) $c['requisitante']], explode('/', (string) $c['requisitante'])) as $cod) {
        $gid = $grupos[mb_strtoupper(trim($cod))] ?? 0;
        if ($gid) {
            break;
        }
    }
    if ($gid) {
        $in['groups_id'] = $gid;
    } else {
        $semgrupo++;
    }
    if ($op['simular']) {
        echo "(simulação) {$c['name']}\n";
        continue;
    }
    $item = new Contratacao();
    $ja   = $item->find(['exercicio' => $ex, 'name' => $c['name']]);
    if ($ja) {
        $in['id'] = (int) array_key_first($ja);
        // Linha mexida por uma pessoa (log com usuário) ou conferida pela área: não sobrescrever.
        $editada = countElementsInTable('glpi_logs', ['itemtype' => Contratacao::class, 'items_id' => $in['id'], 'NOT' => ['user_name' => '']])
            || countElementsInTable(Contratacao::getTable(), ['id' => $in['id'], 'conferido' => 1]);
        if ($editada && !$op['forcar']) {
            $preservadas++;
            $preservadas_lista[] = $c['name'];
            continue;
        }
        $ok = $item->update($in);
        $atual++;
    } else {
        $ok = $item->add($in);
        $novas++;
    }
    if (!$ok) {
        $erros++;
        fwrite(STDERR, "falha: {$c['name']}\n");
        continue;
    }
    $item->getFromDB($ja ? $in['id'] : $ok);
    foreach (['valor_anual' => '_valor_anual_planilha', 'valor_global' => '_valor_global_planilha'] as $campo => $ref) {
        $a = $item->fields[$campo];
        $b = $c[$ref] ?? null;
        if (($a === null) !== ($b === null || $b === '') || ($a !== null && abs((float) $a - (float) $b) > 0.02)) {
            $div[] = "{$c['name']}: {$campo} plugin=" . var_export($a, true) . ' planilha=' . var_export($b, true);
        }
    }
}
echo "Contratações: novas={$novas} atualizadas={$atual} preservadas={$preservadas} falhas={$erros} sem grupo correspondente={$semgrupo}\n";
if ($preservadas_lista) {
    echo 'Preservadas (alteradas por pessoa ou conferidas; use --forcar para sobrescrever): ' . implode(', ', $preservadas_lista) . "\n";
}
echo 'Divergências de cálculo frente à planilha: ' . count($div) . "\n";
foreach ($div as $d) {
    echo "  - {$d}\n";
}

// Recalcula tudo uma segunda vez: apelido e N.PCA duplicados dependem do conjunto completo
if (!$op['simular']) {
    $item = new Contratacao();
    foreach ($item->find(['exercicio' => $ex]) as $l) {
        $item->update(['id' => $l['id'], '_recalculo' => 1]);
    }
    if (!$op['semente']) {
        echo "Carga-semente do PNCP não gravada (use --semente se ainda não houver leitura real).\n";
    } elseif (is_file($op['pncp'])) {
        $p = json_decode(file_get_contents($op['pncp']), true);
        if (countElementsInTable('glpi_plugin_pca_pncpitens', ['exercicio' => (int) $p['exercicio'], 'origem_dado' => 'pncp'])) {
            exit("Já há itens lidos do PNCP em {$p['exercicio']}; a semente não foi gravada.\n");
        }
        $n = Pncp::gravar($p['itens'], (int) $p['exercicio'], (string) $p['idPcaPncp'], (int) $p['sequencialPca'], 'semente');
        echo "Itens do PNCP carregados (semente): {$n}\n";
    }
}
