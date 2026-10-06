<?php

namespace GlpiPlugin\Pca;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Aba "PCA" na ficha do Perfil, para atribuir os direitos do plugin.
 */
class Perfil extends Profile
{
    public static $rightname = 'profile';

    public static function getTable($classname = null)
    {
        return 'glpi_profiles';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof Profile && $item->getID() ? self::createTabEntry('PCA', 0, null, 'ti ti-clipboard-list') : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            (new self())->formDireitos((int) $item->getID());
        }
        return true;
    }

    public function formDireitos(int $profiles_id): void
    {
        $profile = new Profile();
        $profile->getFromDB($profiles_id);
        $pode = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($pode) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";
        }
        $profile->displayRightsChoiceMatrix([
            ['itemtype' => Contratacao::class, 'label' => 'Contratações do PCA', 'field' => 'plugin_pca_contratacao'],
            ['rights' => [READ => __('Read'), UPDATE => __('Update')], 'label' => 'Parâmetros, conciliação e edição de qualquer unidade',
                'field' => 'plugin_pca_config'],
        ], ['canedit' => $pode, 'title' => 'PCA - Plano de Contratações Anual']);
        if ($pode) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}
