/**
 * Reserva de Frota: formulário do motorista.
 *
 * O GLPI fica na rede interna, então é ele quem envia a este script as viagens
 * aprovadas e o KM dos carros (doPost). O motorista abre o link recebido, vê o
 * carro e o último KM, e o registro entra na mesma planilha que o GLPI já lê.
 *
 * Instalação: veja apps-script/README.md.
 */

var FUSO = 'America/Sao_Paulo';
var TAM_PEDACO = 8000; // limite da propriedade é 9 KB por valor
var LOCAL_BASE = 'URE'; // SheetSync::isBaseLocal: chegada na URE conclui a viagem

// ================= Instalação =================

/** Execute uma vez no editor. O segredo aparece no registro de execução. */
function configurar() {
  var planilha = SpreadsheetApp.getActiveSpreadsheet();
  if (!planilha) {
    throw new Error('Crie este script pela planilha: Extensões > Apps Script.');
  }
  var props = PropertiesService.getScriptProperties();
  props.setProperty('SPREADSHEET_ID', planilha.getId());
  var segredo = props.getProperty('SEGREDO');
  if (!segredo) {
    segredo = Utilities.getUuid().replace(/-/g, '') + Utilities.getUuid().replace(/-/g, '');
    props.setProperty('SEGREDO', segredo);
  }
  Logger.log('Planilha: %s', planilha.getName());
  Logger.log('Segredo para colar no GLPI: %s', segredo);
}

// ================= Pontos de entrada =================

function doGet(e) {
  var p = (e && e.parameter) || {};
  var pagina = HtmlService.createTemplateFromFile('Formulario');
  // "<" escapado: o valor vem da URL e entra dentro de um <script>.
  pagina.parametros = JSON.stringify({
    v: String(p.v || ''),
    t: String(p.t || p['amp;t'] || ''),
  }).replace(/</g, '\\u003c');
  return pagina.evaluate()
    .setTitle('Registro de viagem')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1');
}

/** Recebe os dados do GLPI (SheetSync::pushSnapshot). */
function doPost(e) {
  var dados;
  try {
    dados = JSON.parse(e.postData.contents);
  } catch (err) {
    return respostaJson_({ ok: false, error: 'JSON inválido' });
  }
  var segredo = PropertiesService.getScriptProperties().getProperty('SEGREDO');
  if (!segredo || !iguais_(String(dados.secret || ''), segredo)) {
    return respostaJson_({ ok: false, error: 'Segredo incorreto' });
  }
  var trava = LockService.getScriptLock();
  if (!trava.tryLock(20000)) {
    return respostaJson_({ ok: false, error: 'Formulário ocupado, nova tentativa no próximo envio' });
  }
  try {
    var cursor = Number(dados.cursor) || 0;
    gravarJson_('DADOS', {
      trips: dados.trips || [],
      closed: dados.closed || [],
      cars: dados.cars || {},
      drivers: dados.drivers || [],
      max_jump: Number(dados.max_jump) || 0,
      sheet: String(dados.sheet || ''),
    });
    // O GLPI manda a partir de agora, exceto nas linhas que ainda não leu.
    var pendentes = lerJson_('PENDENTES', []).filter(function (p) { return p.row > cursor; });
    gravarJson_('PENDENTES', pendentes);
    // Mesma regra para os encerramentos: o que o GLPI já leu passa a valer pela
    // lista "closed". Se ele recusou a chegada, a viagem volta a aceitar registro.
    gravarJson_('FINALIZADAS', lerJson_('FINALIZADAS', []).filter(function (f) { return f.row > cursor; }));
    return respostaJson_({ ok: true, trips: (dados.trips || []).length });
  } finally {
    trava.releaseLock();
  }
}

function respostaJson_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

// ================= Chamadas da página =================

