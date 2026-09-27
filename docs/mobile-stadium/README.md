# Como subir uma distribuição nova do Mobile Stadium

Este é o "como usar". Para o formato de bytes em si e o porquê de cada
regra, ver `spec.md` — este guia não repete aquilo, só o caminho de ponta a
ponta.

## O caminho, em três passos

1. **Gerar o par `<slug>.bin` + `<slug>.json`** — a partir de um save já
   testado, com `make_distribution.py` (linha de comando) ou, quando
   existir, o botão "Compilar dados para REON" do plugin do PKHeX no
   emulador. Os dois produzem exatamente o mesmo par; o botão é a versão
   com interface do mesmo script.
2. **Importar** com `maint/import_stadium_distribution.php`. Valida o
   formato e grava no banco — **nunca ativa sozinho**.
3. **Ativar** em `/admin/stadium.php`, por região. É aqui, e só aqui, que o
   conteúdo passa a ser servido a qualquer console que peça.

Nenhum passo pula o anterior: o importador recusa um `.bin` malformado
antes de chegar ao banco, e uma linha só aparece no painel depois de
importada.

## Passo 1 — gerar o par

```
python3 docs/mobile-stadium/make_distribution.py <save.sav> <diretório-de-saída> \
    --slug <slug> \
    --file-id <16 caracteres> \
    [--region j|e|p|u|d|f|i|s] \
    [--message "<mensagem do dia, com marcação do Stadium>"] \
    [--flags <byte das flags do Delibird>] \
    [--cost null|0|N] \
    [--battles N] \
    [--title "<descrição livre, só para o painel>"]
```

- **`<save.sav>`** precisa ter o bloco de download já montado em `0xF000`
  (0x1000 bytes) — ou seja, um save que alguém já testou no emulador contra
  o Mobile Stadium, com as batalhas que se quer distribuir gravadas nele.
  O script não monta batalha nenhuma; ele só troca os campos de uma
  distribuição já pronta.
- **`--slug`** vira o nome do arquivo servido (sem extensão, sem prefixo de
  custo) e o identificador da linha no banco. `[a-z0-9-]`, até 40
  caracteres.
- **`--file-id`** tem que ser **novo** — 16 caracteres ASCII, únicos por
  região. É assim que o jogo decide "isto é diferente do que eu já tenho" e
  oferece o download. Reaproveitar um valor faz o jogo dizer "você só tem
  os mesmos dados de novo" e nunca baixar.
- **`--message`**, omitido, mantém a mensagem que já estava gravada no
  save (normalmente a de quem gerou o save de teste — **provavelmente não
  é o que se quer distribuir**). Passe a sua própria, com a marcação do
  Stadium (`<FONT LOAD nn>`, `<LINE nn>`, etc. — ver `spec.md` para a
  sintaxe).
- **`--flags`**, o byte de flags do Delibird. **Cuidado com isto**: os
  bits `0x01` (Game Boy → Game Boy Advance) e `0x02` (Nintendo 64 →
  GameCube) trocam a plataforma do jogador **de forma permanente**, e o
  Stadium não desfaz. Salvo intenção clara, use `0`.
- **`--cost`** decide quem pode baixar, e a escolha recomendada é `0`:
  exige sessão autenticada e não cobra nada do jogador (ver a tabela em
  `spec.md` §5.4). Sem prefixo (`null`) libera o download **sem
  autenticação nenhuma** — não é o padrão para conteúdo que carrega nome
  de treinador e time.
- **`--region`** é a letra que o servidor usa (`j`/`e`/`p`/`u`/`d`/`f`/`i`/`s`),
  não o código de 4 letras. Para as sete regiões ocidentais o conteúdo do
  `.bin` é byte-idêntico entre si — gere uma vez por região mesmo assim,
  porque cada uma precisa do seu próprio par no formato de importação.

O resultado são dois arquivos em `<diretório-de-saída>/`:
`<slug>.bin` (o bloco, exatamente 0xFFE bytes) e `<slug>.json` (os
metadados). Não edite o `.bin` à mão — qualquer byte alterado invalida a
soma que o próprio script já calculou.

## Passo 2 — importar

No servidor (é lá que o banco vive):

```
php maint/import_stadium_distribution.php <caminho/para/slug.json>
```

ou, para vários pares de uma vez (por exemplo, uma região por
subdiretório):

```
php maint/import_stadium_distribution.php --dir <diretório>
```

O importador confere, antes de gravar: o tamanho exato (0xFFE bytes), o
File ID do `.json` batendo com o que está gravado dentro do próprio
`.bin`, e a moldura `P3`+soma no fim do bloco — essa última é a mesma
verificação que faltava nos dois arquivos antigos do upstream, e é o que
faz o Stadium listar a distribuição em vez de mostrar uma lista vazia.
Qualquer um desses pontos falhando, ele recusa com uma mensagem dizendo
qual, e não grava nada.

A linha nasce **inativa**. Reimportar o mesmo File ID é seguro — o
importador recusa a duplicata com uma mensagem clara, não derruba nada.

## Passo 3 — ativar

Em `/admin/stadium.php?region=<letra>`, a distribuição aparece na lista
com o botão **Ativar**. Só depois desse clique ela entra no `menu.cgb` que
o jogo baixa e passa a ser oferecida de verdade.

Duas coisas para saber antes de clicar:

- **Só a primeira entrada elegível de cada sessão é baixada.** Ativar mais
  de uma distribuição na mesma região só faz sentido como janelas de tempo
  que não se sobrepõem (ver `spec.md` §5.3) — nunca deixe uma distribuição
  antiga ativa "atrás" de uma nova com a mesma janela: quem já tem a nova
  recebe a antiga oferecida de novo, alternando.
- **Desativar não apaga a linha nem o arquivo.** Um console que já baixou
  aquele bloco continua com ele — desativar só impede *novos* downloads.
  Isso é o mesmo tipo de limite que existe do lado do jogador: uma vez que
  o Game Boy tem o bloco, o servidor não alcança mais aquela cópia.

## Verificar antes de confiar

`crystal_check.py` modela as mesmas checagens que o Crystal e o Stadium
fazem, sem precisar de emulador nem console:

```
python3 docs/mobile-stadium/crystal_check.py <menu.cgb> <payload.bin> [save.sav]
```

Ele diz se o payload seria aceito, se a moldura é válida (ou seja, se o
Stadium vai listar a distribuição), e o preço que apareceria na tela. É a
mesma ferramenta usada para validar cada payload deste projeto antes de
ativá-lo em produção — vale rodar contra o seu par antes do passo 2, não
só depois.

## Procedência

`spec.md`, `crystal_check.py` e este próprio `make_distribution.py` vieram
da sessão "PKHeX Linux Port", lidos do disassembly do Crystal — não são
trabalho do lado do servidor. Cada arquivo carrega seu próprio cabeçalho
dizendo isso. Este README é a exceção: foi escrito do lado do servidor,
para documentar o fluxo que os três arquivos acima já permitiam, mas que
nenhum deles explicava sozinho.
