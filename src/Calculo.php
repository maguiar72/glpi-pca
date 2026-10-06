<?php

namespace GlpiPlugin\Pca;

/**
 * Regras de cálculo do PCA (as mesmas da planilha de origem).
 *
 * Meses no exercício: contados a partir do mês da data-limite.
 * Valor Anual  = pagamento único + (valor cheio no ano / 12 x meses no exercício)
 * Valor Global = pagamento único + (valor cheio no ano x vigência em meses / 12)
 */
class Calculo
{
    public static function mesesNoExercicio(?string $data_limite, int $exercicio, bool $inclui_mes = true): int
    {
        if (empty($data_limite) || $data_limite === '0000-00-00') {
            return 12;
        }
        $ts = strtotime($data_limite);
        if ($ts === false) {
            return 12;
        }
        $ano = (int) date('Y', $ts);
        $mes = (int) date('n', $ts);
        if ($ano < $exercicio) {
            return 12;
        }
        if ($ano > $exercicio) {
            return 0;
        }
        return ($inclui_mes ? 13 : 12) - $mes;
    }

    public static function valorAnual($unico, $cheio, int $meses): ?float
    {
        if (self::vazio($unico) && self::vazio($cheio)) {
            return null;
        }
        return round((float) $unico + (float) $cheio / 12 * $meses, 2);
    }

    public static function valorGlobal($unico, $cheio, $vigencia): ?float
    {
        if (self::vazio($unico) && self::vazio($cheio)) {
            return null;
        }
        return round((float) $unico + (float) $cheio * (int) $vigencia / 12, 2);
    }

    public static function prioridadePorValor(?float $valor_global, float $alta, float $media): ?int
    {
        if ($valor_global === null) {
            return null;
        }
        return $valor_global > $alta ? 1 : ($valor_global >= $media ? 2 : 3);
    }

    /** "R$ 1.234,56" (formato brasileiro fixo, independente da preferência de número do usuário); vazio se não houver valor. */
    public static function reais($v): string
    {
        return self::vazio($v) ? '' : 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    public static function vazio($v): bool
    {
        return $v === null || $v === '' || $v === 'NULL';
    }
}
