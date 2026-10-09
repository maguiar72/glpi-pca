<?php

namespace GlpiPlugin\Pca;

use Config;

/**
 * Parâmetros do plugin, guardados em glpi_configs (contexto plugin:pca).
 */
class Parametros
{
    public const LISTAS = ['destinacao', 'tipo', 'status', 'origem', 'necessidade', 'pagamento'];

    public static function padroes(): array
    {
        return [
            'exercicio'          => (int) date('Y') + 1, // PCA do ano seguinte, na instalação
            'pca_estimativa'     => '0',
            'cnpj_orgao'         => '', // informe em Parâmetros
            'uasg'               => '', // informe em Parâmetros
            'categoria_tic'      => 5,
            'categoria_tic_nome' => 'Soluções de TIC',
            'inclui_mes_limite'  => 1,
            'faixa_alta'         => '600000',
            'faixa_media'        => '100000',
            'apelido_max'        => 25,
            'pncp_valor_total'   => 'anual',
            'pncp_base_url'      => 'https://pncp.gov.br/api/pncp/v1',
            'lista_destinacao'   => "Interna\nExterna\nAmbas",
            'lista_tipo'         => "Nova Contratação\nProrrogação",
            'lista_status'       => "Não iniciado\nEspecificação e minuta de DOD\nDOD encaminhado\nAguardando especificações para envio do DOP\nDOP encaminhado\nPlanejamento em andamento\nPlanejamento concluído\nContratado",
            'lista_origem'       => "PCA do exercício\nMigrado do PCA anterior",
            'lista_necessidade'  => "Manter\nRemover\nAvaliar",
            'lista_requisitante' => '', // áreas fixas do combo Requisitante; as já usadas nas contratações entram sozinhas
            'lista_pagamento'    => "Único\nParcelado\nÚnico e parcelado",
        ];
    }

    public static function todos(): array
    {
        return Config::getConfigurationValues('plugin:pca') + self::padroes();
    }

    public static function get(string $nome)
    {
        return self::todos()[$nome] ?? null;
    }

    /** Lista suspensa no formato valor => valor. */
    public static function lista(string $nome): array
    {
        $linhas = preg_split('/\r?\n/', (string) self::get('lista_' . $nome));
        $out = [];
        foreach ($linhas as $l) {
            $l = trim($l);
            if ($l !== '') {
                $out[$l] = $l;
            }
        }
        return $out;
    }
}
