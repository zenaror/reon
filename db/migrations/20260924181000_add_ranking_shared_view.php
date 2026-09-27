<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRankingSharedView extends AbstractMigration
{
    public function up(): void
    {
        // O que pode ser MOSTRADO do ranking.
        //
        // O dado continua sendo gravado como sempre: o cartucho manda, o
        // servidor guarda. O que esta view governa é a publicação -- a página
        // aberta e a tabela que o jogo mostra aos outros jogadores.
        //
        // É uma view e não um `where` repetido porque os pontos de leitura
        // são NOVE: oito no news.php, que monta o que o jogo vê, e um na
        // página. Espalhar a mesma condição por nove consultas é garantir
        // que um dia alguém acrescente a décima e esqueça -- e o modo de
        // falhar é publicar dado de quem não pediu para ser publicado, em
        // silêncio. Aqui a regra existe uma vez.
        //
        // São duas condições, e elas são independentes de propósito:
        //
        // 1) A PREFERÊNCIA DA CONTA. Desligada por padrão (ver a coluna
        //    rankings_opt_in): aparecer é uma coisa que a pessoa pede.
        //    O COALESCE cobre a linha sem conta correspondente, e trata o
        //    desconhecido como o padrão -- ninguém escolheu, então não
        //    publica.
        //
        // 2) A IDADE DECLARADA NO JOGO, abaixo de 13, que não publica
        //    independentemente da preferência. A COPPA dispara com
        //    conhecimento de fato, e guardar um campo de idade que diz 9 é
        //    conhecimento de fato; guardar e ignorar seria a pior
        //    combinação.
        //
        //    O número é ruim de duas maneiras: é autodeclarado dentro do
        //    jogo, e é ATUALIZADO À MÃO pela pessoa -- o jogo não mexe nele
        //    sozinho, então uma idade digitada uma vez fica lá envelhecendo
        //    enquanto a pessoa cresce. Por isso ele entra só na direção
        //    protetiva. Quem mente para cima não fica protegido, mas já não
        //    estaria; uma idade velha que diz 12 quando a pessoa já tem 15
        //    protege quem não precisava, e esse erro é barato e do lado
        //    certo. O que ela nunca faz é LIBERAR alguém.
        //
        //    Idade ausente ou zero não esconde: é "não informado", não é
        //    "criança", e a condição 1 já governa esse caso.
        //
        // ATENÇÃO: o `r.*` é expandido na criação. Se bxt_ranking ganhar
        // coluna, esta view precisa ser recriada, ou a coluna nova não
        // aparece para quem lê por aqui.
        $this->execute(
            "CREATE OR REPLACE VIEW bxt_ranking_shared AS
             SELECT r.*
               FROM bxt_ranking r
               LEFT JOIN sys_users u ON u.id = r.account_id
              WHERE COALESCE(u.rankings_opt_in, 0) = 1
                AND (r.player_age IS NULL OR r.player_age = 0 OR r.player_age >= 13)"
        );
    }

    public function down(): void
    {
        $this->execute("DROP VIEW IF EXISTS bxt_ranking_shared");
    }
}
