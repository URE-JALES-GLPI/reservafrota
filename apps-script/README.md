# Formulário do motorista (Apps Script)

O motorista abre o link da viagem, vê o carro e o último KM, e não consegue
registrar um KM menor. O registro entra na mesma planilha que o GLPI já lê.

Como o GLPI fica na rede interna, é ele quem envia os dados ao Google:

```
GLPI  -- viagens e KM (POST) -->  Apps Script  <-- link da viagem --  Celular
  ^                                    |
  '------ lê as respostas ------  Planilha de respostas
```

## Antes de começar

- Use a conta Google dona da planilha de respostas.
- Em **Integração Sheets > Intervalo**, confira se o intervalo cobre todas as
  colunas da aba. Com 9 colunas, por exemplo, use `Respostas!A2:I`, e não `A2:F`.
- O servidor do GLPI precisa acessar `script.google.com` e
  `script.googleusercontent.com`. Libere os dois se houver firewall ou proxy.
- Confira o **KM atual** dos carros no GLPI. Um valor errado para mais bloqueia
  os motoristas até ser corrigido.

## 1. Criar o projeto

1. Abra a planilha de respostas e clique em **Extensões > Apps Script**.
2. Dê o nome `Reserva de Frota - Formulário` ao projeto.
3. Apague o conteúdo de `Code.gs` e cole o arquivo [Code.gs](Code.gs).
4. Clique em **+ > HTML**, dê o nome `Formulario` (sem acento e sem `.html`) e
   cole o conteúdo de [Formulario.html](Formulario.html).
5. Em **Configurações do projeto** (engrenagem), defina o fuso
   `(GMT-03:00) Horário de São Paulo`.
6. Salve com **Ctrl+S**.

## 2. Gerar o segredo

1. Na barra superior, escolha a função `configurar` e clique em **Executar**.
2. Autorize com a sua conta. O Google pode avisar que o app não foi verificado,
   porque foi você quem o criou: clique em **Avançado > Acessar**.
3. Copie o **segredo** mostrado no registro de execução. Trate-o como uma senha.

## 3. Publicar

1. Clique em **Implantar > Nova implantação**.
2. Na engrenagem, escolha **App da Web**.
3. Preencha:
   - **Executar como:** Eu
   - **Quem pode acessar:** Qualquer pessoa
4. Clique em **Implantar** e copie a URL terminada em `/exec`.

Se a opção **Qualquer pessoa** não aparecer, o administrador do Google Workspace
restringiu esse compartilhamento e precisa liberá-lo, ou use outra conta dona da
planilha.

## 4. Configurar o GLPI

1. Acesse **Integração Sheets > Formulário do motorista**.
2. Cole a URL e o segredo. O salto máximo de KM começa em 2.000.
3. Clique em **Salvar e enviar dados**.
4. Confira se apareceu **Dados enviados ao formulário** e se **Último envio** foi
   preenchido.

## 5. Testar

1. Abra uma reserva aprovada e copie o **Link do formulário de saída/chegada**.
2. Abra o link no celular, usando os dados móveis.
3. Confira o carro e o último KM, e tente um KM menor: o envio deve ser bloqueado.
4. Registre um KM válido e confira a nova linha na planilha.
5. No GLPI, clique em **Sincronizar agora** e confira a linha em **Auditoria**.

## 6. Desativar o Forms antigo

Depois do teste, abra o Google Forms e desligue **Respostas > Aceitando
respostas**. Enquanto ele estiver aberto, o motorista ainda pode enviar sem a
validação na hora.

## Como funciona no dia a dia

- **Link:** vai na notificação de reserva aprovada e também aparece na reserva.
- **Dados:** o GLPI envia ao aprovar ou alterar uma reserva, ao mudar o KM do
  carro e a cada sincronização (15 min no cron).
- **KM menor:** é bloqueado no formulário e de novo no GLPI.
- **Salto acima do limite:** o motorista confirma, e a linha fica como erro em
  **Auditoria** até o gestor conferir e reprocessar. Esse valor não eleva o KM
  mostrado aos próximos motoristas.
- **Lançamento atrasado:** se outra viagem já subiu o KM do carro, o registro é
  recusado, e o gestor pode reprocessar.
- **Viagem que ainda não chegou ao Google:** o formulário aceita com aviso, e o
  GLPI faz a conferência na sincronização.

## Envio por WhatsApp

Se o plugin `whatsappsimples` estiver ativo, o motorista recebe o link ao aprovar
a viagem. Só é preciso ter o telefone certo no cadastro do motorista. O resultado
aparece junto do link na reserva, onde o gestor também pode reenviar.

O envio não deve abrir chat na fila de atendimento: o ID da mensagem fica na
tabela do `whatsappsimples`, com `chats_id = 0`, para o webhook ignorar o eco. Se o
motorista responder, a resposta abre um chat pendente, como qualquer contato.

## Atualizar o script

Depois de alterar o código, use **Implantar > Gerenciar implantações > lápis >
Versão: Nova versão**. Assim a URL não muda. Criar uma nova implantação gera
outra URL, e os links já enviados param de funcionar.

Publique o script antes de atualizar o plugin no servidor, porque o formato atual
do link (`?v=12-abc`) só é entendido pela versão nova do script.

Não execute outro projeto com outro segredo: os links são calculados a partir
dele, e os já enviados deixariam de valer.

## Problemas comuns

| Mensagem no GLPI ou no formulário | O que fazer |
|---|---|
| Resposta inesperada do Google | Confira se o acesso está como **Qualquer pessoa** e se a URL termina em `/exec` |
| Segredo incorreto | Copie o segredo de novo: execute `configurar` e veja o registro |
| Erro de conexão ou timeout | Libere os dois endereços do Google no servidor do GLPI |
| Link inválido | O segredo mudou ou o link está incompleto: copie um novo na reserva |
| Execute configurar() no editor | A função `configurar` ainda não foi executada |
