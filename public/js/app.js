// public/js/app.js - Aplicação React Responsiva de Controle de Formatura com Perfis (Admin / Comum)
const { useState, useEffect, useMemo } = React;

// Funções Auxiliares - Máscara de Telefone (ex: (41) 99248-9676)
const maskPhone = (value) => {
  if (!value) return '';
  const digits = value.replace(/\D/g, '').slice(0, 11);
  if (digits.length <= 2) {
    return digits.length > 0 ? `(${digits}` : '';
  }
  if (digits.length <= 6) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
  }
  if (digits.length <= 10) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
  }
  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7, 11)}`;
};

// Gerador Oficial de Payload Pix EMV (Padrão Banco Central do Brasil / BR Code Estático)
const generatePixPayload = (chavePix, nomeRecebedor = 'FORMATURA 2026', cidade = 'CURITIBA', valor = 0, txid = '***') => {
  if (!chavePix) return '';
  chavePix = chavePix.trim();
  nomeRecebedor = (nomeRecebedor || 'FORMATURA 2026').normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-zA-Z0-9 ]/g, "").toUpperCase().slice(0, 25) || 'FORMATURA 2026';
  cidade = (cidade || 'CURITIBA').normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-zA-Z0-9 ]/g, "").toUpperCase().slice(0, 15) || 'CURITIBA';

  const formatTLV = (id, value) => {
    const len = String(value.length).padStart(2, '0');
    return `${id}${len}${value}`;
  };

  const gui = formatTLV('00', 'br.gov.bcb.pix');
  const key = formatTLV('01', chavePix);
  const merchantAccountInfo = formatTLV('26', gui + key);

  const payloadFormatIndicator = formatTLV('00', '01');
  const merchantCategoryCode = formatTLV('52', '0000');
  const transactionCurrency = formatTLV('53', '986');
  
  let transactionAmount = '';
  if (valor > 0) {
    transactionAmount = formatTLV('54', parseFloat(valor).toFixed(2));
  }

  const countryCode = formatTLV('58', 'BR');
  const merchantName = formatTLV('59', nomeRecebedor);
  const merchantCity = formatTLV('60', cidade);
  const additionalDataField = formatTLV('62', formatTLV('05', txid || '***'));

  const rawPayload = 
    payloadFormatIndicator +
    merchantAccountInfo +
    merchantCategoryCode +
    transactionCurrency +
    transactionAmount +
    countryCode +
    merchantName +
    merchantCity +
    additionalDataField +
    '6304';

  let crc = 0xFFFF;
  for (let i = 0; i < rawPayload.length; i++) {
    crc ^= rawPayload.charCodeAt(i) << 8;
    for (let j = 0; j < 8; j++) {
      if ((crc & 0x8000) !== 0) {
        crc = (crc << 1) ^ 0x1021;
      } else {
        crc = crc << 1;
      }
      crc &= 0xFFFF;
    }
  }

  return rawPayload + crc.toString(16).toUpperCase().padStart(4, '0');
};

// Dados Mocks de Demonstração (Foto controle.jpg) para Preview Estático se PHP não estiver rodando localmente
const MOCK_CONFIG = {
  titulo_formatura: 'Formatura 2026',
  valor_pessoa_extra: 80,
  max_parcelas: 12,
  chave_pix: 'formatura2026@escola.com',
  chaves_pix: JSON.stringify([
    { nome: 'Pix APMF', chave: 'apmf@gmail.com' },
    { nome: 'Pix Colégio', chave: 'colegio@gmail.com' }
  ]),
  formas_pagamento: 'Pix APMF, Pix Colégio, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'
};

const MOCK_FORMANDOS = [
  {
    id: 1,
    numero_aluno: 1,
    nome: 'Kauan da Silva',
    cgm: '2026001',
    turma: '3º B',
    telefone: '(41) 99248-9676',
    convidados_extra: 8,
    valor_base: 0,
    valor_extra_unitario: 80,
    valor_total_a_pagar: 640,
    total_pago: 0,
    saldo_devedor: 640,
    pagamentos: []
  },
  {
    id: 2,
    numero_aluno: 2,
    nome: 'Matheus Henrique',
    cgm: '2026002',
    turma: '3º B',
    telefone: '(41) 99555-5815',
    convidados_extra: 5,
    valor_base: 0,
    valor_extra_unitario: 80,
    valor_total_a_pagar: 400,
    total_pago: 200,
    saldo_devedor: 200,
    pagamentos: [
      { id: 1, formando_id: 2, numero_parcela: 1, data_pagamento: '2026-09-02', valor: 200, forma_pagamento: 'Pix', observacao: 'Pago via Pix' }
    ]
  }
];

// COMPONENTE REUTILIZÁVEL DE DATATABLE RESPONSIVA (COM PESQUISA, ORDENAÇÃO E PAGINAÇÃO)
function DataTable({ columns, data, keyField = 'id', defaultPageSize = 10, emptyMessage = 'Nenhum registro encontrado.' }) {
  const [search, setSearch] = useState('');
  const [sortColumnIdx, setSortColumnIdx] = useState(null);
  const [sortDirection, setSortDirection] = useState('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [pageSize, setPageSize] = useState(defaultPageSize);

  // Filtrar
  const filteredData = useMemo(() => {
    if (!search.trim()) return data;
    const q = search.toLowerCase().trim();
    return data.filter(row => {
      return columns.some(col => {
        if (col.sortable === false && !col.accessor) return false;
        const val = col.searchVal ? col.searchVal(row) : (typeof col.accessor === 'function' ? col.accessor(row) : row[col.accessor]);
        if (val === null || val === undefined) return false;
        return String(val).toLowerCase().includes(q);
      });
    });
  }, [data, columns, search]);

  // Ordenar
  const sortedData = useMemo(() => {
    if (sortColumnIdx === null || !columns[sortColumnIdx]) return filteredData;
    const col = columns[sortColumnIdx];
    const sorted = [...filteredData].sort((a, b) => {
      const valA = typeof col.accessor === 'function' ? col.accessor(a) : a[col.accessor];
      const valB = typeof col.accessor === 'function' ? col.accessor(b) : b[col.accessor];

      if (valA === valB) return 0;
      if (valA === null || valA === undefined) return 1;
      if (valB === null || valB === undefined) return -1;

      if (typeof valA === 'number' && typeof valB === 'number') {
        return sortDirection === 'asc' ? valA - valB : valB - valA;
      }
      return sortDirection === 'asc'
        ? String(valA).localeCompare(String(valB), 'pt-BR', { numeric: true })
        : String(valB).localeCompare(String(valA), 'pt-BR', { numeric: true });
    });
    return sorted;
  }, [filteredData, sortColumnIdx, sortDirection, columns]);

  // Paginar
  const totalRecords = sortedData.length;
  const totalPages = Math.ceil(totalRecords / pageSize) || 1;
  const safePage = Math.min(currentPage, totalPages);
  
  const pageData = useMemo(() => {
    const start = (safePage - 1) * pageSize;
    return sortedData.slice(start, start + pageSize);
  }, [sortedData, safePage, pageSize]);

  const handleSort = (idx) => {
    const col = columns[idx];
    if (col.sortable === false) return;
    if (sortColumnIdx === idx) {
      setSortDirection(prev => (prev === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortColumnIdx(idx);
      setSortDirection('asc');
    }
  };

  return (
    <div className="datatable-container">
      {/* Barra de Controles (Qtd por Página e Busca) */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem', flexWrap: 'wrap', gap: '0.75rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontSize: '0.85rem', color: '#cbd5e1' }}>
          <span>Exibir</span>
          <select
            className="form-control"
            style={{ width: 'auto', padding: '0.3rem 0.6rem', fontSize: '0.85rem' }}
            value={pageSize}
            onChange={e => { setPageSize(Number(e.target.value)); setCurrentPage(1); }}
          >
            <option value={5}>5</option>
            <option value={10}>10</option>
            <option value={25}>25</option>
            <option value={50}>50</option>
            <option value={100}>100</option>
          </select>
          <span>registros</span>
        </div>

        <div style={{ minWidth: '220px' }}>
          <input
            type="text"
            className="form-control"
            style={{ padding: '0.4rem 0.75rem', fontSize: '0.85rem' }}
            placeholder="🔍 Pesquisar na tabela..."
            value={search}
            onChange={e => { setSearch(e.target.value); setCurrentPage(1); }}
          />
        </div>
      </div>

      {/* Tabela de Dados */}
      <div className="table-responsive">
        <table className="custom-table datatable">
          <thead>
            <tr>
              {columns.map((col, idx) => (
                <th
                  key={idx}
                  style={{
                    cursor: col.sortable !== false ? 'pointer' : 'default',
                    userSelect: 'none',
                    width: col.width || 'auto'
                  }}
                  onClick={() => handleSort(idx)}
                >
                  <div style={{ display: 'inline-flex', alignItems: 'center', gap: '0.3rem' }}>
                    {col.header}
                    {col.sortable !== false && (
                      <span style={{ fontSize: '0.75rem', opacity: sortColumnIdx === idx ? 1 : 0.4, color: sortColumnIdx === idx ? '#818cf8' : 'inherit' }}>
                        {sortColumnIdx === idx ? (sortDirection === 'asc' ? '▲' : '▼') : '⇅'}
                      </span>
                    )}
                  </div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {pageData.length === 0 ? (
              <tr>
                <td colSpan={columns.length} style={{ textAlign: 'center', padding: '2rem', color: '#94a3b8' }}>
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              pageData.map((row, rowIdx) => (
                <tr
                  key={row[keyField] || rowIdx}
                  style={row.onRowClick ? { cursor: 'pointer' } : {}}
                  onClick={() => row.onRowClick && row.onRowClick(row)}
                >
                  {columns.map((col, colIdx) => (
                    <td key={colIdx} style={col.cellStyle ? col.cellStyle(row) : {}}>
                      {col.render ? col.render(row) : (typeof col.accessor === 'function' ? col.accessor(row) : row[col.accessor])}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Rodapé com Contador e Paginação */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '1rem', flexWrap: 'wrap', gap: '0.75rem', fontSize: '0.85rem', color: '#94a3b8' }}>
        <div>
          {totalRecords > 0 ? (
            `Mostrando ${Math.min((safePage - 1) * pageSize + 1, totalRecords)} até ${Math.min(safePage * pageSize, totalRecords)} de ${totalRecords} registros`
          ) : (
            'Nenhum registro para exibir'
          )}
        </div>

        {totalPages > 1 && (
          <div style={{ display: 'flex', gap: '0.25rem', alignItems: 'center' }}>
            <button
              className="btn btn-secondary btn-sm"
              disabled={safePage === 1}
              onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
            >
              Anterior
            </button>
            {Array.from({ length: totalPages }, (_, i) => i + 1)
              .filter(p => p === 1 || p === totalPages || Math.abs(p - safePage) <= 1)
              .map((p, i, arr) => {
                const prevP = arr[i - 1];
                return (
                  <React.Fragment key={p}>
                    {prevP && p - prevP > 1 && <span style={{ padding: '0.2rem 0.4rem', color: '#64748b' }}>...</span>}
                    <button
                      className={`btn btn-sm ${safePage === p ? 'btn-primary' : 'btn-secondary'}`}
                      onClick={() => setCurrentPage(p)}
                    >
                      {p}
                    </button>
                  </React.Fragment>
                );
              })}
            <button
              className="btn btn-secondary btn-sm"
              disabled={safePage === totalPages}
              onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))}
            >
              Próximo
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

const parseChavesPix = (chavesPixRaw) => {
  if (!chavesPixRaw) return [];
  if (Array.isArray(chavesPixRaw)) return chavesPixRaw;
  if (typeof chavesPixRaw === 'string') {
    try {
      const parsed = JSON.parse(chavesPixRaw);
      if (Array.isArray(parsed)) return parsed;
    } catch (e) {}
    const lines = chavesPixRaw.split('\n');
    const result = [];
    lines.forEach(line => {
      const parts = line.split(/[:|]/);
      if (parts.length >= 2) {
        result.push({ nome: parts[0].trim(), chave: parts[1].trim() });
      }
    });
    return result;
  }
  return [];
};

const getPixKeysList = (config) => {
  const parsed = parseChavesPix(config?.chaves_pix);
  if (parsed.length > 0) return parsed;
  return [{ nome: 'Pix Padrão', chave: config?.chave_pix || 'formatura2026@escola.com' }];
};

const findPixKeyForForma = (formaPagamento, config) => {
  if (!formaPagamento) return config?.chave_pix || 'formatura2026@escola.com';
  const list = getPixKeysList(config);
  const matched = list.find(item => item.nome.trim().toLowerCase() === formaPagamento.trim().toLowerCase());
  if (matched && matched.chave) return matched.chave;
  return config?.chave_pix || 'formatura2026@escola.com';
};

function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [view, setView] = useState('login'); // 'login', 'dashboard', 'configuracoes', 'formando_detail', 'usuarios'
  const [formandoId, setFormandoId] = useState(null);

  // Estados Globais
  const [theme, setTheme] = useState(localStorage.getItem('app_theme') || 'dark');
  const [config, setConfig] = useState(MOCK_CONFIG);
  const [resumo, setResumo] = useState({
    total_formandos: 2,
    total_previsto: 4040,
    total_arrecadado: 200,
    total_pendente: 3840
  });

  useEffect(() => {
    if (theme === 'light') {
      document.body.classList.add('theme-light');
    } else {
      document.body.classList.remove('theme-light');
    }
    localStorage.setItem('app_theme', theme);
  }, [theme]);
  const [formandos, setFormandos] = useState(MOCK_FORMANDOS);
  const [busca, setBusca] = useState('');
  const [turmaFiltro, setTurmaFiltro] = useState('');

  // Estados de Usuários
  const [listaUsuarios, setListaUsuarios] = useState([]);
  const [novoUsuarioData, setNovoUsuarioData] = useState({ id: null, nome: '', usuario: '', senha: '', perfil: 'comum' });

  // Estados de Turmas
  const [listaTurmas, setListaTurmas] = useState([]);
  const [novaTurmaData, setNovaTurmaData] = useState({ id: null, nome: '', observacao: '' });

  // Estados do Formando Selecionado
  const [formandoAtual, setFormandoAtual] = useState(null);
  const [simularParcelas, setSimularParcelas] = useState(6);

  // Modais
  const [showModalFormando, setShowModalFormando] = useState(false);
  const [modalFormandoData, setModalFormandoData] = useState({ id: null, numero_aluno: '', nome: '', cgm: '', turma: '', telefone: '', convidados_extra: 0, participa_formatura: 1, observacoes: '' });

  // Estados de Importação Inteligente & Relação Geral de Alunos
  const [todosAlunos, setTodosAlunos] = useState([]);
  const [textoImportacao, setTextoImportacao] = useState('');
  const [turmaImportacaoPadrao, setTurmaImportacaoPadrao] = useState('3º A');
  const [showModalSelecaoParticipante, setShowModalSelecaoParticipante] = useState(false);
  const [alunoSelecaoData, setAlunoSelecaoData] = useState({ id: null, numero_aluno: '', nome: '', cgm: '', turma: '', telefone: '', convidados_extra: 0, participa_formatura: 1 });

  const loadListaCompletaAlunos = async () => {
    try {
      const res = await fetch('../api/formandos.php?lista_completa=1');
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        setTodosAlunos(data.formandos || []);
      }
    } catch (e) {}
  };

  const handleFileUploadCSV = (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (event) => {
      setTextoImportacao(event.target.result || '');
    };
    reader.readAsText(file, 'UTF-8');
  };

  const handleImportarInteligente = async (e) => {
    e.preventDefault();
    if (!textoImportacao.trim()) {
      alert('⚠️ Por favor, cole a lista de alunos ou selecione um arquivo CSV para importar.');
      return;
    }

    const linhas = textoImportacao.split('\n');
    const alunosParaEnviar = [];

    // Tenta detectar se a primeira linha é um cabeçalho CSV
    let hasHeader = false;
    let colMap = { numero: -1, nome: -1, cgm: -1, turma: -1 };

    if (linhas.length > 0) {
      const firstLineHeader = linhas[0].toLowerCase();
      if (firstLineHeader.includes('nome') || firstLineHeader.includes('cgm') || firstLineHeader.includes('turma') || firstLineHeader.includes('numero') || firstLineHeader.includes('nº')) {
        hasHeader = true;
        const headerCols = linhas[0].split(/[-–;,|\t]/).map(h => h.trim().toLowerCase());
        headerCols.forEach((h, idx) => {
          if (h.includes('numero') || h.includes('nº') || h.includes('num') || h.includes('chamada') || h === 'n') colMap.numero = idx;
          else if (h.includes('nome') || h.includes('aluno')) colMap.nome = idx;
          else if (h.includes('cgm') || h.includes('matricula')) colMap.cgm = idx;
          else if (h.includes('turma') || h.includes('classe') || h.includes('serie')) colMap.turma = idx;
        });
      }
    }

    for (let i = hasHeader ? 1 : 0; i < linhas.length; i++) {
      let linha = linhas[i].trim();
      if (!linha) continue;

      let numero_aluno = null;
      let nome = '';
      let cgm = '';
      let turma = turmaImportacaoPadrao || '3º A';

      // Tenta extrair o número da chamada no início da linha (ex: "1. João", "01 - Maria", "2) Ana")
      const matchLeadingNum = linha.match(/^(\d{1,3})[\.\)\s\-\–]+\s*(.*)$/);
      if (matchLeadingNum && !hasHeader) {
        numero_aluno = parseInt(matchLeadingNum[1]);
        linha = matchLeadingNum[2].trim();
      }

      const partes = linha.split(/[-–;,|\t]/).map(p => p.trim());

      if (hasHeader && colMap.nome !== -1) {
        nome = partes[colMap.nome] || '';
        if (colMap.cgm !== -1) cgm = partes[colMap.cgm] || '';
        if (colMap.turma !== -1 && partes[colMap.turma]) turma = partes[colMap.turma];
        if (colMap.numero !== -1 && partes[colMap.numero]) {
          const parsedNum = parseInt(partes[colMap.numero]);
          if (!isNaN(parsedNum)) numero_aluno = parsedNum;
        }
      } else {
        const nonCols = partes.filter(Boolean);
        if (nonCols.length >= 4) {
          if (/^\d{1,3}$/.test(nonCols[0])) {
            if (numero_aluno === null) numero_aluno = parseInt(nonCols[0]);
            nome = nonCols[1];
            cgm = nonCols[2];
            turma = nonCols[3];
          } else {
            nome = nonCols[0];
            cgm = nonCols[1];
            turma = nonCols[2];
          }
        } else if (nonCols.length === 3) {
          if (/^\d{1,3}$/.test(nonCols[0])) {
            if (numero_aluno === null) numero_aluno = parseInt(nonCols[0]);
            nome = nonCols[1];
            if (/^\d{4,15}$/.test(nonCols[2])) cgm = nonCols[2];
            else turma = nonCols[2];
          } else if (/^\d{4,15}$/.test(nonCols[1])) {
            nome = nonCols[0];
            cgm = nonCols[1];
            turma = nonCols[2];
          } else {
            nome = nonCols[0];
            turma = nonCols[1];
            if (/^\d{4,15}$/.test(nonCols[2])) cgm = nonCols[2];
            else if (/^\d{1,3}$/.test(nonCols[2]) && numero_aluno === null) numero_aluno = parseInt(nonCols[2]);
          }
        } else if (nonCols.length === 2) {
          if (/^\d{1,3}$/.test(nonCols[0])) {
            if (numero_aluno === null) numero_aluno = parseInt(nonCols[0]);
            nome = nonCols[1];
          } else if (/^\d{4,15}$/.test(nonCols[1])) {
            nome = nonCols[0];
            cgm = nonCols[1];
          } else {
            nome = nonCols[0];
            turma = nonCols[1];
          }
        } else if (nonCols.length === 1) {
          nome = nonCols[0];
        }
      }

      // Se nenhum número foi informado explicitamente, atribui o número sequencial da lista
      if (numero_aluno === null) {
        numero_aluno = alunosParaEnviar.length + 1;
      }

      if (nome) {
        alunosParaEnviar.push({
          numero_aluno: numero_aluno,
          nome: nome,
          cgm: cgm,
          turma: turma,
          telefone: '',
          convidados_extra: 0,
          participa_formatura: 0
        });
      }
    }

    if (alunosParaEnviar.length === 0) {
      alert('⚠️ Nenhum aluno válido foi identificado na lista.');
      return;
    }

    try {
      const res = await fetch('../api/formandos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'import_bulk',
          alunos_importados: alunosParaEnviar
        })
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'success') {
        alert(`✅ ${data.message || `${data.imported_count || alunosParaEnviar.length} aluno(s) processados com sucesso!`}`);
        setTextoImportacao('');
        loadListaCompletaAlunos();
        loadTurmas();
        loadDashboardData();
      } else {
        alert(data.message || 'Erro ao importar alunos.');
      }
    } catch (err) {
      alert('Erro de conexão ao realizar importação inteligente.');
    }
  };

  const handleLimparTodosAlunos = async () => {
    if (!isAdmin) {
      alert('Acesso negado. Apenas administradores podem limpar a lista de alunos.');
      return;
    }
    if (!confirm('⚠️ ATENÇÃO: Tem certeza de que deseja EXCLUIR TODOS OS ALUNOS cadastrados e todo o histórico de pagamentos?\n\nEsta ação não poderá ser desfeita e permitirá reimportar a lista do zero.')) {
      return;
    }
    try {
      const res = await fetch('../api/formandos.php?limpar_todos=1', { method: 'DELETE' });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        alert('✨ Todos os alunos e pagamentos foram limpos com sucesso!');
        loadListaCompletaAlunos();
        loadTurmas();
        loadDashboardData();
      } else {
        alert(data.message || 'Erro ao limpar alunos.');
      }
    } catch (e) {
      alert('Erro de conexão ao tentar limpar alunos.');
    }
  };

  const handleToggleParticipacao = (aluno) => {
    if (aluno.participa_formatura === 1) {
      if (confirm(`Deseja alterar o status do aluno "${aluno.nome}" para "Não Formando"? Ele deixará de aparecer na tela de Gerenciamento de Pagamentos.`)) {
        salvarStatusParticipacao(aluno.id, 0, aluno.telefone || '', aluno.convidados_extra || 0, aluno.cgm || '', aluno.numero_aluno || '', aluno.nome || '', aluno.turma || '');
      }
    } else {
      setAlunoSelecaoData({
        id: aluno.id,
        numero_aluno: aluno.numero_aluno || '',
        nome: aluno.nome,
        cgm: aluno.cgm || '',
        turma: aluno.turma,
        telefone: aluno.telefone || '',
        convidados_extra: aluno.convidados_extra || 0,
        participa_formatura: 1
      });
      setShowModalSelecaoParticipante(true);
    }
  };

  const salvarStatusParticipacao = async (id, participa, telefone, convidadosExtra, cgm = '', numeroAluno = '', nome = '', turma = '') => {
    try {
      const res = await fetch('../api/formandos.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: id,
          only_participacao: true,
          participa_formatura: participa,
          telefone: telefone,
          convidados_extra: convidadosExtra,
          cgm: cgm,
          numero_aluno: numeroAluno,
          nome: nome,
          turma: turma
        })
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'success') {
        loadListaCompletaAlunos();
        loadTurmas();
        loadDashboardData();
        if (participa === 1) {
          setShowModalSelecaoParticipante(false);
          alert(`✨ Status do aluno alterado para "Formando" com sucesso!\nEle já aparece na tela de Gerenciamento de Pagamentos.`);
        }
      } else {
        alert(data.message || 'Erro ao atualizar participação.');
      }
    } catch (e) {
      alert('Erro de conexão ao salvar participação.');
    }
  };
  
  const loadTurmas = async () => {
    try {
      const res = await fetch('../api/turmas.php');
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        setListaTurmas(data.turmas || []);
      }
    } catch (e) {}
  };

  const handleSaveTurma = async (e) => {
    e.preventDefault();
    const isEdit = !!novaTurmaData.id;
    const method = isEdit ? 'PUT' : 'POST';
    try {
      const res = await fetch('../api/turmas.php', {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(novaTurmaData)
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        alert(isEdit ? 'Turma atualizada com sucesso!' : 'Turma cadastrada com sucesso!');
        setNovaTurmaData({ id: null, nome: '', observacao: '' });
        loadTurmas();
        loadDashboardData();
      } else {
        alert(data.message || 'Erro ao salvar turma.');
      }
    } catch (e) {
      alert('Erro de conexão ao salvar turma.');
    }
  };

  const handleExcluirTurma = async (id) => {
    if (!confirm('Deseja realmente excluir esta turma?')) return;
    try {
      const res = await fetch(`../api/turmas.php?id=${id}`, { method: 'DELETE' });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        loadTurmas();
        loadDashboardData();
      } else {
        alert(data.message || 'Erro ao excluir turma.');
      }
    } catch (e) {
      alert('Erro de conexão ao excluir turma.');
    }
  };
  
  const [showModalPagamento, setShowModalPagamento] = useState(false);
  const [modalPagamentoData, setModalPagamentoData] = useState({ numero_parcela: 1, data_pagamento: new Date().toISOString().split('T')[0], valor: 200, forma_pagamento: 'Pix', observacao: '' });

  // Login Form
  const [loginCreds, setLoginCreds] = useState({ usuario: '', senha: '' });
  const [loginError, setLoginError] = useState('');

  const isAdmin = user ? (user.perfil === 'admin' || !user.perfil) : false;

  // Checar Autenticação Inicial
  useEffect(() => {
    checkAuth();
  }, []);

  const recalculateResumo = (listaFormandos, currentConfig) => {
    let tPrev = 0, tArr = 0, tPend = 0, tExtras = 0;
    const unitValue = currentConfig.valor_pessoa_extra || currentConfig.valor_total_base || 80;

    const list = listaFormandos.map(f => {
      const convExtra = Math.max(1, parseInt(f.convidados_extra) || 1);
      tExtras += convExtra;
      const vTotal = convExtra * unitValue;
      let tPago = 0;
      if (f.pagamentos) {
        tPago = f.pagamentos.reduce((acc, p) => acc + parseFloat(p.valor || 0), 0);
      } else {
        tPago = parseFloat(f.total_pago || 0);
      }
      const sDev = Math.max(0, vTotal - tPago);

      tPrev += vTotal;
      tArr += tPago;
      tPend += sDev;

      return {
        ...f,
        convidados_extra: convExtra,
        valor_base: 0,
        valor_extra_unitario: unitValue,
        valor_total_a_pagar: vTotal,
        total_pago: tPago,
        saldo_devedor: sDev
      };
    });

    const totalFormandos = list.length;
    const totalPessoasEvento = tExtras;

    setFormandos(list);
    setResumo({
      total_formandos: totalFormandos,
      total_extras: tExtras,
      total_pessoas_evento: totalPessoasEvento,
      total_previsto: tPrev,
      total_arrecadado: tArr,
      total_pendente: tPend
    });
    return list;
  };

  const checkAuth = async () => {
    try {
      const res = await fetch('../api/auth.php?action=check');
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'authenticated') {
        setUser(data.user);
        setView('dashboard');
        loadTurmas();
        loadDashboardData();
        loadListaCompletaAlunos();
      } else {
        setView('login');
      }
    } catch (e) {
      setView('login');
    } finally {
      setLoading(false);
    }
  };

  const loadDashboardData = async () => {
    try {
      const res = await fetch(`../api/formandos.php?busca=${encodeURIComponent(busca)}`);
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'success') {
        let currentCfg = config;
        if (data.config) {
          currentCfg = {
            ...config,
            valor_pessoa_extra: data.config.valor_extra || data.config.valor_base,
            max_parcelas: data.config.max_parcelas,
            chave_pix: data.config.chave_pix || config.chave_pix,
            chaves_pix: data.config.chaves_pix || config.chaves_pix,
            formas_pagamento: data.config.formas_pagamento || config.formas_pagamento
          };
          setConfig(currentCfg);
        }
        recalculateResumo(data.formandos, currentCfg);
      } else {
        recalculateResumo(formandos, config);
      }
    } catch (e) {
      recalculateResumo(formandos, config);
    }
  };

  const loadFormandoDetail = async (id) => {
    try {
      const res = await fetch(`../api/formandos.php?id=${id}`);
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'success') {
        setFormandoAtual(data.formando);
        setFormandoId(id);
        setView('formando_detail');
      } else {
        const fAtualizado = recalculateResumo(formandos, config).find(item => item.id === id);
        setFormandoAtual(fAtualizado);
        setFormandoId(id);
        setView('formando_detail');
      }
    } catch (e) {
      const fAtualizado = recalculateResumo(formandos, config).find(item => item.id === id);
      setFormandoAtual(fAtualizado);
      setFormandoId(id);
      setView('formando_detail');
    }
  };

  const loadUsuarios = async () => {
    try {
      const res = await fetch('../api/auth.php?action=listar_usuarios');
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        setListaUsuarios(data.usuarios);
      }
    } catch (e) {}
  };

  const handleSaveUsuario = async (e) => {
    e.preventDefault();
    const isEdit = !!novoUsuarioData.id;
    const action = isEdit ? 'editar_usuario' : 'cadastrar_usuario';
    try {
      const res = await fetch(`../api/auth.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(novoUsuarioData)
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        alert(isEdit ? 'Usuário atualizado com sucesso!' : 'Usuário cadastrado com sucesso!');
        setNovoUsuarioData({ id: null, nome: '', usuario: '', senha: '', perfil: 'comum' });
        loadUsuarios();
      } else {
        alert(data.message || 'Erro ao salvar usuário.');
      }
    } catch (e) {
      alert('Erro de conexão ao salvar usuário.');
    }
  };

  const handleExcluirUsuario = async (id) => {
    if (!confirm('Deseja realmente excluir este usuário?')) return;
    try {
      const res = await fetch('../api/auth.php?action=excluir_usuario', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        loadUsuarios();
      } else {
        alert(data.message || 'Erro ao excluir usuário.');
      }
    } catch (e) {
      alert('Erro ao excluir usuário.');
    }
  };

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoginError('');
    try {
      const res = await fetch('../api/auth.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(loginCreds)
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'success') {
        setUser(data.user);
        setView('dashboard');
        loadDashboardData();
        return;
      }
    } catch (e) {}

    // Fallback Mock de Login
    if (loginCreds.usuario === 'admin') {
      setUser({ id: 1, nome: 'Administrador', usuario: 'admin', perfil: 'admin' });
      setView('dashboard');
      recalculateResumo(formandos, config);
    } else if (loginCreds.usuario === 'comum' || loginCreds.usuario === 'operador') {
      setUser({ id: 2, nome: 'Usuário Comum', usuario: 'comum', perfil: 'comum' });
      setView('dashboard');
      recalculateResumo(formandos, config);
    } else {
      setLoginError('Usuário ou senha incorretos.');
    }
  };

  const handleLogout = async () => {
    try { await fetch('../api/auth.php?action=logout'); } catch (e) {}
    setUser(null);
    setView('login');
  };

  const handleSaveConfig = async (e) => {
    e.preventDefault();
    if (!isAdmin) {
      alert('Acesso negado. Apenas administradores podem alterar as configurações.');
      return;
    }
    try {
      const res = await fetch('../api/configuracoes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(config)
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}

      if (data.status === 'success') {
        alert('Configurações salvas no servidor!');
      }
    } catch (e) {}

    recalculateResumo(formandos, config);
    alert('Configurações atualizadas com sucesso!');
    setView('dashboard');
  };

  const handleSaveFormando = async (e) => {
    e.preventDefault();
    if (!isAdmin) {
      alert('Acesso negado. Apenas administradores podem cadastrar ou editar alunos.');
      return;
    }
    const isEdit = !!modalFormandoData.id;
    try {
      const res = await fetch('../api/formandos.php', {
        method: isEdit ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(modalFormandoData)
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        setShowModalFormando(false);
        loadListaCompletaAlunos();
        loadTurmas();
        loadDashboardData();
        if (isEdit && formandoAtual && formandoAtual.id === modalFormandoData.id) {
          loadFormandoDetail(modalFormandoData.id);
        }
        return;
      }
    } catch (e) {}

    let novaLista = [...formandos];
    if (isEdit) {
      novaLista = novaLista.map(f => f.id === modalFormandoData.id ? { ...f, ...modalFormandoData } : f);
    } else {
      const newId = Date.now();
      novaLista.push({
        id: newId,
        ...modalFormandoData,
        convidados_extra: parseInt(modalFormandoData.convidados_extra) || 0,
        pagamentos: []
      });
    }

    const processada = recalculateResumo(novaLista, config);
    setShowModalFormando(false);
    if (isEdit && formandoAtual) {
      setFormandoAtual(processada.find(item => item.id === modalFormandoData.id));
    }
  };

  const handleDeleteFormando = async (id) => {
    if (!isAdmin) {
      alert('Acesso negado. Apenas administradores podem excluir alunos.');
      return;
    }
    if (!confirm('Deseja realmente excluir este formando e todo o histórico de pagamentos?')) return;
    try {
      await fetch(`../api/formandos.php?id=${id}`, { method: 'DELETE' });
    } catch (e) {}

    const novaLista = formandos.filter(f => f.id !== id);
    recalculateResumo(novaLista, config);
    setView('dashboard');
  };

  const handleSavePagamento = async (e) => {
    e.preventDefault();
    const valorDigitado = Math.round((parseFloat(modalPagamentoData.valor) || 0) * 100) / 100;
    const saldoDevedor = Math.round((formandoAtual?.saldo_devedor || 0) * 100) / 100;

    if (valorDigitado > saldoDevedor) {
      alert(`⚠️ Operação não permitida!\n\nO valor digitado (R$ ${valorDigitado.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}) é maior do que o saldo devedor restante (R$ ${saldoDevedor.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}).`);
      return;
    }
    if (valorDigitado <= 0) {
      alert('⚠️ O valor do pagamento deve ser maior que zero.');
      return;
    }

    const activePixKey = findPixKeyForForma(modalPagamentoData.forma_pagamento, config);
    // Pré-abre a aba do recibo síncronamente para não ser bloqueada pelo bloqueador de popups do navegador
    const popupWin = window.open('about:blank', '_blank');
    let createdId = null;
    try {
      const payload = {
        ...modalPagamentoData,
        chave_pix: activePixKey,
        formando_id: formandoAtual.id
      };
      const res = await fetch('../api/pagamentos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        createdId = data.id;
        setShowModalPagamento(false);
        loadFormandoDetail(formandoAtual.id);
        loadDashboardData();

        if (createdId && popupWin) {
          popupWin.location.href = `../api/recibo.php?id=${createdId}`;
        }
        return;
      } else if (data.message) {
        if (popupWin) popupWin.close();
        alert('⚠️ ' + data.message);
        return;
      }
    } catch (e) {}

    // Fallback local em modo preview
    const novoPagamento = {
      id: Date.now(),
      formando_id: formandoAtual.id,
      numero_parcela: modalPagamentoData.numero_parcela,
      data_pagamento: modalPagamentoData.data_pagamento,
      valor: parseFloat(modalPagamentoData.valor) || 0,
      forma_pagamento: modalPagamentoData.forma_pagamento,
      chave_pix: activePixKey,
      observacao: modalPagamentoData.observacao
    };

    const novaLista = formandos.map(f => {
      if (f.id === formandoAtual.id) {
        const pagts = [...(f.pagamentos || []), novoPagamento];
        return { ...f, pagamentos: pagts };
      }
      return f;
    });

    const processada = recalculateResumo(novaLista, config);
    setFormandoAtual(processada.find(item => item.id === formandoAtual.id));
    setShowModalPagamento(false);

    if (popupWin) {
      popupWin.location.href = `../api/recibo.php?id=${novoPagamento.id}`;
    }
  };

  const handleDeletePagamento = async (pagamentoId) => {
    if (!isAdmin) {
      alert('Acesso negado. Apenas administradores podem excluir parcelas de pagamento.');
      return;
    }
    if (!confirm('Deseja excluir esta parcela de pagamento?')) return;
    try {
      const res = await fetch(`../api/pagamentos.php?id=${pagamentoId}`, { method: 'DELETE' });
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch (err) {}
      if (data.status === 'success') {
        loadFormandoDetail(formandoAtual.id);
        loadDashboardData();
        return;
      }
    } catch (e) {}

    const novaLista = formandos.map(f => {
      if (f.id === formandoAtual.id) {
        const pagts = (f.pagamentos || []).filter(p => String(p.id) !== String(pagamentoId));
        return { ...f, pagamentos: pagts };
      }
      return f;
    });

    const processada = recalculateResumo(novaLista, config);
    setFormandoAtual(processada.find(item => item.id === formandoAtual.id));
  };

  const handleSendWhatsApp = (pagamento) => {
    if (!formandoAtual) return;
    const foneRaw = (formandoAtual.telefone || '').replace(/\D/g, '');
    if (!foneRaw) {
      alert('Telefone do formando não informado no cadastro.');
      return;
    }

    const foneClean = foneRaw.startsWith('55') ? foneRaw : '55' + foneRaw;
    const nomeAluno = formandoAtual.nome;
    const numParcela = pagamento.numero_parcela;
    const valPago = parseFloat(pagamento.valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
    const dataPag = new Date(pagamento.data_pagamento + 'T00:00:00').toLocaleDateString('pt-BR');
    const formaPag = pagamento.forma_pagamento;
    const saldoRestante = formandoAtual.saldo_devedor.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

    const cgmText = formandoAtual.cgm ? `📋 *CGM:* ${formandoAtual.cgm}\n` : '';

    const mensagem = 
      `🎓 *RECIBO DE PAGAMENTO - FORMATURA 2026*\n\n` +
      `Olá *${nomeAluno}*!\n` +
      cgmText +
      `Confirmamos o recebimento da sua *${numParcela}ª Parcela*.\n\n` +
      `💵 *Valor Pago:* R$ ${valPago}\n` +
      `📅 *Data do Pagamento:* ${dataPag}\n` +
      `💳 *Forma:* ${formaPag}\n` +
      `📊 *Saldo Restante a Pagar:* R$ ${saldoRestante}\n\n` +
      `Obrigado! Comissão Organizadora de Formatura.`;

    const url = `https://api.whatsapp.com/send?phone=${foneClean}&text=${encodeURIComponent(mensagem)}`;
    window.open(url, '_blank');
  };

  if (loading) {
    return (
      <div style={{ display: 'grid', placeItems: 'center', height: '100vh' }}>
        <h2 style={{ color: '#6366f1' }}>Carregando Controle de Formatura...</h2>
      </div>
    );
  }

  // TELA DE LOGIN (Sem a informação de senha padrão)
  if (view === 'login') {
    return (
      <div style={{ display: 'grid', placeItems: 'center', minHeight: '100vh', padding: '1rem' }}>
        <div className="glass-panel" style={{ width: '100%', maxWidth: '420px', padding: '2.5rem' }}>
          <div style={{ textAlign: 'center', marginBottom: '2rem' }}>
            <div className="brand-icon" style={{ margin: '0 auto 1rem auto', width: '50px', height: '50px', fontSize: '1.5rem' }}>🎓</div>
            <h2 style={{ fontSize: '1.5rem', fontWeight: '800' }}>Acesso ao Sistema</h2>
            <p style={{ color: '#94a3b8', fontSize: '0.875rem' }}>Gestão Financeira de Formatura</p>
          </div>

          {loginError && (
            <div style={{ background: 'rgba(239, 68, 68, 0.2)', border: '1px solid rgba(239, 68, 68, 0.4)', padding: '0.75rem', borderRadius: '8px', color: '#fca5a5', marginBottom: '1rem', fontSize: '0.875rem', textAlign: 'center' }}>
              {loginError}
            </div>
          )}

          <form onSubmit={handleLogin}>
            <div className="form-group">
              <label className="form-label">Usuário</label>
              <input
                type="text"
                className="form-control"
                placeholder="Informe seu usuário"
                value={loginCreds.usuario}
                onChange={e => setLoginCreds({ ...loginCreds, usuario: e.target.value })}
                required
              />
            </div>
            <div className="form-group">
              <label className="form-label">Senha</label>
              <input
                type="password"
                className="form-control"
                placeholder="Informe sua senha"
                value={loginCreds.senha}
                onChange={e => setLoginCreds({ ...loginCreds, senha: e.target.value })}
                required
              />
            </div>
            <button type="submit" className="btn btn-primary" style={{ width: '100%', padding: '0.8rem', marginTop: '1rem' }}>
              Entrar no Sistema
            </button>
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              style={{ width: '100%', marginTop: '0.75rem' }}
              onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
            >
              {theme === 'dark' ? '☀️ Alternar para Modo Claro' : '🌙 Alternar para Modo Escuro'}
            </button>
          </form>
        </div>
      </div>
    );
  }

  return (
    <div style={{ paddingBottom: '3rem' }}>
      {/* NAVBAR */}
      <header className="navbar glass-panel">
        <div className="brand" style={{ cursor: 'pointer' }} onClick={() => { setView('dashboard'); loadDashboardData(); }}>
          <div className="brand-icon">🎓</div>
          <div>
            <div>{config.titulo_formatura}</div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontWeight: '400' }}>
              Perfil: <strong style={{ color: isAdmin ? '#a5b4fc' : '#34d399' }}>{isAdmin ? 'Administrador' : 'Usuário Comum (Recebimentos)'}</strong>
            </div>
          </div>
        </div>
        <div className="nav-links">
          <button className={`btn ${view === 'dashboard' ? 'btn-primary' : 'btn-secondary'}`} onClick={() => { setView('dashboard'); loadDashboardData(); }}>
            💳 Gerenciamento de Pagamentos
          </button>
          
          <button className={`btn ${view === 'importar_alunos' ? 'btn-primary' : 'btn-secondary'}`} onClick={() => { setView('importar_alunos'); loadListaCompletaAlunos(); loadTurmas(); }}>
            👨‍🎓 Gerenciar Alunos
          </button>

          {/* Opções Restritas ao Administrador */}
          {isAdmin && (
            <>
              <button className={`btn ${view === 'turmas' ? 'btn-primary' : 'btn-secondary'}`} onClick={() => { setView('turmas'); loadTurmas(); }}>
                🏫 Turmas
              </button>
              <button className={`btn ${view === 'configuracoes' ? 'btn-primary' : 'btn-secondary'}`} onClick={() => setView('configuracoes')}>
                ⚙️ Configurações de Preço
              </button>
              <button className={`btn ${view === 'usuarios' ? 'btn-primary' : 'btn-secondary'}`} onClick={() => { setView('usuarios'); loadUsuarios(); }}>
                👥 Usuários
              </button>
            </>
          )}

          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
            title={theme === 'dark' ? 'Alternar para Tema Claro ☀️' : 'Alternar para Tema Escuro 🌙'}
          >
            {theme === 'dark' ? '☀️ Modo Claro' : '🌙 Modo Escuro'}
          </button>

          <button className="btn btn-danger btn-sm" onClick={handleLogout}>
            Sair ({user ? user.nome : ''})
          </button>
        </div>
      </header>

      <main style={{ maxWidth: '1200px', margin: '0 auto', padding: '0 1rem' }}>

        {/* TELA DE GESTÃO DE TURMAS (SOMENTE ADMIN) */}
        {view === 'turmas' && isAdmin && (
          <div className="glass-panel" style={{ padding: '2rem' }}>
            <h2 style={{ fontSize: '1.5rem', fontWeight: '800', marginBottom: '1.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              🏫 Cadastro e Gestão de Turmas
            </h2>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: '2rem' }}>
              {/* FORMULÁRIO DE NOVA / EDITAR TURMA */}
              <div style={{ background: 'rgba(15, 23, 42, 0.5)', padding: '1.5rem', borderRadius: '12px', border: '1px solid var(--bg-card-border)' }}>
                <h3 style={{ fontSize: '1.1rem', fontWeight: '700', marginBottom: '1rem', color: '#a5b4fc' }}>
                  {novaTurmaData.id ? `✏️ Editar Turma (${novaTurmaData.nome})` : '➕ Cadastrar Nova Turma'}
                </h3>
                <form onSubmit={handleSaveTurma}>
                  <div className="form-group">
                    <label className="form-label">Nome da Turma*</label>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="ex: 3º A, Terceirão B, etc."
                      value={novaTurmaData.nome}
                      onChange={e => setNovaTurmaData({ ...novaTurmaData, nome: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Observação (Opcional)</label>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="ex: Turno Matutino / Sala 12"
                      value={novaTurmaData.observacao}
                      onChange={e => setNovaTurmaData({ ...novaTurmaData, observacao: e.target.value })}
                    />
                  </div>

                  <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem' }}>
                    <button type="submit" className="btn btn-success" style={{ flex: 1, padding: '0.8rem' }}>
                      {novaTurmaData.id ? '💾 Salvar Alterações' : '➕ Cadastrar Turma'}
                    </button>
                    {novaTurmaData.id && (
                      <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => setNovaTurmaData({ id: null, nome: '', observacao: '' })}
                      >
                        Cancelar
                      </button>
                    )}
                  </div>
                </form>
              </div>

              {/* LISTA DE TURMAS CADASTRADAS */}
              <div style={{ background: 'rgba(15, 23, 42, 0.5)', padding: '1.5rem', borderRadius: '12px', border: '1px solid var(--bg-card-border)' }}>
                <h3 style={{ fontSize: '1.1rem', fontWeight: '700', marginBottom: '1rem', color: '#a5b4fc' }}>Turmas Cadastradas</h3>
                {(() => {
                  const colsTurmas = [
                    {
                      header: 'Turma',
                      accessor: 'nome',
                      render: t => <span className="badge badge-success" style={{ fontSize: '0.9rem' }}>Turma {t.nome}</span>
                    },
                    {
                      header: 'Alunos Cadastrados',
                      accessor: t => formandos.filter(f => (f.turma || '').trim() === t.nome.trim()).length,
                      render: t => {
                        const count = formandos.filter(f => (f.turma || '').trim() === t.nome.trim()).length;
                        return <strong>{count} Alunos</strong>;
                      }
                    },
                    {
                      header: 'Observação',
                      accessor: 'observacao',
                      render: t => <span style={{ color: '#94a3b8', fontSize: '0.85rem' }}>{t.observacao || '-'}</span>
                    },
                    {
                      header: 'Ações',
                      sortable: false,
                      render: t => (
                        <div style={{ display: 'flex', gap: '0.4rem' }}>
                          <button
                            className="btn btn-secondary btn-sm"
                            onClick={() => setNovaTurmaData({ id: t.id, nome: t.nome, observacao: t.observacao || '' })}
                          >
                            ✏️ Editar
                          </button>
                          <button className="btn btn-danger btn-sm" onClick={() => handleExcluirTurma(t.id)} title="Excluir Turma">
                            🗑️ Excluir
                          </button>
                        </div>
                      )
                    }
                  ];
                  return (
                    <DataTable
                      columns={colsTurmas}
                      data={listaTurmas}
                      keyField="id"
                      defaultPageSize={10}
                      emptyMessage="Nenhuma turma cadastrada."
                    />
                  );
                })()}
              </div>
            </div>
          </div>
        )}

        {/* TELA DE IMPORTAÇÃO INTELIGENTE DE ALUNOS & RELAÇÃO GERAL POR TURMA */}
        {view === 'importar_alunos' && (
          <div>
            <div className="glass-panel" style={{ padding: '2rem', marginBottom: '1.5rem' }}>
              <h2 style={{ fontSize: '1.5rem', fontWeight: '800', marginBottom: '0.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                👨‍🎓 Gerenciamento e Importação de Alunos por Turma
              </h2>
              <p style={{ color: '#94a3b8', fontSize: '0.9rem', marginBottom: '1.5rem' }}>
                Cole a lista de alunos da escola ou turma. O sistema identifica automaticamente o nome do aluno e sua turma (ex: <code>João da Silva - 3º A</code> ou <code>Maria Souza, 3º B</code>). Se a turma ainda não existir, ela será criada automaticamente!
              </p>

              <form onSubmit={handleImportarInteligente}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '1rem', marginBottom: '1rem' }}>
                  <div className="form-group" style={{ marginBottom: 0 }}>
                    <label className="form-label">Turma Padrão (se não informada)</label>
                    <select
                      className="form-control"
                      value={turmaImportacaoPadrao}
                      onChange={e => setTurmaImportacaoPadrao(e.target.value)}
                    >
                      {listaTurmas.map(t => (
                        <option key={t.id || t.nome} value={t.nome}>Turma {t.nome}</option>
                      ))}
                      <option value="3º A">Turma 3º A</option>
                      <option value="3º B">Turma 3º B</option>
                      <option value="3º C">Turma 3º C</option>
                    </select>
                  </div>
                  <div className="form-group" style={{ marginBottom: 0 }}>
                    <label className="form-label">📁 Selecionar Arquivo CSV / TXT</label>
                    <input
                      type="file"
                      accept=".csv,.txt"
                      className="form-control"
                      style={{ padding: '0.45rem' }}
                      onChange={handleFileUploadCSV}
                    />
                  </div>
                </div>

                <div style={{ marginBottom: '1rem' }}>
                  <span style={{ fontSize: '0.8rem', color: '#a5b4fc', background: 'rgba(99, 102, 241, 0.1)', padding: '0.6rem 0.85rem', borderRadius: '8px', border: '1px solid rgba(99, 102, 241, 0.3)', display: 'block' }}>
                    💡 <strong>Formatos CSV aceitos:</strong> Arquivo CSV com cabeçalho (ex: <code>Nº; Nome; CGM; Turma</code> ou <code>Número, Nome, CGM, Turma</code>) ou texto delimitado por vírgula, ponto e vírgula, traço ou tabulação.
                  </span>
                </div>

                <div className="form-group">
                  <label className="form-label">Conteúdo do CSV ou Cole a Lista de Alunos*</label>
                  <textarea
                    className="form-control"
                    rows="6"
                    placeholder={`Exemplos:\n1; Kauan da Silva; 2026001; 3º B\n2; Matheus Henrique; 2026002; 3º B\nNº, Nome, CGM, Turma\n3, Ana Paula Souza, 2026003, 3º A`}
                    value={textoImportacao}
                    onChange={e => setTextoImportacao(e.target.value)}
                    required
                  ></textarea>
                </div>

                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '1rem', flexWrap: 'wrap' }}>
                  {isAdmin && (
                    <button
                      type="button"
                      className="btn btn-danger"
                      onClick={handleLimparTodosAlunos}
                      style={{ padding: '0.75rem 1.25rem', fontWeight: 'bold' }}
                      title="Excluir todos os alunos cadastrados e recomeçar do zero"
                    >
                      🗑️ Limpar Todos os Alunos Cadastrados
                    </button>
                  )}
                  <button type="submit" className="btn btn-success" style={{ padding: '0.75rem 1.5rem', fontWeight: 'bold', marginLeft: 'auto' }}>
                    ⚡ Processar & Importar Arquivo CSV / Alunos
                  </button>
                </div>
              </form>
            </div>

            {/* RELAÇÃO GERAL DE ALUNOS E SELEÇÃO PARA GERENCIAMENTO DE PAGAMENTO */}
            <div className="glass-panel" style={{ padding: '1.5rem' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem', flexWrap: 'wrap', gap: '1rem' }}>
                <div>
                  <h3 style={{ fontSize: '1.25rem', fontWeight: '700', margin: 0 }}>
                    📋 Relação Geral de Alunos por Turma
                  </h3>
                  <span style={{ fontSize: '0.825rem', color: '#94a3b8' }}>
                    Selecione os alunos que vão participar da formatura para enviá-los à tela de Gerenciamento de Pagamentos.
                  </span>
                </div>

                <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
                  <input
                    type="text"
                    className="form-control"
                    placeholder="🔍 Buscar aluno ou turma..."
                    style={{ width: '220px' }}
                    value={busca}
                    onChange={e => setBusca(e.target.value)}
                  />
                  <select
                    className="form-control"
                    style={{ width: '160px' }}
                    value={turmaFiltro}
                    onChange={e => setTurmaFiltro(e.target.value)}
                  >
                    <option value="">Todas as Turmas</option>
                    {listaTurmas.map(t => (
                      <option key={t.id || t.nome} value={t.nome}>Turma {t.nome}</option>
                    ))}
                  </select>
                </div>
              </div>

              {(() => {
                const alunosFiltrados = todosAlunos.filter(a => {
                  const matchTurma = !turmaFiltro || (a.turma || '').trim() === turmaFiltro.trim();
                  const q = busca.toLowerCase().trim();
                  const matchBusca = !q || (
                    (a.nome || '').toLowerCase().includes(q) ||
                    (a.cgm || '').toLowerCase().includes(q) ||
                    (a.telefone || '').includes(q) ||
                    (a.turma || '').toLowerCase().includes(q)
                  );
                  return matchTurma && matchBusca;
                });

                const colsImportacao = [
                  {
                    header: 'Nº',
                    accessor: 'numero_aluno',
                    width: '60px',
                    render: a => <span style={{ fontWeight: 'bold', color: '#818cf8' }}>{a.numero_aluno ? `#${a.numero_aluno}` : '-'}</span>
                  },
                  {
                    header: 'Nome do Aluno(a)',
                    accessor: 'nome',
                    render: a => <strong style={{ color: 'var(--text-main)' }}>{a.nome}</strong>
                  },
                  {
                    header: 'CGM',
                    accessor: 'cgm',
                    render: a => a.cgm ? <span className="badge badge-warning" style={{ background: 'rgba(245, 158, 11, 0.15)', color: '#fbbf24', border: '1px solid rgba(245, 158, 11, 0.3)' }}>{a.cgm}</span> : <span style={{ color: '#64748b' }}>-</span>
                  },
                  {
                    header: 'Turma',
                    accessor: 'turma',
                    render: a => <span className="badge badge-success">{a.turma}</span>
                  },
                  {
                    header: 'Status',
                    accessor: 'participa_formatura',
                    render: a => (
                      <span className={`badge ${a.participa_formatura === 1 ? 'badge-success' : 'badge-warning'}`} style={a.participa_formatura === 1 ? {} : { background: 'rgba(148, 163, 184, 0.2)', color: '#94a3b8', border: '1px solid rgba(148, 163, 184, 0.4)' }}>
                        {a.participa_formatura === 1 ? '🎓 Formando' : '⚪ Não Formando'}
                      </span>
                    )
                  },
                  {
                    header: 'Alterar Status',
                    accessor: 'participa_formatura',
                    sortable: false,
                    render: a => (
                      <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', margin: 0, fontWeight: 'bold' }}>
                        <input
                          type="checkbox"
                          style={{ width: '18px', height: '18px', cursor: 'pointer', accentColor: '#34d399' }}
                          checked={a.participa_formatura === 1}
                          onChange={() => handleToggleParticipacao(a)}
                        />
                        <span style={{ color: a.participa_formatura === 1 ? '#34d399' : '#94a3b8', fontSize: '0.85rem' }}>
                          {a.participa_formatura === 1 ? 'Formando' : 'Não Formando'}
                        </span>
                      </label>
                    )
                  },
                  {
                    header: 'Ações',
                    sortable: false,
                    render: a => (
                      <div style={{ display: 'flex', gap: '0.4rem' }}>
                        <button
                          className="btn btn-secondary btn-sm"
                          onClick={() => {
                            setModalFormandoData({
                              id: a.id,
                              numero_aluno: a.numero_aluno || '',
                              nome: a.nome || '',
                              cgm: a.cgm || '',
                              turma: a.turma || '',
                              telefone: a.telefone || '',
                              convidados_extra: a.convidados_extra || 0,
                              participa_formatura: a.participa_formatura !== undefined ? a.participa_formatura : 1,
                              observacoes: a.observacoes || ''
                            });
                            setShowModalFormando(true);
                          }}
                          title="Editar dados completos do aluno"
                        >
                          ✏️ Editar
                        </button>
                        {isAdmin && (
                          <button
                            className="btn btn-danger btn-sm"
                            onClick={() => handleDeleteFormando(a.id)}
                            title="Excluir Aluno"
                          >
                            🗑️ Excluir
                          </button>
                        )}
                      </div>
                    )
                  }
                ];

                return (
                  <DataTable
                    columns={colsImportacao}
                    data={alunosFiltrados}
                    keyField="id"
                    defaultPageSize={10}
                    emptyMessage="Nenhum aluno encontrado na lista geral."
                  />
                );
              })()}
            </div>
          </div>
        )}

        {/* TELA DE GESTÃO DE USUÁRIOS (SOMENTE ADMIN) */}
        {view === 'usuarios' && isAdmin && (
          <div className="glass-panel" style={{ padding: '2rem' }}>
            <h2 style={{ fontSize: '1.5rem', fontWeight: '800', marginBottom: '1.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              👥 Cadastro e Gestão de Usuários
            </h2>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: '2rem' }}>
              {/* FORMULÁRIO DE NOVO / EDITAR USUÁRIO */}
              <div style={{ background: 'rgba(15, 23, 42, 0.5)', padding: '1.5rem', borderRadius: '12px', border: '1px solid var(--bg-card-border)' }}>
                <h3 style={{ fontSize: '1.1rem', fontWeight: '700', marginBottom: '1rem', color: '#a5b4fc' }}>
                  {novoUsuarioData.id ? `✏️ Editar Usuário (@${novoUsuarioData.usuario})` : 'Cadastrar Novo Usuário'}
                </h3>
                <form onSubmit={handleSaveUsuario}>
                  <div className="form-group">
                    <label className="form-label">Nome Completo*</label>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="ex: Maria Oliveira"
                      value={novoUsuarioData.nome}
                      onChange={e => setNovoUsuarioData({ ...novoUsuarioData, nome: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Nome de Usuário (Login)*</label>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="ex: maria"
                      value={novoUsuarioData.usuario}
                      onChange={e => setNovoUsuarioData({ ...novoUsuarioData, usuario: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Tipo / Perfil do Usuário*</label>
                    <select
                      className="form-control"
                      value={novoUsuarioData.perfil}
                      onChange={e => setNovoUsuarioData({ ...novoUsuarioData, perfil: e.target.value })}
                    >
                      <option value="comum">Usuário Comum (Somente Recebimentos)</option>
                      <option value="admin">Administrador (Acesso Total)</option>
                    </select>
                  </div>

                  <div className="form-group">
                    <label className="form-label">
                      {novoUsuarioData.id ? 'Nova Senha (deixe em branco para não alterar)' : 'Senha de Acesso*'}
                    </label>
                    <input
                      type="password"
                      className="form-control"
                      placeholder={novoUsuarioData.id ? 'Manter senha atual' : 'Senha do novo usuário'}
                      value={novoUsuarioData.senha}
                      onChange={e => setNovoUsuarioData({ ...novoUsuarioData, senha: e.target.value })}
                      required={!novoUsuarioData.id}
                    />
                  </div>

                  <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem' }}>
                    <button type="submit" className="btn btn-success" style={{ flex: 1, padding: '0.8rem' }}>
                      {novoUsuarioData.id ? '💾 Salvar Alterações' : '➕ Cadastrar Usuário'}
                    </button>
                    {novoUsuarioData.id && (
                      <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => setNovoUsuarioData({ id: null, nome: '', usuario: '', senha: '', perfil: 'comum' })}
                      >
                        Cancelar
                      </button>
                    )}
                  </div>
                </form>
              </div>

              {/* LISTA DE USUÁRIOS CADASTRADOS */}
              <div style={{ background: 'rgba(15, 23, 42, 0.5)', padding: '1.5rem', borderRadius: '12px', border: '1px solid var(--bg-card-border)' }}>
                <h3 style={{ fontSize: '1.1rem', fontWeight: '700', marginBottom: '1rem', color: '#a5b4fc' }}>Usuários Cadastrados</h3>
                {(() => {
                  const colsUsuarios = [
                    {
                      header: 'Nome Completo',
                      accessor: 'nome',
                      render: u => <strong>{u.nome}</strong>
                    },
                    {
                      header: 'Usuário (Login)',
                      accessor: 'usuario',
                      render: u => <span className="badge badge-warning">@{u.usuario}</span>
                    },
                    {
                      header: 'Perfil',
                      accessor: 'perfil',
                      render: u => (
                        <span className={`badge ${u.perfil === 'admin' ? 'badge-danger' : 'badge-success'}`}>
                          {u.perfil === 'admin' ? 'Admin' : 'Comum'}
                        </span>
                      )
                    },
                    {
                      header: 'Ações',
                      sortable: false,
                      render: u => (
                        <div style={{ display: 'flex', gap: '0.4rem' }}>
                          <button
                            className="btn btn-secondary btn-sm"
                            onClick={() => setNovoUsuarioData({ id: u.id, nome: u.nome, usuario: u.usuario, perfil: u.perfil || 'comum', senha: '' })}
                          >
                            ✏️ Editar
                          </button>
                          {user && user.id !== u.id && (
                            <button className="btn btn-danger btn-sm" onClick={() => handleExcluirUsuario(u.id)} title="Excluir Usuário">
                              🗑️ Excluir
                            </button>
                          )}
                        </div>
                      )
                    }
                  ];
                  return (
                    <DataTable
                      columns={colsUsuarios}
                      data={listaUsuarios}
                      keyField="id"
                      defaultPageSize={10}
                      emptyMessage="Carregando ou nenhum usuário cadastrado."
                    />
                  );
                })()}
              </div>
            </div>
          </div>
        )}

        {/* TELA DE CONFIGURAÇÕES (SOMENTE ADMIN) */}
        {view === 'configuracoes' && isAdmin && (
          <div className="glass-panel" style={{ padding: '2rem' }}>
            <h2 style={{ fontSize: '1.5rem', fontWeight: '800', marginBottom: '1.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              ⚙️ Configurações do Valor por Pessoa
            </h2>
            <form onSubmit={handleSaveConfig} style={{ maxWidth: '600px' }}>
              <div className="form-group">
                <label className="form-label">Título da Formatura</label>
                <input
                  type="text"
                  className="form-control"
                  value={config.titulo_formatura}
                  onChange={e => setConfig({ ...config, titulo_formatura: e.target.value })}
                  required
                />
              </div>

              <div className="form-group">
                <label className="form-label">Valor por Pessoa / Convite (R$)*</label>
                <input
                  type="number"
                  step="0.01"
                  min="1"
                  className="form-control"
                  value={config.valor_pessoa_extra}
                  onChange={e => setConfig({ ...config, valor_pessoa_extra: parseFloat(e.target.value) || 0 })}
                  required
                />
                <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}>
                  O valor total de cada aluno é calculado multiplicando este valor pela quantidade de pessoas indicadas.
                </span>
              </div>

              <div className="form-group">
                <label className="form-label">Número Máximo de Parcelas Permitidas</label>
                <input
                  type="number"
                  min="1"
                  max="48"
                  className="form-control"
                  value={config.max_parcelas}
                  onChange={e => setConfig({ ...config, max_parcelas: parseInt(e.target.value) || 1 })}
                  required
                />
              </div>

              <div className="form-group">
                <label className="form-label">Formas de Pagamento Aceitas (separadas por vírgula)*</label>
                <input
                  type="text"
                  className="form-control"
                  value={config.formas_pagamento || 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'}
                  onChange={e => setConfig({ ...config, formas_pagamento: e.target.value })}
                  placeholder="ex: Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto"
                  required
                />
                <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}>
                  Digite as opções de pagamento separadas por vírgula. Elas serão exibidas na tela de recebimento de parcelas.
                </span>
              </div>

              <div className="form-group" style={{ background: 'rgba(15, 23, 42, 0.4)', padding: '1.25rem', borderRadius: '12px', border: '1px solid rgba(99, 102, 241, 0.2)' }}>
                <label className="form-label" style={{ fontSize: '1rem', fontWeight: 'bold', color: '#818cf8' }}>
                  🔑 Chaves Pix Vinculadas por Opção (ex: Pix APMF ➔ apmf@gmail.com)
                </label>
                <p style={{ fontSize: '0.8rem', color: '#94a3b8', marginBottom: '1rem' }}>
                  Cadastre as opções de Pix com suas respectivas chaves. Ao escolher essa opção no recebimento, o QR Code e o recibo usarão automaticamente a chave configurada!
                </p>

                {(() => {
                  const list = parseChavesPix(config.chaves_pix);
                  
                  const handlePixItemChange = (idx, field, val) => {
                    const newList = [...list];
                    if (!newList[idx]) newList[idx] = { nome: '', chave: '' };
                    newList[idx][field] = val;
                    const jsonStr = JSON.stringify(newList);
                    
                    const names = newList.map(i => i.nome.trim()).filter(Boolean);
                    let currentFormas = (config.formas_pagamento || '').split(',').map(s => s.trim()).filter(Boolean);
                    names.forEach(n => {
                      if (!currentFormas.includes(n)) currentFormas.push(n);
                    });
                    
                    setConfig({
                      ...config,
                      chaves_pix: jsonStr,
                      formas_pagamento: currentFormas.join(', ')
                    });
                  };

                  const handleAddPixKey = () => {
                    const newList = [...list, { nome: `Pix ${list.length + 1}`, chave: '' }];
                    setConfig({ ...config, chaves_pix: JSON.stringify(newList) });
                  };

                  const handleRemovePixKey = (idx) => {
                    const newList = list.filter((_, i) => i !== idx);
                    setConfig({ ...config, chaves_pix: JSON.stringify(newList) });
                  };

                  return (
                    <div>
                      {list.map((item, idx) => (
                        <div key={idx} style={{ display: 'flex', gap: '0.5rem', marginBottom: '0.5rem', alignItems: 'center' }}>
                          <input
                            type="text"
                            className="form-control"
                            placeholder="Nome (ex: Pix APMF)"
                            style={{ flex: 1 }}
                            value={item.nome || ''}
                            onChange={e => handlePixItemChange(idx, 'nome', e.target.value)}
                          />
                          <input
                            type="text"
                            className="form-control"
                            placeholder="Chave Pix (ex: apmf@gmail.com)"
                            style={{ flex: 1.5 }}
                            value={item.chave || ''}
                            onChange={e => handlePixItemChange(idx, 'chave', e.target.value)}
                          />
                          <button
                            type="button"
                            className="btn btn-danger btn-sm"
                            onClick={() => handleRemovePixKey(idx)}
                            title="Remover Chave"
                          >
                            🗑️
                          </button>
                        </div>
                      ))}

                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        style={{ marginTop: '0.5rem' }}
                        onClick={handleAddPixKey}
                      >
                        ➕ Adicionar Opção / Chave Pix
                      </button>
                    </div>
                  );
                })()}
              </div>

              <div className="form-group">
                <label className="form-label">Chave Pix Geral / Padrão (Fallback)</label>
                <input
                  type="text"
                  className="form-control"
                  value={config.chave_pix}
                  onChange={e => setConfig({ ...config, chave_pix: e.target.value })}
                />
              </div>

              <div style={{ display: 'flex', gap: '1rem', marginTop: '2rem' }}>
                <button type="submit" className="btn btn-success">
                  💾 Salvar Configurações
                </button>
                <button type="button" className="btn btn-secondary" onClick={() => setView('dashboard')}>
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        )}

        {/* DASHBOARD PRINCIPAL - LISTA DE FORMANDOS */}
        {view === 'dashboard' && (
          <div>
            {/* CARDS DE MÉTRICAS GLOBAIS */}
            <div className="metrics-grid">
              <div className="glass-panel metric-card primary">
                <div className="metric-title">Total de Formandos & Convidados</div>
                <div className="metric-value">{resumo.total_pessoas_evento} Pessoas</div>
                <div style={{ fontSize: '1.05rem', fontWeight: '800', color: '#a5b4fc', marginTop: '0.25rem' }}>
                  🎓 {resumo.total_formandos} Alunos Formandos
                </div>
                <div className="metric-sub" style={{ marginTop: '0.25rem', fontSize: '0.75rem' }}>
                  Soma total de pessoas indicadas
                </div>
              </div>

              <div className="glass-panel metric-card warning">
                <div className="metric-title">Total Previsto</div>
                <div className="metric-value">R$ {resumo.total_previsto.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</div>
                <div className="metric-sub">Pessoas × R$ {config.valor_pessoa_extra}/pessoa</div>
              </div>

              <div className="glass-panel metric-card success">
                <div className="metric-title">Total Arrecadado</div>
                <div className="metric-value" style={{ color: '#34d399' }}>R$ {resumo.total_arrecadado.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</div>
                <div className="metric-sub">Parcelas pagas</div>
              </div>

              <div className="glass-panel metric-card danger">
                <div className="metric-title">Saldo Pendente</div>
                <div className="metric-value" style={{ color: '#fca5a5' }}>R$ {resumo.total_pendente.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</div>
                <div className="metric-sub">Falta arrecadar</div>
              </div>
            </div>

            {/* CARDS DE RESUMO E TOTALIZADORES POR TURMA */}
            {(() => {
              const mapTurmas = {};
              listaTurmas.forEach(t => {
                const tName = (t.nome || '').trim();
                if (tName) {
                  mapTurmas[tName] = {
                    nome: tName,
                    totalAlunos: 0,
                    totalExtras: 0,
                    totalPrevisto: 0,
                    totalArrecadado: 0,
                    saldoDevedor: 0
                  };
                }
              });

              formandos.forEach(f => {
                const tName = (f.turma || 'Sem Turma').trim();
                if (!mapTurmas[tName]) {
                  mapTurmas[tName] = {
                    nome: tName,
                    totalAlunos: 0,
                    totalExtras: 0,
                    totalPrevisto: 0,
                    totalArrecadado: 0,
                    saldoDevedor: 0
                  };
                }
                mapTurmas[tName].totalAlunos += 1;
                mapTurmas[tName].totalExtras += (parseInt(f.convidados_extra) || 0);
                mapTurmas[tName].totalPrevisto += (f.valor_total_a_pagar || 0);
                mapTurmas[tName].totalArrecadado += (f.total_pago || 0);
                mapTurmas[tName].saldoDevedor += (f.saldo_devedor || 0);
              });

              const turmasList = Object.values(mapTurmas);

              if (turmasList.length === 0) return null;

              return (
                <div style={{ marginBottom: '1.5rem' }}>
                  <div style={{ fontSize: '0.85rem', fontWeight: '700', color: '#a5b4fc', textTransform: 'uppercase', marginBottom: '0.75rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    🏫 Total de Alunos, Pessoas Cadastradas e Arrecadação por Turma
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '1rem' }}>
                    {turmasList.map(t => {
                      const totalPessoasTurma = t.totalExtras;
                      return (
                        <div
                          key={t.nome}
                          className="glass-panel"
                          style={{
                            padding: '1.25rem',
                            borderRadius: '12px',
                            borderLeft: turmaFiltro === t.nome ? '4px solid #34d399' : '4px solid #6366f1',
                            background: turmaFiltro === t.nome ? 'rgba(99, 102, 241, 0.2)' : 'rgba(30, 41, 59, 0.7)',
                            cursor: 'pointer',
                            transition: 'all 0.2s ease'
                          }}
                          onClick={() => setTurmaFiltro(turmaFiltro === t.nome ? '' : t.nome)}
                          title="Clique para filtrar apenas os alunos desta turma"
                        >
                          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
                            <span className="badge badge-success" style={{ fontSize: '0.85rem' }}>Turma {t.nome}</span>
                            {turmaFiltro === t.nome && (
                              <span style={{ fontSize: '0.75rem', color: '#34d399', fontWeight: 'bold' }}>Filtro Ativo ✓</span>
                            )}
                          </div>
                          <div style={{ fontSize: '1.5rem', fontWeight: '800', color: 'var(--text-main)' }}>
                            {t.totalAlunos} <span style={{ fontSize: '0.85rem', fontWeight: '500', color: '#94a3b8' }}>{t.totalAlunos === 1 ? 'Aluno' : 'Alunos'}</span>
                          </div>
                          <div style={{ fontSize: '0.95rem', fontWeight: '800', color: '#a5b4fc', margin: '0.2rem 0' }}>
                            👥 {totalPessoasTurma} Pessoas Cadastradas
                          </div>
                          <div style={{ fontSize: '0.75rem', color: '#94a3b8', marginBottom: '0.3rem' }}>
                            Cálculo: {totalPessoasTurma} pessoas × R$ {config.valor_pessoa_extra}
                          </div>
                          <div style={{ fontSize: '0.8rem', color: '#94a3b8', marginTop: '0.5rem', paddingTop: '0.5rem', borderTop: '1px solid rgba(255,255,255,0.08)', display: 'flex', justifyContent: 'space-between' }}>
                            <span>Previsto: <strong>R$ {t.totalPrevisto.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</strong></span>
                            <span style={{ color: '#34d399' }}>Pago: <strong>R$ {t.totalArrecadado.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</strong></span>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              );
            })()}

            {/* BARRA DE BUSCA E AÇÕES */}
            <div className="glass-panel" style={{ padding: '1.25rem', marginBottom: '1.5rem', display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ display: 'flex', gap: '0.75rem', flex: 1, minWidth: '280px', alignItems: 'center', flexWrap: 'wrap' }}>
                <input
                  type="text"
                  className="form-control"
                  placeholder="🔍 Buscar por nome, CGM, turma ou telefone..."
                  style={{ flex: 1, minWidth: '200px' }}
                  value={busca}
                  onChange={e => setBusca(e.target.value)}
                  onKeyUp={e => e.key === 'Enter' && loadDashboardData()}
                />
                <select
                  className="form-control"
                  style={{ width: '160px' }}
                  value={turmaFiltro}
                  onChange={e => setTurmaFiltro(e.target.value)}
                >
                  <option value="">Todas as Turmas</option>
                  {listaTurmas.map(t => (
                    <option key={t.id || t.nome} value={t.nome}>Turma {t.nome}</option>
                  ))}
                </select>
                <button className="btn btn-secondary" onClick={loadDashboardData}>Filtrar</button>
              </div>

              {/* Botão Novo Formando Restrito ao Admin */}
              {isAdmin && (
                <button className="btn btn-success" onClick={() => {
                  setModalFormandoData({ id: null, numero_aluno: '', nome: '', cgm: '', turma: '3º B', telefone: '', convidados_extra: 0, observacoes: '' });
                  setShowModalFormando(true);
                }}>
                  ➕ Cadastrar Novo Formando
                </button>
              )}
            </div>

            {/* TABELA DE FORMANDOS */}
            <div className="glass-panel" style={{ padding: '1.5rem' }}>
              {(() => {
                const formandosFiltrados = formandos.filter(f => {
                  const matchTurma = !turmaFiltro || (f.turma || '').trim() === turmaFiltro.trim();
                  const q = busca.toLowerCase().trim();
                  const matchBusca = !q || (
                    (f.nome || '').toLowerCase().includes(q) ||
                    (f.cgm || '').toLowerCase().includes(q) ||
                    (f.numero_aluno || '').toString().includes(q) ||
                    (f.telefone || '').includes(q) ||
                    (f.turma || '').toLowerCase().includes(q)
                  );
                  return matchTurma && matchBusca;
                });

                const colsFormandos = [
                  {
                    header: 'Nº',
                    accessor: 'numero_aluno',
                    width: '60px',
                    render: f => <span style={{ fontWeight: 'bold', color: '#818cf8' }}>{f.numero_aluno ? `#${f.numero_aluno}` : '-'}</span>
                  },
                  {
                    header: 'Formando(a)',
                    accessor: 'nome',
                    render: f => <span style={{ fontWeight: '700', color: 'var(--text-main)' }}>🎓 {f.nome}</span>
                  },
                  {
                    header: 'CGM',
                    accessor: 'cgm',
                    render: f => f.cgm ? <span className="badge badge-warning" style={{ background: 'rgba(245, 158, 11, 0.15)', color: '#fbbf24', border: '1px solid rgba(245, 158, 11, 0.3)' }}>{f.cgm}</span> : '-'
                  },
                  {
                    header: 'Turma',
                    accessor: 'turma',
                    render: f => <span className="badge badge-success">{f.turma}</span>
                  },
                  {
                    header: 'Telefone',
                    accessor: 'telefone',
                    render: f => f.telefone || '-'
                  },
                  {
                    header: 'Qtd. Pessoas',
                    accessor: 'convidados_extra',
                    render: f => <span><strong>{f.convidados_extra}</strong> p.</span>
                  },
                  {
                    header: 'Valor Total',
                    accessor: 'valor_total_a_pagar',
                    render: f => `R$ ${f.valor_total_a_pagar.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`
                  },
                  {
                    header: 'Pago',
                    accessor: 'total_pago',
                    render: f => <span style={{ color: '#34d399', fontWeight: '700' }}>R$ {f.total_pago.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
                  },
                  {
                    header: 'Saldo Devedor',
                    accessor: 'saldo_devedor',
                    render: f => <span style={{ color: f.saldo_devedor > 0 ? '#fca5a5' : '#34d399', fontWeight: '700' }}>R$ {f.saldo_devedor.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
                  },
                  {
                    header: 'Ações',
                    sortable: false,
                    render: f => (
                      <div style={{ display: 'flex', gap: '0.4rem' }} onClick={e => e.stopPropagation()}>
                        <button className="btn btn-primary btn-sm" onClick={() => loadFormandoDetail(f.id)}>
                          📄 Abrir Ficha
                        </button>
                        {isAdmin && (
                          <button className="btn btn-secondary btn-sm" onClick={() => {
                            setModalFormandoData({
                              id: f.id,
                              numero_aluno: f.numero_aluno || '',
                              nome: f.nome,
                              cgm: f.cgm || '',
                              turma: f.turma,
                              telefone: f.telefone || '',
                              convidados_extra: f.convidados_extra,
                              observacoes: f.observacoes || ''
                            });
                            setShowModalFormando(true);
                          }}>
                            ✏️ Editar
                          </button>
                        )}
                      </div>
                    )
                  }
                ];

                return (
                  <>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem', flexWrap: 'wrap', gap: '0.5rem' }}>
                      <h3 style={{ fontSize: '1.2rem', fontWeight: '700', margin: 0 }}>
                        {turmaFiltro ? `🎓 Alunos da Turma: ${turmaFiltro} (${formandosFiltrados.length})` : `Formandos Cadastrados (${formandosFiltrados.length})`}
                      </h3>
                      {turmaFiltro && (
                        <button
                          className="btn btn-secondary btn-sm"
                          style={{ background: 'rgba(239, 68, 68, 0.2)', color: '#fca5a5', border: '1px solid rgba(239, 68, 68, 0.4)', fontWeight: 'bold' }}
                          onClick={() => setTurmaFiltro('')}
                        >
                          ✖ Mostrar Todas as Turmas
                        </button>
                      )}
                    </div>

                    <DataTable
                      columns={colsFormandos}
                      data={formandosFiltrados.map(f => ({ ...f, onRowClick: () => loadFormandoDetail(f.id) }))}
                      keyField="id"
                      defaultPageSize={10}
                      emptyMessage={turmaFiltro ? `Nenhum formando encontrado na Turma ${turmaFiltro}.` : 'Nenhum formando encontrado.'}
                    />
                  </>
                );
              })()}
            </div>
          </div>
        )}

        {/* FICHA DETALHADA DO FORMANDO */}
        {view === 'formando_detail' && formandoAtual && (
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.5rem' }}>
              <button className="btn btn-secondary" onClick={() => setView('dashboard')}>
                ← Voltar para a Lista de Alunos
              </button>
              
              {/* Botões de Edição/Deleção do Aluno Restritos ao Admin */}
              {isAdmin && (
                <div style={{ display: 'flex', gap: '0.5rem' }}>
                  <button className="btn btn-secondary btn-sm" onClick={() => {
                    setModalFormandoData({
                      id: formandoAtual.id,
                      numero_aluno: formandoAtual.numero_aluno || '',
                      nome: formandoAtual.nome,
                      cgm: formandoAtual.cgm || '',
                      turma: formandoAtual.turma,
                      telefone: formandoAtual.telefone || '',
                      convidados_extra: formandoAtual.convidados_extra,
                      observacoes: formandoAtual.observacoes || ''
                    });
                    setShowModalFormando(true);
                  }}>
                    ✏️ Editar Dados
                  </button>
                  <button className="btn btn-danger btn-sm" onClick={() => handleDeleteFormando(formandoAtual.id)}>
                    🗑️ Excluir Aluno
                  </button>
                </div>
              )}
            </div>

            {/* CABEÇALHO DO ALUNO */}
            <div className="glass-panel" style={{ padding: '1.75rem', marginBottom: '1.5rem' }}>
              <div style={{ borderBottom: '1px solid var(--bg-card-border)', paddingBottom: '1rem', marginBottom: '1.25rem', display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: '1rem' }}>
                <div>
                  <span style={{ fontSize: '0.85rem', color: '#94a3b8', textTransform: 'uppercase' }}>Ficha Individual do Formando</span>
                  <h1 style={{ fontSize: '2rem', fontWeight: '800', color: 'var(--text-main)' }}>🎓 {formandoAtual.nome}</h1>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <span className="badge badge-success" style={{ fontSize: '1rem', padding: '0.4rem 1rem' }}>Turma: {formandoAtual.turma}</span>
                  <div style={{ marginTop: '0.4rem', color: '#94a3b8', fontSize: '0.9rem' }}>
                    🔢 Nº: <strong style={{ color: '#e2e8f0' }}>{formandoAtual.numero_aluno ? `#${formandoAtual.numero_aluno}` : 'Não informado'}</strong> | 📋 CGM: <strong style={{ color: '#e2e8f0' }}>{formandoAtual.cgm || 'Não informado'}</strong> | 📞 Tel: {formandoAtual.telefone || 'Não informado'}
                  </div>
                </div>
              </div>

              {/* HIGHLIGHTS EM DESTAQUE */}
              <div className="formando-highlights">
                <div className="highlight-box total-a-pagar">
                  <div className="highlight-title">Somatória Valor a Pagar</div>
                  <div className="highlight-amount" style={{ color: '#a5b4fc' }}>
                    R$ {formandoAtual.valor_total_a_pagar.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                  </div>
                  <div style={{ fontSize: '0.75rem', color: '#94a3b8', marginTop: '0.25rem' }}>
                    {formandoAtual.convidados_extra} pessoa(s) × R$ {formandoAtual.valor_extra_unitario} por pessoa
                  </div>
                </div>

                <div className="highlight-box total-pago">
                  <div className="highlight-title">Somatória Valores Pagos</div>
                  <div className="highlight-amount" style={{ color: '#34d399' }}>
                    R$ {formandoAtual.total_pago.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                  </div>
                  <div style={{ fontSize: '0.75rem', color: '#34d399', marginTop: '0.25rem' }}>
                    {formandoAtual.pagamentos ? formandoAtual.pagamentos.length : 0} parcela(s) quitada(s)
                  </div>
                </div>

                <div className="highlight-box falta-pagar">
                  <div className="highlight-title">Quanto Falta Pagar</div>
                  <div className="highlight-amount" style={{ color: formandoAtual.saldo_devedor > 0 ? '#fca5a5' : '#34d399' }}>
                    R$ {formandoAtual.saldo_devedor.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                  </div>
                  <div style={{ fontSize: '0.75rem', color: '#94a3b8', marginTop: '0.25rem' }}>
                    {formandoAtual.saldo_devedor === 0 ? '✨ Totalmente Quitado!' : 'Saldo Restante'}
                  </div>
                </div>
              </div>

              {/* SIMULADOR DE VALORES DAS PARCELAS */}
              <div style={{ background: 'rgba(15, 23, 42, 0.6)', padding: '1.25rem', borderRadius: '12px', border: '1px dashed rgba(99, 102, 241, 0.4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '1rem' }}>
                  <div>
                    <h4 style={{ fontSize: '1rem', fontWeight: '700', color: '#818cf8' }}>🧮 Simulador de Parcelas para o Aluno</h4>
                    <p style={{ fontSize: '0.825rem', color: '#94a3b8' }}>Escolha o número de parcelas para calcular o valor estimado de cada mês:</p>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                    <label style={{ fontSize: '0.9rem', fontWeight: '600' }}>Nº de Parcelas:</label>
                    <select
                      className="form-control"
                      style={{ width: '90px' }}
                      value={simularParcelas}
                      onChange={e => setSimularParcelas(parseInt(e.target.value) || 1)}
                    >
                      {[...Array(config.max_parcelas || 12)].map((_, i) => (
                        <option key={i + 1} value={i + 1}>{i + 1}x</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div style={{ marginTop: '1rem', padding: '0.75rem 1rem', background: 'rgba(99, 102, 241, 0.1)', borderRadius: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <div>
                    <span>Plano de Parcelamento em <strong>{simularParcelas}x</strong> (sobre o Valor Total):</span>
                  </div>
                  <div style={{ fontSize: '1.25rem', fontWeight: '800', color: '#a5b4fc' }}>
                    {simularParcelas}x de R$ {(formandoAtual.valor_total_a_pagar > 0 ? (formandoAtual.valor_total_a_pagar / simularParcelas) : 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} / mês
                  </div>
                </div>
              </div>
            </div>

            {/* TABELA DE PARCELAS E LANÇAMENTO DE PAGAMENTOS */}
            <div className="glass-panel" style={{ padding: '1.75rem' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem', flexWrap: 'wrap', gap: '1rem' }}>
                <h3 style={{ fontSize: '1.25rem', fontWeight: '700' }}>📋 Registro de Pagamentos & Parcelas</h3>
                
                {/* BOTÃO DISPONÍVEL PARA AMBOS PERFIS: RECEBER PAGAMENTO */}
                <button className="btn btn-success" onClick={() => {
                  const numProx = (formandoAtual.pagamentos ? formandoAtual.pagamentos.length : 0) + 1;
                  const valorParcelaCalculado = formandoAtual.valor_total_a_pagar > 0 
                    ? (Math.round((formandoAtual.valor_total_a_pagar / simularParcelas) * 100) / 100) 
                    : 0;
                  const saldoDev = Math.round((formandoAtual.saldo_devedor || 0) * 100) / 100;
                  const valorInicial = Math.min(valorParcelaCalculado, Math.max(0, saldoDev));
                  setModalPagamentoData({
                    numero_parcela: numProx,
                    data_pagamento: new Date().toISOString().split('T')[0],
                    valor: valorInicial,
                    forma_pagamento: 'Pix',
                    observacao: ''
                  });
                  setShowModalPagamento(true);
                }}>
                  💳 Receber Pagamento
                </button>
              </div>

              {(() => {
                const colsPagamentos = [
                  {
                    header: 'Parcela',
                    accessor: 'numero_parcela',
                    render: p => <strong style={{ fontSize: '1.1rem', color: '#818cf8' }}>{p.numero_parcela}ª</strong>
                  },
                  {
                    header: 'Data',
                    accessor: 'data_pagamento',
                    render: p => new Date(p.data_pagamento + 'T00:00:00').toLocaleDateString('pt-BR')
                  },
                  {
                    header: 'Valor Pago & Recibo PDF',
                    accessor: 'valor',
                    render: p => (
                      <div className="receipt-btn-wrap" style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', flexWrap: 'wrap' }}>
                        <span style={{ fontSize: '1.1rem', fontWeight: '800', color: '#34d399', marginRight: '0.25rem' }}>
                          R$ {parseFloat(p.valor).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                        </span>
                        <a
                          href={`../api/recibo.php?id=${p.id}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="btn btn-primary btn-sm"
                          title="Imprimir Recibo em PDF a qualquer hora"
                          style={{ background: 'linear-gradient(135deg, #6366f1, #4f46e5)', border: 'none' }}
                        >
                          🖨️ Imprimir PDF
                        </a>
                        <button
                          type="button"
                          className="btn btn-sm"
                          style={{ background: 'linear-gradient(135deg, #10b981, #059669)', color: '#fff', border: 'none', fontWeight: 'bold' }}
                          onClick={() => handleSendWhatsApp(p)}
                          title="Enviar Comprovante via WhatsApp para o formando"
                        >
                          📲 Enviar WhatsApp
                        </button>
                      </div>
                    )
                  },
                  {
                    header: 'Forma de Pagamento',
                    accessor: 'forma_pagamento',
                    render: p => <span className="badge badge-warning">{p.forma_pagamento}</span>
                  },
                  ...(isAdmin ? [{
                    header: 'Ações',
                    sortable: false,
                    render: p => (
                      <button className="btn btn-danger btn-sm" onClick={() => handleDeletePagamento(p.id)} title="Excluir Parcela">
                        🗑️ Excluir
                      </button>
                    )
                  }] : [])
                ];

                return (
                  <DataTable
                    columns={colsPagamentos}
                    data={formandoAtual.pagamentos || []}
                    keyField="id"
                    defaultPageSize={10}
                    emptyMessage='Nenhum pagamento registrado ainda para este formando. Clique no botão "Receber Pagamento".'
                  />
                );
              })()}
            </div>
          </div>
        )}

        {/* MODAL CADASTRAR/EDITAR FORMANDO (SOMENTE ADMIN) */}
        {showModalFormando && isAdmin && (
          <div className="modal-overlay">
            <div className="glass-panel modal-content">
              <h3 style={{ fontSize: '1.25rem', fontWeight: '700', marginBottom: '1rem' }}>
                {modalFormandoData.id ? '✏️ Editar Dados do Aluno' : '🎓 Novo Aluno / Formando'}
              </h3>
              <form onSubmit={handleSaveFormando}>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '1rem' }}>
                  <div className="form-group">
                    <label className="form-label">Nº na Chamada</label>
                    <input
                      type="number"
                      min="1"
                      className="form-control"
                      value={modalFormandoData.numero_aluno || ''}
                      onChange={e => setModalFormandoData({ ...modalFormandoData, numero_aluno: e.target.value ? parseInt(e.target.value) : '' })}
                      placeholder="ex: 1"
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Nome Completo do Aluno(a)*</label>
                    <input
                      type="text"
                      className="form-control"
                      value={modalFormandoData.nome}
                      onChange={e => setModalFormandoData({ ...modalFormandoData, nome: e.target.value })}
                      placeholder="ex: Kauan da Silva"
                      required
                    />
                  </div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '1rem' }}>
                  <div className="form-group">
                    <label className="form-label">Nº do CGM (Matrícula)</label>
                    <input
                      type="text"
                      className="form-control"
                      value={modalFormandoData.cgm || ''}
                      onChange={e => setModalFormandoData({ ...modalFormandoData, cgm: e.target.value })}
                      placeholder="ex: 2026001"
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Turma Cadastrada*</label>
                    <select
                      className="form-control"
                      value={modalFormandoData.turma}
                      onChange={e => setModalFormandoData({ ...modalFormandoData, turma: e.target.value })}
                      required
                    >
                      <option value="">-- Selecione a Turma --</option>
                      {listaTurmas.map(t => (
                        <option key={t.id || t.nome} value={t.nome}>
                          Turma {t.nome}
                        </option>
                      ))}
                      {modalFormandoData.turma && !listaTurmas.some(t => t.nome === modalFormandoData.turma) && (
                        <option value={modalFormandoData.turma}>{modalFormandoData.turma}</option>
                      )}
                    </select>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Status de Participação*</label>
                    <select
                      className="form-control"
                      value={modalFormandoData.participa_formatura !== undefined ? modalFormandoData.participa_formatura : 1}
                      onChange={e => setModalFormandoData({ ...modalFormandoData, participa_formatura: parseInt(e.target.value) })}
                    >
                      <option value={1}>🎓 Formando (Participa)</option>
                      <option value={0}>⚪ Não Formando (Apenas Lista)</option>
                    </select>
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label">Telefone de Contato (WhatsApp)</label>
                  <input
                    type="text"
                    className="form-control"
                    value={modalFormandoData.telefone}
                    onChange={e => setModalFormandoData({ ...modalFormandoData, telefone: maskPhone(e.target.value) })}
                    placeholder="ex: (41) 99248-9676"
                    maxLength={15}
                  />
                </div>

                <div className="form-group">
                  <label className="form-label">Quantidade de Pessoas / Convites Cadastrados*</label>
                  <input
                    type="number"
                    min="1"
                    className="form-control"
                    value={modalFormandoData.convidados_extra || 1}
                    onChange={e => setModalFormandoData({ ...modalFormandoData, convidados_extra: parseInt(e.target.value) || 1 })}
                    required
                  />
                  <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}>
                    Total do aluno: {modalFormandoData.convidados_extra || 1} pessoa(s) × R$ {config.valor_pessoa_extra} = R$ {((modalFormandoData.convidados_extra || 1) * (config.valor_pessoa_extra || 80)).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}.
                  </span>
                </div>

                <div className="form-group">
                  <label className="form-label">Observações</label>
                  <textarea
                    className="form-control"
                    rows="2"
                    value={modalFormandoData.observacoes}
                    onChange={e => setModalFormandoData({ ...modalFormandoData, observacoes: e.target.value })}
                  ></textarea>
                </div>

                <div style={{ display: 'flex', gap: '1rem', justifyContent: 'flex-end', marginTop: '1.5rem' }}>
                  <button type="button" className="btn btn-secondary" onClick={() => setShowModalFormando(false)}>
                    Cancelar
                  </button>
                  <button type="submit" className="btn btn-success">
                    {modalFormandoData.id ? 'Salvar Alterações' : 'Cadastrar Formando'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL LANÇAR PAGAMENTO (DISPONÍVEL PARA ADMIN E USUÁRIO COMUM) */}
        {showModalPagamento && (
          <div className="modal-overlay">
            <div className="glass-panel modal-content">
              <h3 style={{ fontSize: '1.25rem', fontWeight: '700', marginBottom: '1rem' }}>
                💳 Receber Pagamento / Parcela
              </h3>
              <form onSubmit={handleSavePagamento}>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                  <div className="form-group">
                    <label className="form-label">Número da Parcela {!isAdmin && '(Fixo)'}</label>
                    <input
                      type="number"
                      min="1"
                      className="form-control"
                      value={modalPagamentoData.numero_parcela}
                      onChange={e => setModalPagamentoData({ ...modalPagamentoData, numero_parcela: parseInt(e.target.value) || 1 })}
                      disabled={!isAdmin} // Bloqueado para Usuário Comum
                      required
                    />
                    {!isAdmin && <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>Apenas o administrador altera a numeração da parcela.</span>}
                  </div>

                  <div className="form-group">
                    <label className="form-label">Data do Pagamento</label>
                    <input
                      type="date"
                      className="form-control"
                      value={modalPagamentoData.data_pagamento}
                      onChange={e => setModalPagamentoData({ ...modalPagamentoData, data_pagamento: e.target.value })}
                      required
                    />
                  </div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                  <div className="form-group">
                    <label className="form-label">Valor Pago (R$)*</label>
                    <input
                      type="number"
                      step="0.01"
                      min="0.01"
                      max={formandoAtual ? formandoAtual.saldo_devedor : undefined}
                      className="form-control"
                      style={{ fontSize: '1.1rem', fontWeight: 'bold', color: '#34d399' }}
                      value={modalPagamentoData.valor}
                      onChange={e => setModalPagamentoData({ ...modalPagamentoData, valor: parseFloat(e.target.value) || 0 })}
                      required
                    />
                    <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>
                      Máximo permitido: <strong style={{ color: '#fca5a5' }}>R$ {(formandoAtual?.saldo_devedor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</strong> (Quanto Falta Pagar).
                    </span>
                  </div>

                  <div className="form-group">
                    <label className="form-label">Forma de Pagamento</label>
                    <select
                      className="form-control"
                      value={modalPagamentoData.forma_pagamento}
                      onChange={e => setModalPagamentoData({ ...modalPagamentoData, forma_pagamento: e.target.value })}
                    >
                      {(config.formas_pagamento || 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto')
                        .split(',')
                        .map(s => s.trim())
                        .filter(Boolean)
                        .map((forma, idx) => (
                          <option key={idx} value={forma}>{forma}</option>
                        ))}
                    </select>
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label">Observação (Opcional)</label>
                  <input
                    type="text"
                    className="form-control"
                    placeholder="ex: Pago via Pix com comprovante"
                    value={modalPagamentoData.observacao}
                    onChange={e => setModalPagamentoData({ ...modalPagamentoData, observacao: e.target.value })}
                  />
                </div>

                {/* BLOCO QR CODE PIX DINÂMICO (PADRÃO BR CODE BANCO CENTRAL) */}
                {modalPagamentoData.forma_pagamento.toLowerCase().includes('pix') && (() => {
                  const activePixKey = findPixKeyForForma(modalPagamentoData.forma_pagamento, config);
                  const pixPayload = generatePixPayload(
                    activePixKey,
                    config.titulo_formatura || 'FORMATURA 2026',
                    'CURITIBA',
                    modalPagamentoData.valor || 0
                  );

                  return (
                    <div style={{ background: 'rgba(16, 185, 129, 0.1)', border: '1px solid rgba(16, 185, 129, 0.3)', borderRadius: '12px', padding: '1.25rem', marginTop: '1rem', textAlign: 'center' }}>
                      <h4 style={{ color: '#34d399', margin: '0 0 0.25rem 0', fontSize: '1rem', fontWeight: '700' }}>
                        📱 QR Code Pix Válido (Padrão Banco Central / Copia e Cola)
                      </h4>
                      <div style={{ fontSize: '0.85rem', color: '#e2e8f0', marginBottom: '0.75rem' }}>
                        Opção selecionada: <strong>{modalPagamentoData.forma_pagamento}</strong> | Chave: <strong style={{ color: '#34d399' }}>{activePixKey}</strong>
                      </div>
                      <div style={{ background: '#ffffff', padding: '10px', borderRadius: '12px', display: 'inline-block', marginBottom: '0.75rem' }}>
                        <img
                          src={`https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(pixPayload)}`}
                          alt="QR Code Pix"
                          style={{ width: '160px', height: '160px', display: 'block' }}
                        />
                      </div>
                      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'center' }}>
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={() => {
                            navigator.clipboard.writeText(pixPayload);
                            alert(`✅ Pix Copia e Cola copiado para a chave ${activePixKey}!`);
                          }}
                        >
                          📋 Copiar Pix Copia e Cola
                        </button>
                      </div>
                    </div>
                  );
                })()}

                <div style={{ display: 'flex', gap: '1rem', justifyContent: 'flex-end', marginTop: '1.5rem' }}>
                  <button type="button" className="btn btn-secondary" onClick={() => setShowModalPagamento(false)}>
                    Cancelar
                  </button>
                  <button type="submit" className="btn btn-success">
                    Confirmar Pagamento
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL DE SELEÇÃO DO FORMANDO (CADASTRAR TELEFONE E CONVIDADOS EXTRAS) */}
        {showModalSelecaoParticipante && (
          <div className="modal-overlay">
            <div className="glass-panel modal-content" style={{ maxWidth: '520px' }}>
              <h3 style={{ fontSize: '1.25rem', fontWeight: '700', marginBottom: '0.5rem', color: '#34d399', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                🎓 Definir Aluno como Formando
              </h3>
              <p style={{ fontSize: '0.85rem', color: '#94a3b8', marginBottom: '1.25rem' }}>
                Ao confirmar, o status de <strong>{alunoSelecaoData.nome}</strong> (Turma {alunoSelecaoData.turma}) será alterado para <strong>Formando</strong>.
              </p>

              <form onSubmit={e => {
                e.preventDefault();
                salvarStatusParticipacao(
                  alunoSelecaoData.id,
                  1,
                  alunoSelecaoData.telefone,
                  alunoSelecaoData.convidados_extra,
                  alunoSelecaoData.cgm || '',
                  alunoSelecaoData.numero_aluno || '',
                  alunoSelecaoData.nome || '',
                  alunoSelecaoData.turma || ''
                );
              }}>
                <div className="form-group">
                  <label className="form-label">Nome Completo do Aluno(a)*</label>
                  <input
                    type="text"
                    className="form-control"
                    value={alunoSelecaoData.nome || ''}
                    onChange={e => setAlunoSelecaoData({ ...alunoSelecaoData, nome: e.target.value })}
                    required
                  />
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                  <div className="form-group">
                    <label className="form-label">Nº Chamada</label>
                    <input
                      type="number"
                      min="1"
                      className="form-control"
                      placeholder="ex: 1"
                      value={alunoSelecaoData.numero_aluno || ''}
                      onChange={e => setAlunoSelecaoData({ ...alunoSelecaoData, numero_aluno: e.target.value ? parseInt(e.target.value) : '' })}
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label">Turma*</label>
                    <select
                      className="form-control"
                      value={alunoSelecaoData.turma || ''}
                      onChange={e => setAlunoSelecaoData({ ...alunoSelecaoData, turma: e.target.value })}
                      required
                    >
                      <option value="">-- Selecione --</option>
                      {listaTurmas.map(t => (
                        <option key={t.id || t.nome} value={t.nome}>Turma {t.nome}</option>
                      ))}
                      {alunoSelecaoData.turma && !listaTurmas.some(t => t.nome === alunoSelecaoData.turma) && (
                        <option value={alunoSelecaoData.turma}>{alunoSelecaoData.turma}</option>
                      )}
                    </select>
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label">Número do CGM (Matrícula)</label>
                  <input
                    type="text"
                    className="form-control"
                    placeholder="ex: 2026001"
                    value={alunoSelecaoData.cgm || ''}
                    onChange={e => setAlunoSelecaoData({ ...alunoSelecaoData, cgm: e.target.value })}
                  />
                </div>

                <div className="form-group">
                  <label className="form-label">Telefone de Contato (WhatsApp)*</label>
                  <input
                    type="text"
                    className="form-control"
                    placeholder="ex: (41) 99248-9676"
                    value={alunoSelecaoData.telefone}
                    onChange={e => setAlunoSelecaoData({ ...alunoSelecaoData, telefone: maskPhone(e.target.value) })}
                    maxLength={15}
                    required
                  />
                  <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>Usado para enviar recibos e comprovantes via WhatsApp.</span>
                </div>

                <div className="form-group">
                  <label className="form-label">Quantidade de Pessoas / Convites Cadastrados*</label>
                  <input
                    type="number"
                    min="1"
                    className="form-control"
                    value={alunoSelecaoData.convidados_extra || 1}
                    onChange={e => setAlunoSelecaoData({ ...alunoSelecaoData, convidados_extra: parseInt(e.target.value) || 1 })}
                    required
                  />
                  <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>
                    Total do aluno: {alunoSelecaoData.convidados_extra || 1} pessoa(s) × R$ {config.valor_pessoa_extra} = R$ {((alunoSelecaoData.convidados_extra || 1) * (config.valor_pessoa_extra || 80)).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}.
                  </span>
                </div>

                <div style={{ display: 'flex', gap: '1rem', justifyContent: 'flex-end', marginTop: '1.5rem' }}>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => setShowModalSelecaoParticipante(false)}
                  >
                    Cancelar
                  </button>
                  <button type="submit" className="btn btn-success" style={{ fontWeight: 'bold' }}>
                    ✅ Confirmar e Ir para Gerenciamento
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