/** Dados da viagem para o código, o token e a data informados. */
function consultar(codigoBruto, token, dataIso) {
  var codigo = normalizarCodigo_(codigoBruto);
  if (!tokenValido_(codigo, token)) {
    return { ok: false, erro: 'Link inválido. Use o link recebido na aprovação da viagem ou peça um novo ao gestor.' };
  }
  var hoje = Utilities.formatDate(new Date(), FUSO, 'yyyy-MM-dd');
  var data = dataValida_(dataIso) ? dataIso : hoje;
  var dados = lerJson_('DADOS', { trips: [], cars: {}, drivers: [], max_jump: 0, sheet: '' });
  var r = resolverViagem_(dados, codigo, data, lerJson_('FINALIZADAS', []));
  var resposta = {
    ok: true,
    codigo: codigo,
    hoje: hoje,
    agora: Utilities.formatDate(new Date(), FUSO, 'HH:mm'),
    data: data,
    situacao: r.situacao,
    mensagem: r.mensagem || '',
    titulo: r.titulo || '',
    carro: '',
    kmAtual: 0,
    motorista: '',
    motoristas: dados.drivers,
    localBase: LOCAL_BASE,
  };
  if (r.viagem) {
    resposta.motorista = r.viagem.driver || '';
    var carro = dados.cars[String(r.viagem.car)];
    if (carro) {
      resposta.carro = carro.label;
      resposta.kmAtual = kmAtual_(dados, lerJson_('PENDENTES', []), r.viagem.car);
    }
  }
  return resposta;
}

/** Valida e grava um registro. A trava evita dois envios simultâneos. */
function registrar(f) {
  var codigo = normalizarCodigo_(f.codigo);
  if (!tokenValido_(codigo, f.token)) {
    return { ok: false, erro: 'Link inválido.' };
  }
  var entrada = validarEntrada_(f);
  if (entrada.erro) {
    return { ok: false, erro: entrada.erro };
  }
  var cache = CacheService.getScriptCache();
  var idEnvio = 'envio:' + String(f.idEnvio || '').slice(0, 64);
  if (f.idEnvio && cache.get(idEnvio)) {
    return { ok: true, repetido: true }; // toque duplo ou reenvio após queda de rede
  }

  var trava = LockService.getScriptLock();
  if (!trava.tryLock(15000)) {
    return { ok: false, erro: 'Muitos envios ao mesmo tempo. Tente de novo em alguns segundos.' };
  }
  try {
    var dados = lerJson_('DADOS', { trips: [], cars: {}, drivers: [], max_jump: 0, sheet: '' });
    var pendentes = lerJson_('PENDENTES', []);
    var finalizadas = lerJson_('FINALIZADAS', []);
    var r = resolverViagem_(dados, codigo, entrada.data, finalizadas);
    if (r.situacao === 'finalizada') {
      return { ok: false, finalizada: true, erro: r.mensagem, titulo: r.titulo };
    }
    if (r.situacao === 'outra_data' || r.situacao === 'ambigua') {
      return { ok: false, erro: r.mensagem };
    }

    var motorista = entrada.motorista;
    var carId = 0;
    var dentroDoLimite = true;
    if (r.viagem) {
      motorista = r.viagem.driver || motorista;
      carId = Number(r.viagem.car) || 0;
      if (carId && dados.cars[String(carId)]) {
        var atual = kmAtual_(dados, pendentes, carId);
        if (entrada.km < atual) {
          return { ok: false, erro: 'O KM informado é menor que o último registrado (' + formatarKm_(atual) + ').', kmAtual: atual };
        }
        dentroDoLimite = !dados.max_jump || !atual || entrada.km - atual <= dados.max_jump;
        if (!dentroDoLimite && !f.confirmaSalto) {
          return { ok: false, precisaConfirmar: true, kmAtual: atual, salto: entrada.km - atual };
        }
      }
    }
    if (!motorista) {
      return { ok: false, erro: 'Informe o motorista.' };
    }

    var linha = escreverLinha_(dados.sheet, {
      codigo: codigo,
      data: entrada.data,
      hora: entrada.hora,
      local: entrada.local,
      km: entrada.km,
      tipo: entrada.tipo,
      motorista: motorista,
      obs: entrada.obs,
    });

    // Salto acima do limite aguarda o gestor no GLPI, então não eleva o KM
    // mostrado: um erro de digitação não pode bloquear os próximos motoristas.
    if (carId && dentroDoLimite) {
      pendentes.push({ row: linha, car: carId, km: entrada.km });
      gravarJson_('PENDENTES', pendentes);
    }
    var finalizou = entrada.tipo === 'Chegada' && ehLocalBase_(entrada.local);
    if (finalizou) {
      finalizadas.push({
        row: linha,
        code: codigo,
        from: r.viagem ? r.viagem.from : entrada.data,
        to: r.viagem ? (r.viagem.to || r.viagem.from) : entrada.data,
        at: entrada.data + ' ' + entrada.hora,
      });
      gravarJson_('FINALIZADAS', finalizadas);
    }
    if (f.idEnvio) {
      cache.put(idEnvio, '1', 600);
    }
    return { ok: true, aguardaConferencia: !dentroDoLimite, finalizou: finalizou };
  } finally {
    trava.releaseLock();
  }
}

