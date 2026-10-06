<?php

namespace GlpiPlugin\Pca;

use CommonGLPI;
use CronTask;
use Toolbox;

/**
 * Espelho do PCA publicado no PNCP (somente leitura) e conciliação com o cadastro.
 */
class Pncp extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'PNCP';
    }

    public static function cronInfo($name)
    {
        return ['description' => 'Sincroniza o PCA publicado no PNCP'];
    }

    public static function cronSincronizarPncp(CronTask $task): int
    {
        try {
            $fonte = self::fonte((int) Parametros::get('exercicio'));
            if ($fonte['tipo'] === 'plugin') {
                $task->log("Itens do PCA vêm do plugin PNCP ({$fonte['qtd']} itens); nada a ler aqui.");
                return 0;
            }
            $n = self::sincronizar();
            $task->addVolume($n);
            $task->log("Itens lidos do PNCP: {$n}");
            return 1;
        } catch (\Throwable $e) {
            $task->log('Falha na leitura do PNCP: ' . $e->getMessage());
            return -1;
        }
    }

    /**
     * Itens do PCA publicado, já normalizados. A fonte preferida é o plugin PNCP (monitoramento da UASG, que
     * já coleta o PCA); sem ele, usa o espelho próprio deste plugin (leitura direta pela API).
     *
     * @return array{tipo: string, itens: array<int, array>, qtd: int, ultima: ?string}
     */
    public static function fonte(int $exercicio): array
    {
        global $DB;

        $p = Parametros::todos();
        $tabela = 'glpi_plugin_pncp_pcaitems';
        if ((new \Plugin())->isActivated('pncp') && $DB->tableExists($tabela)) {
            $itens  = [];
            $ultima = null;
            foreach ($DB->request(['FROM' => $tabela, 'WHERE' => ['year' => $exercicio, 'cnpj' => (string) $p['cnpj_orgao']]]) as $r) {
                if (ltrim((string) $r['unit_code'], '0') !== ltrim((string) $p['uasg'], '0')) {
                    continue;
                }
                $raw    = json_decode((string) $r['raw'], true) ?: [];
                $codigo = $raw['grupoContratacaoCodigo'] ?? null;
                if ($codigo === null || $codigo === '') {
                    continue;
                }
                $itens[] = [
                    'exercicio'                 => $exercicio,
                    'numero_item'               => (int) $r['item_number'],
                    'grupo_codigo'              => (string) $codigo,
                    'grupo_nome'                => (string) ($raw['grupoContratacaoNome'] ?? $r['group_name'] ?? ''),
                    'categoria_cod'             => null,
                    'categoria_nome'            => $r['category_name'],
                    'valor_total'               => $r['total_value'],
                    'valor_orcamento_exercicio' => $raw['valorOrcamentoExercicio'] ?? null,
                    'data_desejada'             => $r['date_desired'],
                ];
                if ($ultima === null || (string) $r['date_sync'] > $ultima) {
                    $ultima = (string) $r['date_sync'];
                }
            }
            if ($itens) {
                return ['tipo' => 'plugin', 'itens' => $itens, 'qtd' => count($itens), 'ultima' => $ultima];
            }
        }

        $itens = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_pca_pncpitens', 'WHERE' => ['exercicio' => $exercicio]]), false);
        $ult   = $itens ? max(array_column($itens, 'date_sync')) : null;

        return ['tipo' => 'espelho', 'itens' => $itens, 'qtd' => count($itens), 'ultima' => $ult];
    }

    /** O item é de TIC? Pelo código da categoria (espelho próprio) ou pelo nome (plugin PNCP). */
    private static function ehTic(array $item, array $p): bool
    {
        if ($item['categoria_cod'] !== null && $item['categoria_cod'] !== '') {
            return (int) $item['categoria_cod'] === (int) $p['categoria_tic'];
        }
        return mb_strtolower(trim((string) $item['categoria_nome'])) === mb_strtolower(trim((string) $p['categoria_tic_nome']));
    }

    private static function get(string $url): array
    {
        $cliente = Toolbox::getGuzzleClient(['timeout' => 90]);
        $resp = $cliente->request('GET', $url, ['headers' => [
            'Accept'     => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (GLPI plugin PCA)',
            'Referer'    => 'https://pncp.gov.br/app/pca',
        ]]);
        $dados = json_decode((string) $resp->getBody(), true);
        if (!is_array($dados)) {
            throw new \RuntimeException('Resposta do PNCP não é JSON: ' . $url);
        }
        return $dados;
    }

    /** Lê o plano da UASG configurada e grava no espelho. Retorna a quantidade de itens. */
    public static function sincronizar(?int $exercicio = null): int
    {
        $p         = Parametros::todos();
        $exercicio = $exercicio ?: (int) $p['exercicio'];
        if (trim((string) $p['cnpj_orgao']) === '' || trim((string) $p['uasg']) === '') {
            throw new \RuntimeException('Informe o CNPJ do órgão e a UASG em Parâmetros antes de ler o PNCP.');
        }
        $base      = rtrim((string) $p['pncp_base_url'], '/') . "/orgaos/{$p['cnpj_orgao']}/pca/{$exercicio}";

        $unidades = self::get("{$base}/consolidado/unidades?pagina=1&tamanhoPagina=500");
        $unidades = $unidades['data'] ?? $unidades;
        $plano    = null;
        foreach ($unidades as $u) {
            if (ltrim((string) ($u['codigoUnidade'] ?? ''), '0') === ltrim((string) $p['uasg'], '0')) {
                $plano = $u;
                break;
            }
        }
        if ($plano === null) {
            throw new \RuntimeException("Nenhum PCA de {$exercicio} publicado para a UASG {$p['uasg']}");
        }
        $seq = (int) ($plano['sequencialPca'] ?? 0);
        $id  = (string) ($plano['numeroControlePNCP'] ?? $plano['idPcaPncp'] ?? '');

        $itens = [];
        for ($pag = 1; $pag <= 200; $pag++) {
            $lote = self::get("{$base}/{$seq}/itens?pagina={$pag}&tamanhoPagina=50");
            $lote = $lote['data'] ?? $lote;
            if (!$lote) {
                break;
            }
            $itens = array_merge($itens, $lote);
            if (count($lote) < 50) {
                break;
            }
        }
        if (!$itens) {
            throw new \RuntimeException('O PNCP não devolveu itens para o plano ' . $id);
        }
        $inicio = date('Y-m-d H:i:s');
        $n      = self::gravar($itens, $exercicio, $id, $seq, 'pncp');
        // Remove o que não veio nesta leitura (itens excluídos do plano e a carga-semente)
        global $DB;
        $DB->delete('glpi_plugin_pca_pncpitens', ['exercicio' => $exercicio, 'date_sync' => ['<', $inicio]]);
        return $n;
    }

    /** Grava itens no espelho. Aceita os nomes de campo das duas APIs do PNCP. */
    public static function gravar(array $itens, int $exercicio, string $id_plano, int $seq, string $origem): int
    {
        global $DB;

        $n = 0;
        foreach ($itens as $i) {
            $codigo = $i['grupoContratacaoCodigo'] ?? null;
            $numero = $i['numeroItem'] ?? null;
            if ($codigo === null || $numero === null) {
                continue;
            }
            $linha = [
                'exercicio'                 => $exercicio,
                'pncp_id_plano'             => $id_plano,
                'sequencial'                => $seq,
                'numero_item'               => (int) $numero,
                'grupo_codigo'              => $codigo,
                'grupo_nome'                => mb_substr((string) ($i['grupoContratacaoNome'] ?? ''), 0, 255),
                'categoria_cod'             => $i['categoriaItemPcaid'] ?? $i['categoriaItemPcaId'] ?? null,
                'categoria_nome'            => $i['categoriaItemPcaNome'] ?? null,
                'classificacao_codigo'      => $i['classificacaoSuperiorCodigo'] ?? null,
                'classificacao_nome'        => mb_substr((string) ($i['classificacaoSuperiorNome'] ?? ''), 0, 255),
                'valor_total'               => $i['valorTotal'] ?? null,
                'valor_orcamento_exercicio' => $i['valorOrcamentoExercicio'] ?? null,
                'data_desejada'             => !empty($i['dataDesejada']) ? substr((string) $i['dataDesejada'], 0, 10) : null,
                'data_atualizacao'          => $i['dataAtualizacao'] ?? null,
                'origem_dado'               => $origem,
                'raw'                       => json_encode($i, JSON_UNESCAPED_UNICODE),
                'date_sync'                 => date('Y-m-d H:i:s'),
            ];
            $DB->updateOrInsert('glpi_plugin_pca_pncpitens', $linha, [
                'exercicio' => $exercicio, 'grupo_codigo' => $codigo, 'numero_item' => (int) $numero,
            ]);
            $n++;
        }
        return $n;
    }

    /** Linhas da conciliação: cadastro x PNCP, pela chave pncp_grupo_codigo. */
    public static function conciliacao(int $exercicio): array
    {
        global $DB;

        $p    = Parametros::todos();
        $pncp = [];
        foreach (self::fonte($exercicio)['itens'] as $r) {
            $pncp[$r['grupo_codigo']][] = $r;
        }
        $linhas = [];
        $usados = [];
        foreach ($DB->request(['FROM' => Contratacao::getTable(),
            'WHERE' => ['exercicio' => $exercicio, 'is_deleted' => 0], 'ORDER' => ['requisitante', 'name']]) as $c) {
            $cod   = (string) $c['pncp_grupo_codigo'];
            $itens = $cod !== '' ? ($pncp[$cod] ?? []) : [];
            $valor_pncp = $itens ? array_sum(array_column($itens, 'valor_total')) : null;
            $ref   = $p['pncp_valor_total'] === 'global' ? $c['valor_global'] : $c['valor_anual'];
            if ($c['necessidade'] === 'Remover') {
                $sit = $itens ? 'Marcada para remover, mas publicada' : 'Marcada para remover';
            } elseif (!$itens) {
                $sit = $cod === '' ? 'Sem código do PNCP' : 'Código não encontrado no PNCP';
            } elseif ($ref === null) {
                $sit = 'Publicada; cadastro sem valor';
            } elseif (abs((float) $valor_pncp - (float) $ref) < 1) {
                $sit = 'Confere';
            } else {
                $sit = 'Valor divergente';
            }
            if ($itens) {
                $usados[$cod] = true;
            }
            $linhas[] = [
                'id' => $c['id'], 'apelido' => $c['name'], 'requisitante' => $c['requisitante'], 'numero_pca' => $c['numero_pca'],
                'codigo' => $cod, 'objeto_pncp' => $itens[0]['grupo_nome'] ?? '', 'valor_cadastro' => $ref,
                'valor_pncp' => $valor_pncp, 'diferenca' => ($itens && $ref !== null) ? (float) $valor_pncp - (float) $ref : null,
                'data_cadastro' => $c['data_limite'], 'data_pncp' => $itens[0]['data_desejada'] ?? null, 'situacao' => $sit,
            ];
        }
        foreach ($pncp as $cod => $itens) {
            if (isset($usados[$cod]) || !self::ehTic($itens[0], $p)) {
                continue;
            }
            $linhas[] = [
                'id' => null, 'apelido' => '', 'requisitante' => '', 'numero_pca' => '', 'codigo' => $cod,
                'objeto_pncp' => $itens[0]['grupo_nome'], 'valor_cadastro' => null,
                'valor_pncp' => array_sum(array_column($itens, 'valor_total')), 'diferenca' => null,
                'data_cadastro' => null, 'data_pncp' => $itens[0]['data_desejada'], 'situacao' => 'No PNCP como TIC, fora do cadastro',
            ];
        }
        return $linhas;
    }
}
