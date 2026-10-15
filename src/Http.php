<?php

namespace GlpiPlugin\Pca;

/**
 * Cliente HTTP (Guzzle) com o proxy configurado no GLPI.
 * O GLPI 11 fornece Toolbox::getGuzzleClient(); o GLPI 12 removeu e usa Symfony HttpClient no núcleo,
 * mas mantém o Guzzle no vendor. Este auxiliar funciona nas duas versões.
 */
final class Http
{
    public static function guzzle(array $options = []): \GuzzleHttp\Client
    {
        if (method_exists(\Toolbox::class, 'getGuzzleClient')) {
            return \Toolbox::getGuzzleClient($options);
        }
        global $CFG_GLPI;
        if (!empty($CFG_GLPI['proxy_name']) && !isset($options['proxy'])) {
            $cred = '';
            if (!empty($CFG_GLPI['proxy_user'])) {
                $senha = (new \GLPIKey())->decrypt($CFG_GLPI['proxy_passwd'] ?? '') ?: '';
                $cred  = rawurlencode((string) $CFG_GLPI['proxy_user']) . ':' . rawurlencode((string) $senha) . '@';
            }
            $options['proxy'] = sprintf('http://%s%s:%d', $cred, $CFG_GLPI['proxy_name'], (int) $CFG_GLPI['proxy_port']);
        }
        return new \GuzzleHttp\Client($options);
    }
}