// ================= Regras =================

/**
 * Mesma janela de SheetSync::findBooking: data da viagem ±1 dia.
 *
 * O encerramento vale por viagem, e não por código: as repetições da semana
 * compartilham código e link, e as dos outros dias continuam abertas.
 */
function resolverViagem_(dados, codigo, data, finalizadasLocais) {
  var doCodigo = function (t) { return String(t.code) === codigo; };
  var fechadas = (dados.closed || []).concat(finalizadasLocais || []).filter(doCodigo);
  var mesmaViagem = function (t) {
    return fechadas.some(function (f) { return f.from === t.from && (f.to || f.from) === (t.to || t.from); });
  };
  var abertas = (dados.trips || []).filter(function (t) { return doCodigo(t) && !mesmaViagem(t); });
  var naData = function (t) { return data >= t.from && data <= (t.to || t.from); };
  var naJanelaDe = function (t) { return data >= somarDias_(t.from, -1) && data <= somarDias_(t.to || t.from, 1); };
  var finalizada = function (f) {
    return { situacao: 'finalizada', titulo: tituloEncerramento_(f), mensagem: mensagemFinalizada_(codigo, f) };
  };

  var fechadasNaData = fechadas.filter(naData);
  if (fechadasNaData.length > 0 && !abertas.some(naData)) {
    return finalizada(fechadasNaData[fechadasNaData.length - 1]);
  }
  if (abertas.length === 0) {
    if (fechadas.length > 0) {
      return finalizada(fechadas[fechadas.length - 1]);
    }
    return { situacao: 'sem_dados', mensagem: 'Os dados desta viagem ainda não chegaram aqui. Você pode registrar: o KM será conferido no GLPI.' };
  }
  var naJanela = abertas.filter(naJanelaDe);
  if (naJanela.length === 1) {
    return { situacao: 'ok', viagem: naJanela[0] };
  }
  if (naJanela.length > 1) {
    return { situacao: 'ambigua', mensagem: 'Há mais de uma viagem com este código nesta data. Fale com o gestor.' };
  }
  var fechadasNaJanela = fechadas.filter(naJanelaDe);
  if (fechadasNaJanela.length > 0) {
    return finalizada(fechadasNaJanela[fechadasNaJanela.length - 1]);
  }
  return { situacao: 'outra_data', mensagem: 'Esta viagem é de ' + formatarData_(abertas[0].from) + '. Confira a data informada.' };
}

/** Situações de encerramento enviadas pelo GLPI (SheetSync::buildSnapshot). */
var ENCERRAMENTOS = {
  cancelled: { titulo: 'Viagem cancelada', texto: 'foi cancelada' },
  rejected: { titulo: 'Viagem recusada', texto: 'foi recusada' },
};
var ENCERRAMENTO_PADRAO = { titulo: 'Viagem finalizada', texto: 'já foi finalizada' };

function tituloEncerramento_(f) {
  return (ENCERRAMENTOS[f.kind] || ENCERRAMENTO_PADRAO).titulo;
}

function mensagemFinalizada_(codigo, f) {
  var p = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/.exec(String(f.at || ''));
  return 'A viagem #' + codigo + ' ' + (ENCERRAMENTOS[f.kind] || ENCERRAMENTO_PADRAO).texto +
    (p ? ' em ' + formatarData_(p[1]) + ' às ' + p[2] : '') +
    '. Este link não aceita mais registros.';
}

/** Mesma regra de SheetSync::isBaseLocal: a palavra exata "URE" no local. */
function ehLocalBase_(local) {
  return (' ' + normalizarTexto_(local) + ' ').indexOf(' ' + normalizarTexto_(LOCAL_BASE) + ' ') >= 0;
}

/** Maior valor entre o KM do GLPI e os registros que ele ainda não leu. */
function kmAtual_(dados, pendentes, carId) {
  var carro = dados.cars[String(carId)];
  var km = carro ? Number(carro.km) || 0 : 0;
  pendentes.forEach(function (p) {
    if (Number(p.car) === Number(carId) && Number(p.km) > km) {
      km = Number(p.km);
    }
  });
  return km;
}

