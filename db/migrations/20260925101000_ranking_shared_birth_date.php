<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RankingSharedBirthDate extends AbstractMigration
{
    public function up(): void
    {
        // Acrescenta a data de nascimento da conta ao filtro de publicação.
        //
        // Já havia um corte por idade aqui, mas pela idade que o CARTUCHO
        // manda (r.player_age). Ela é fraca por dois motivos conhecidos: é
        // atualizada à mão e envelhece parada, e só existe depois da primeira
        // transmissão. A data de nascimento da conta é melhor nos dois pontos.
        //
        // Os dois cortes ficam, e isso não é redundância: são fontes
        // diferentes, e a pessoa pode ter informado uma e não a outra. Quem
        // informa a data no site mas nunca tocou na idade do jogo fica coberta
        // pelo primeiro; quem se cadastrou antes de a data existir fica
        // coberta pelo segundo.
        //
        // NULL passa, de propósito: é "não informou", e não "criança". As
        // contas que existiam quando esta coluna nasceu estão todas assim, e
        // bloqueá-las seria interromper quem já usava por causa de um campo
        // que não existia quando elas se cadastraram.
        //
        // CURDATE() num `WHERE` de view é avaliado a cada consulta, então
        // alguém que faz 13 anos amanhã passa a poder aparecer amanhã, sem
        // nada precisar rodar.
        $this->execute(
            "CREATE OR REPLACE VIEW bxt_ranking_shared AS
             SELECT r.*
               FROM bxt_ranking r
               LEFT JOIN sys_users u ON u.id = r.account_id
              WHERE COALESCE(u.rankings_opt_in, 0) = 1
                AND (r.player_age IS NULL OR r.player_age = 0 OR r.player_age >= 13)
                AND (u.birth_date IS NULL OR u.birth_date <= (CURDATE() - INTERVAL 13 YEAR))"
        );
    }

    public function down(): void
    {
        $this->execute(
            "CREATE OR REPLACE VIEW bxt_ranking_shared AS
             SELECT r.*
               FROM bxt_ranking r
               LEFT JOIN sys_users u ON u.id = r.account_id
              WHERE COALESCE(u.rankings_opt_in, 0) = 1
                AND (r.player_age IS NULL OR r.player_age = 0 OR r.player_age >= 13)"
        );
    }
}
