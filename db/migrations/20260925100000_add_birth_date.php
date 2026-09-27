<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBirthDate extends AbstractMigration
{
    public function change(): void
    {
        // A data de nascimento da pessoa, dita por ela no cadastro.
        //
        // Por que existe, já que o servidor recebia uma idade do cartucho: a
        // idade do jogo é atualizada à mão pela pessoa e envelhece parada, e
        // chega DEPOIS do cadastro -- tarde para governar qualquer coisa. Uma
        // data resolve os dois problemas: envelhece sozinha e está disponível
        // antes da primeira transmissão.
        //
        // Por que DATA e não só um sim/não de "tenho 13+", que era a minha
        // recomendação: decisão do dono em 24/09/2026, para o ranking poder
        // ser adequado a limiar por país no futuro (12 no Brasil, 13 na COPPA,
        // 14 no Quebec, 13-16 na GDPR, 18 na Índia). Um booleano de 13 não
        // responde nenhuma dessas outras perguntas depois.
        //
        // NULL é permitido, e significa "não informou" -- o campo é OPCIONAL
        // no cadastro, por decisão dele: o registro é livre, o que a data
        // governa é o ranking. Quem não informar continua sob a regra antiga,
        // o filtro pela idade que o cartucho manda.
        //
        // Nunca é exibida a ninguém. E não é o mesmo dado que
        // bxt_ranking.player_age: aquele vem do cartucho, este vem do site, e
        // confundir os dois já rendeu bug antes com as três identidades da
        // conta.
        $this->table('sys_users')
             ->addColumn('birth_date', 'date', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'self-declared at sign-up, optional; gates ranking participation, never displayed',
             ])
             ->update();
    }
}
