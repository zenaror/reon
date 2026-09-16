<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUserAdapterChoice extends AbstractMigration
{
    public function change(): void
    {
        // A cor do adaptador e a marca de não-tarifado, por conta.
        //
        // Os dois já existiam como configuração única do painel, valendo
        // para toda mobile_config.bin gerada. Continuam existindo: o que
        // entra aqui é a escolha de cada pessoa, quando o administrador
        // liberar (bin_user_choice).
        //
        // Nulas de propósito, e nulo NÃO quer dizer "azul tarifado" -- quer
        // dizer "não escolhi". Duas consequências que a coluna com valor
        // padrão não teria:
        //
        //   - quem nunca abriu a tela continua acompanhando o painel. Se o
        //     administrador trocar o modelo global amanhã, essas contas
        //     mudam junto, que é o que elas esperariam de não ter escolhido.
        //   - desligar a opção no painel não apaga escolha de ninguém. As
        //     linhas ficam, param de ser lidas, e voltam a valer se a opção
        //     for religada.
        //
        // O valor é o enum mobile_adapter_device da libmobile (8 azul,
        // 9 amarelo, 10 verde, 11 vermelho); o bit 0x80 do byte gravado no
        // arquivo é montado na geração, não guardado aqui.
        $this->table('sys_users')
             ->addColumn('adapter_device', 'integer', [
                 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                 'null' => true,
                 'default' => null,
                 'comment' => 'mobile_adapter_device chosen by the account; null means follow the panel',
             ])
             ->addColumn('adapter_unmetered', 'boolean', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'unmetered flag chosen by the account; null means follow the panel',
             ])
             ->update();
    }
}
