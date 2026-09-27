<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRankingsOptIn extends AbstractMigration
{
    public function change(): void
    {
        // Se o que o jogo manda entra no ranking.
        //
        // Até aqui não havia saída: o cartucho mandava, o servidor gravava, e
        // a página publicava -- sem login para ler e sem lugar nenhum para
        // desligar. É a divergência 14 do registro de proteção de dados, que
        // é sobre o PADRÃO e não sobre o campo: o Children's Code britânico
        // pede que a configuração de privacidade já comece fechada em serviço
        // que criança acessa.
        //
        // Nasce DESLIGADO, e é isso que fecha a 14: aparecer no ranking passa
        // a ser uma coisa que a pessoa pede, na caixa do cadastro ou na tela
        // da conta, em vez de uma coisa que acontece com ela.
        //
        // Pôde nascer assim sem esconder ninguém porque bxt_ranking estava
        // VAZIA no dia em que esta coluna chegou ao servidor -- 13 contas,
        // zero linhas de ranking. Não houve ninguém publicado para sumir.
        //
        // NOT NULL com default: nulo significaria "não sei", e cada leitura
        // teria de decidir o que fazer com isso. A coluna responde sempre, e
        // o padrão mora aqui, em UserUtil e em bxt_config -- os três iguais.
        $this->table('sys_users')
             ->addColumn('rankings_opt_in', 'boolean', [
                 'null' => false,
                 'default' => false,
                 'comment' => 'whether what the game uploads may be counted and shown in the rankings',
             ])
             ->update();
    }
}