function validarEntrada_(f) {
  var tipo = f.tipo === 'chegada' ? 'Chegada' : f.tipo === 'saida' ? 'Saída' : '';
  if (!tipo) {
    return { erro: 'Escolha saída ou chegada.' };
  }
  var km = /^\d{1,7}$/.test(String(f.km || '').trim()) ? Number(f.km) : 0;
  if (km <= 0) {
    return { erro: 'Informe o KM do hodômetro, só com números.' };
  }
  if (!dataValida_(f.data) || !/^([01]\d|2[0-3]):[0-5]\d$/.test(String(f.hora || ''))) {
    return { erro: 'Informe a data e a hora.' };
  }
  var hoje = Utilities.formatDate(new Date(), FUSO, 'yyyy-MM-dd');
  if (f.data > hoje) {
    return { erro: 'A data não pode ser futura.' };
  }
  var local = f.retornoBase ? LOCAL_BASE : textoLimpo_(f.local, 255);
  if (!local) {
    return { erro: 'Informe o local.' };
  }
  return {
    tipo: tipo,
    km: km,
    data: f.data,
    hora: f.hora,
    local: local,
    motorista: textoLimpo_(f.motorista, 255),
    obs: textoLimpo_(f.obs, 1000),
  };
}

function textoLimpo_(valor, limite) {
  return String(valor === undefined || valor === null ? '' : valor)
    .replace(/[\u0000-\u001f\u007f]+/g, ' ')
    .trim()
    .slice(0, limite);
}

function dataValida_(s) {
  return /^\d{4}-\d{2}-\d{2}$/.test(String(s || ''));
}

