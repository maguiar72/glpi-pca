<?php

namespace GlpiPlugin\Pca;

use Glpi\DBAL\QueryExpression;

/**
 * Totais do PCA para o painel. "Ativas" = necessidade diferente de Remover.
 */
class Painel
{
    public static function dados(int $exercicio): array
    {
        global $DB;

        $t    = Contratacao::getTable();
        $base = ['exercicio' => $exercicio, 'is_deleted' => 0];
        $ativ = array_merge($base, [['OR' => [['necessidade' => null], ['NOT' => ['necessidade' => 'Remover']]]]]);

        $soma = static function (array $where, ?string $grupo = null) use ($DB, $t): array {
            $crit = [
                'SELECT' => [
                    new QueryExpression('COUNT(*) AS qtd'),
                    new QueryExpression('COALESCE(SUM(valor_anual),0) AS anual'),
                    new QueryExpression('COALESCE(SUM(valor_global),0) AS global'),
                ],
                'FROM'  => $t,
                'WHERE' => $where,
            ];
            if ($grupo !== null) {
                $crit['SELECT'][] = new QueryExpression("COALESCE(NULLIF(CAST({$grupo} AS CHAR),''),'(não informado)') AS chave");
                $crit['GROUPBY']  = ['chave'];
                $crit['ORDER']    = ['anual DESC'];
            }
            return iterator_to_array($DB->request($crit), false);
        };

        $tot  = $soma($ativ)[0];
        $rem  = $soma(array_merge($base, ['necessidade' => 'Remover']))[0];
        $pend = countElementsInTable($t, array_merge($ativ, [['NOT' => ['pendencias' => '']], ['NOT' => ['pendencias' => null]]]));
        $conf = countElementsInTable($t, array_merge($ativ, ['conferido' => 1]));
        $aval = countElementsInTable($t, array_merge($base, ['necessidade' => 'Avaliar']));
        $est  = (float) Parametros::get('pca_estimativa');

        $meses = $soma($ativ, "DATE_FORMAT(data_limite,'%Y-%m')");
        usort($meses, static fn ($a, $b) => strcmp($a['chave'], $b['chave']));

        return [
            'exercicio'   => $exercicio,
            'total'       => $tot,
            'removidas'   => $rem,
            'pendentes'   => $pend,
            'conferidas'  => $conf,
            'avaliar'     => $aval,
            'estimativa'  => $est,
            'percentual'  => $est > 0 ? (float) $tot['anual'] / $est * 100 : null,
            'tabelas'     => [
                'Por destinação'   => $soma($ativ, 'destinacao'),
                'Por requisitante' => $soma($ativ, 'requisitante'),
                'Por necessidade'  => $soma($ativ, 'necessidade'),
                'Por prioridade'   => $soma($ativ, 'prioridade'),
                'Por tipo'         => $soma($ativ, 'tipo'),
                'Por pagamento'    => $soma($ativ, 'pagamento'),
                'Por origem'       => $soma($ativ, 'origem'),
                'Por mês da data limite' => $meses,
            ],
        ];
    }
}
