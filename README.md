# Reserva de Frota (reservafrota)

Plugin para **GLPI 11.0.x** de **reserva de veiculos da frota**.

## Funcionalidades

### Gestor / Administrador
- **Cadastrar veiculos**: modelo, placa, ano, foto, observacoes
- **Ativar / Inativar veiculos**: tire de circulacao para manutencao com um clique (is_active)
- **Aprovar / Recusar / Cancelar reservas**: painel de aprovacao com escolha do carro disponivel no horario
- **Confirmar chegada**: marca retorno, anexa folha, registra KM final e observacao
- **Visao gerencial**: analise mensal, relatorios por setor/carro, exportacao XLSX

### Usuario
- **Reservar veiculos**: escolha data/hora de saida (chegada opcional), destino, motivo, motorista e acompanhante
- **Ver disponibilidade**: calendario mensal mostra por dia quais carros estao livres, pendentes, aprovados, concluidos, em conflito ou cancelados
- **Agenda do dia**: detalhes por carro (foto, placa) com lista de quem esta usando em cada horario
- **Acompanhar solicitacoes**: listas agrupadas por status (pendentes, aprovadas, recusadas, canceladas, concluidas) abaixo do calendario
- **Cancelar propria reserva** com motivo

### Calendario
- Navegacao mensal, botao Hoje, legenda de cores
- Cada dia mostra chips coloridos das reservas (pendente=amarelo, aprovado=verde, conflito=vermelho, etc)
- Ao clicar no dia, modal com duas colunas: reservas do dia + formulario de nova reserva
- Verificacao de conflito em tempo real via AJAX (conflict.php, carsstatus.php)
- Apenas carros **ativos** (is_active=1) e **sem sobreposicao com reservas aprovadas** sao oferecidos como disponiveis

## Requisitos

- GLPI **11.0.0** a **11.0.99**
- PHP compativel com o GLPI 11

## Instalacao

1. Copie a pasta `reservafrota/` para o diretorio de plugins do GLPI:
   ```
   glpi/plugins/reservafrota
   ```
   ou `glpi/marketplace/reservafrota`
2. No GLPI, va em **Configurar > Plugins**.
3. Clique em **Instalar** e depois **Ativar** em "Reserva de Frota".

Na instalacao sao criadas as tabelas `glpi_plugin_reservafrota_cars` e `glpi_plugin_reservafrota_bookings`,
e todos os direitos sao concedidos ao perfil que esta instalando (super-admin).

## Permissoes

Va em **Administracao > Perfis > [perfil] > aba Reserva de Frota**:

- **Veiculos (`reservafrota::car`)**
  - `Sem acesso` / `Visualizar` / `Visualizar e cadastrar` / `Cadastrar e editar` / `Acesso total`
- **Reservas (`reservafrota::booking`)**
  - `Sem acesso` / `Ver proprias` / `Criar` / `Criar e aprovar (gestor)` / `Acesso total`

> Quem **nao** tem aprovacao enxerga apenas as proprias reservas na listagem, mas ve a ocupacao de todos os carros no calendario.

## Uso

Menu **Ferramentas > Reserva de Frota** (ou atalho na interface Helpdesk):

- **Calendario**: tela principal. Veja o mes, clique num dia para reservar.
- **Frota** (admin): lista todos os veiculos (ativos primeiro), com toggle ativo/inativo e foto.
- **Analise** (gestor): graficos e tabelas do mes + exportacao anual.
- **Historico**: linha do tempo de todas as reservas (ou so as proprias para usuario comum).

## Estrutura

```
reservafrota/
├── setup.php
├── hook.php
├── reservafrota.xml
├── src/
│   ├── Car.php
│   ├── Booking.php
│   ├── Profile.php
│   └── XlsxExporter.php
├── front/
│   ├── calendar.php
│   ├── car.php / car.form.php
│   ├── booking.form.php
│   ├── analytics.php / history.php / export.php
│   └── car.picture.php / sheet.php
├── ajax/
│   ├── month.php / carsstatus.php / conflict.php / carslot.php / bookinglist.php / pending.php
├── templates/
│   ├── calendar.html.twig
│   ├── car.form.html.twig / car.list.html.twig
│   ├── booking.form.html.twig / booking.lists.html.twig
│   └── history.html.twig / analytics.html.twig
└── public/
    ├── css/reservafrota.css
    └── js/calendar.js / agenda.js / analytics.js
```

## Notas GLPI 11

- Classes em `src/` no namespace `GlpiPlugin\Reservafrota`
- Assets em `public/` (exigencia GLPI 11)
- Twig `@reservafrota/...`
- Query builder `$DB->request()`
- Firewall `STRATEGY_AUTHENTICATED` para paginas Helpdesk
- CSRF `csrf_compliant = true`

## Licenca

MIT.