/** Mesma regra de SheetSync::normCode. */
function normalizarCodigo_(bruto) {
  var s = String(bruto === undefined || bruto === null ? '' : bruto).replace(/[#\s]/g, '');
  if (!/^\d+$/.test(s)) {
    return '';
  }
  return s.replace(/^0+/, '') || '0';
}

function tokenValido_(codigo, token) {
  var segredo = PropertiesService.getScriptProperties().getProperty('SEGREDO');
  return !!(codigo && segredo && iguais_(String(token || ''), calcularToken_(codigo, segredo)));
}

/** Mesmo cálculo de SheetSync::formToken. */
function calcularToken_(codigo, segredo) {
  var hex = Utilities.computeHmacSha256Signature(codigo, segredo).map(function (b) {
    return ('0' + (b & 0xff).toString(16)).slice(-2);
  }).join('');
  return hex.slice(0, 16);
}

function iguais_(a, b) {
  if (a.length !== b.length) {
    return false;
  }
  var dif = 0;
  for (var i = 0; i < a.length; i++) {
    dif |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return dif === 0;
}

function somarDias_(iso, dias) {
  var p = iso.split('-');
  var d = new Date(Date.UTC(Number(p[0]), Number(p[1]) - 1, Number(p[2]) + dias));
  return d.toISOString().slice(0, 10);
}

function formatarData_(iso) {
  var p = String(iso).split('-');
  return p[2] + '/' + p[1] + '/' + p[0];
}

function formatarKm_(km) {
  return String(km).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

// ================= Planilha =================

/** Mesma normalização de SheetSync::norm. */
function normalizarTexto_(s) {
  return String(s === undefined || s === null ? '' : s)
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9 ]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Colunas pela mesma regra de SheetSync::mapHeader. Sem cabeçalho reconhecível,
 * usa a ordem fixa que o GLPI assume nesse caso.
 */
function mapearColunas_(cabecalho) {
  var nomes = {
    ts: ['carimbo de data hora', 'carimbo', 'timestamp', 'data hora', 'enviado em'],
    codigo: ['codigo da viagem', 'codigo', 'cod', 'id', 'viagem'],
    tipo: ['tipo de registro', 'tipo', 'evento', 'event', 'tipo de evento'],
    motorista: ['motorista', 'driver', 'condutor', 'nome do motorista'],
    km: ['km do hodometro', 'km', 'hodometro', 'quilometragem', 'km atual'],
    obs: ['observacao', 'obs', 'comentario', 'observacoes'],
    quando: ['datahora', 'data e hora', 'quando', 'horario', 'data do evento', 'datahora do evento'],
    data: ['data', 'dia'],
    hora: ['hora', 'horario evento'],
    local: ['local', 'lugar', 'destino', 'origem', 'parada', 'place', 'location', 'escola'],
    marcador: ['chegada'],
  };
  var mapa = {};
  cabecalho.forEach(function (coluna, i) {
    var n = normalizarTexto_(coluna);
    Object.keys(nomes).some(function (chave) {
      if (mapa[chave] === undefined && nomes[chave].indexOf(n) >= 0) {
        mapa[chave] = i;
        return true;
      }
      return false;
    });
  });
  var temData = mapa.quando !== undefined || mapa.data !== undefined;
  var temTipo = mapa.tipo !== undefined || mapa.marcador !== undefined;
  if (mapa.codigo === undefined || mapa.km === undefined || mapa.motorista === undefined || !temData || !temTipo) {
    return { ts: 0, codigo: 1, data: 2, hora: 3, local: 4, km: 5, tipo: 6, motorista: 7, obs: 8 };
  }
  if (mapa.ts === undefined) {
    mapa.ts = 0;
  }
  return mapa;
}

function montarLinha_(mapa, tamanhoAtual, r, carimbo) {
  var dataHora = formatarData_(r.data) + ' ' + r.hora + ':00';
  var maior = tamanhoAtual;
  Object.keys(mapa).forEach(function (k) { maior = Math.max(maior, mapa[k] + 1); });
  var linha = [];
  for (var i = 0; i < maior; i++) {
    linha.push('');
  }
  var por = function (chave, valor) {
    if (mapa[chave] !== undefined) {
      linha[mapa[chave]] = valor;
    }
  };
  por('ts', carimbo);
  por('codigo', r.codigo);
  por('quando', dataHora);
  por('data', formatarData_(r.data));
  por('hora', r.hora + ':00');
  por('local', r.local);
  por('km', String(r.km));
  por('tipo', r.tipo);
  por('marcador', r.tipo === 'Chegada' ? 'X' : '');
  por('motorista', r.motorista);
  por('obs', r.obs);
  return linha;
}

function escreverLinha_(nomeAba, registro) {
  var props = PropertiesService.getScriptProperties();
  var id = props.getProperty('SPREADSHEET_ID');
  if (!id) {
    throw new Error('Execute configurar() no editor do Apps Script.');
  }
  var planilha = SpreadsheetApp.openById(id);
  var aba = (nomeAba && planilha.getSheetByName(nomeAba)) || planilha.getSheets()[0];
  var colunas = Math.max(aba.getLastColumn(), 1);
  var mapa = mapearColunas_(aba.getRange(1, 1, 1, colunas).getDisplayValues()[0]);
  var carimbo = Utilities.formatDate(new Date(), FUSO, 'dd/MM/yyyy HH:mm:ss');
  var valores = montarLinha_(mapa, colunas, registro, carimbo);

  var numero = aba.getLastRow() + 1;
  if (numero > aba.getMaxRows()) {
    aba.insertRowAfter(aba.getMaxRows());
  }
  // Texto puro: evita que o Sheets reinterprete a data ou execute fórmula digitada.
  var intervalo = aba.getRange(numero, 1, 1, valores.length);
  intervalo.setNumberFormat('@');
  intervalo.setValues([valores]);
  SpreadsheetApp.flush();
  return numero;
}

// ================= Armazenamento =================

/**
 * JSON em pedaços nas propriedades do script, que só os editores do projeto
 * veem. A planilha de respostas costuma estar aberta por link para a chave de
 * API, então os dados dos carros não ficam nela.
 */
function gravarJson_(nome, valor) {
  // Só ASCII, para cada caractere ocupar 1 byte no limite por valor.
  var texto = JSON.stringify(valor).replace(/[\u0080-￿]/g, function (c) {
    return '\\u' + ('000' + c.charCodeAt(0).toString(16)).slice(-4);
  });
  var props = PropertiesService.getScriptProperties();
  var anterior = Number(props.getProperty(nome + ':n')) || 0;
  var novos = {};
  var n = Math.ceil(texto.length / TAM_PEDACO);
  for (var i = 0; i < n; i++) {
    novos[nome + ':' + i] = texto.slice(i * TAM_PEDACO, (i + 1) * TAM_PEDACO);
  }
  novos[nome + ':n'] = String(n);
  props.setProperties(novos);
  for (var j = n; j < anterior; j++) {
    props.deleteProperty(nome + ':' + j);
  }
}

function lerJson_(nome, padrao) {
  var props = PropertiesService.getScriptProperties();
  var n = Number(props.getProperty(nome + ':n')) || 0;
  if (n === 0) {
    return padrao;
  }
  var texto = '';
  for (var i = 0; i < n; i++) {
    texto += props.getProperty(nome + ':' + i) || '';
  }
  try {
    return JSON.parse(texto);
  } catch (err) {
    return padrao;
  }
}
