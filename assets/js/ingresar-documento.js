(() => {
  'use strict';

  const $ = (selector) => document.querySelector(selector);
  const fileInput = $('#documentFile');
  const cameraInput = $('#cameraFile');
  const analyzeButton = $('#analyzeButton');
  const saveButton = $('#saveButton');
  const form = $('#documentForm');
  const context = window.CAP_OCR_CONTEXT || { companies: [], providers: [] };
  if (!fileInput || !form) return;

  let workerPromise = null;
  let mode = 'ocr';
  const setMode = (nextMode) => {
    mode = nextMode;
    $('#methodInput').value = nextMode;
    $('#ocrPanel').classList.toggle('d-none', nextMode !== 'ocr');
    $('#reviewFields').classList.toggle('d-none', nextMode === 'ocr' && !$('#reviewFields').dataset.ready);
    saveButton.classList.toggle('d-none', nextMode === 'ocr' && !$('#reviewFields').dataset.ready);
    document.querySelectorAll('[data-entry-mode]').forEach((button) => {
      button.classList.toggle('active', button.dataset.entryMode === nextMode);
      button.setAttribute('aria-pressed', button.dataset.entryMode === nextMode ? 'true' : 'false');
    });
  };

  document.querySelectorAll('[data-entry-mode]').forEach((button) => {
    button.addEventListener('click', () => setMode(button.dataset.entryMode));
  });
  $('#addItemRow').addEventListener('click', () => addItemRow());
  $('#itemRows').addEventListener('click', (event) => {
    if (!event.target.closest('.remove-item-row')) return;
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) event.target.closest('.item-row').remove();
    else event.target.closest('.item-row').querySelectorAll('input').forEach((input) => { input.value = input.name.includes('descuento') ? '0' : ''; });
  });
  $('#fileButton').addEventListener('click', () => fileInput.click());
  $('#cameraButton').addEventListener('click', () => cameraInput.click());
  $('#publicSearchButton').addEventListener('click', () => {
    const name = $('#emisorNombre').value.trim();
    const rtn = normalizeRtn($('#rtnEmisor').value);
    if (!name && !rtn) {
      $('#providerMatchMessage').textContent = 'Primero ingresa el nombre o RTN del proveedor para preparar la búsqueda.';
      $('#providerMatchMessage').className = 'form-text text-warning';
      $('#emisorNombre').focus();
      return;
    }
    const query = [name, rtn, 'Honduras teléfono oficial'].filter(Boolean).join(' ');
    window.open(`https://www.google.com/search?q=${encodeURIComponent(query)}`, '_blank', 'noopener,noreferrer');
  });

  const setSelectedFile = (file) => {
    if (!file) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    fileInput.files = transfer.files;
    $('#fileName').textContent = file.name;
    analyzeButton.classList.remove('d-none');
    $('#ocrError').classList.add('d-none');
  };
  fileInput.addEventListener('change', () => setSelectedFile(fileInput.files[0]));
  cameraInput.addEventListener('change', () => setSelectedFile(cameraInput.files[0]));

  const parseMoney = (value) => {
    let normalized = String(value || '').replace(/[^\d,.-]/g, '');
    if (!normalized) return null;
    const comma = normalized.lastIndexOf(',');
    const dot = normalized.lastIndexOf('.');
    if (comma > dot) normalized = normalized.replace(/\./g, '').replace(',', '.');
    else normalized = normalized.replace(/,/g, '');
    const amount = Number.parseFloat(normalized);
    return Number.isFinite(amount) ? amount : null;
  };

  const lastMoneyOnLine = (line) => {
    const matches = [...line.matchAll(/(?:L\.?|HNL|USD|US\$|\$)?\s*(-?\d[\d,.]*\d|\d)(?:\s*(?:L|HNL))?/gi)];
    return matches.length ? parseMoney(matches[matches.length - 1][1]) : null;
  };

  const normalizeRtn = (value) => String(value || '').replace(/\D/g, '');
  const findRtns = (lines) => {
    const found = [];
    const rtnPattern = /(?:\d[\d -]{11,18}\d)/g;
    lines.forEach((line, index) => {
      for (const match of line.matchAll(rtnPattern)) {
        const value = normalizeRtn(match[0]);
        if (value.length === 14 && !found.some((item) => item.value === value)) {
          found.push({ value, index, context: lines.slice(Math.max(0, index - 1), Math.min(lines.length, index + 2)).join(' ') });
        }
      }
    });
    return found;
  };

  const matchRtnField = (rtn) => {
    const clean = normalizeRtn(rtn);
    return clean.length === 14 ? clean : '';
  };

  const applyCompanyMatch = (rtn) => {
    const clean = matchRtnField(rtn);
    $('#rtnCliente').value = clean;
    const company = context.companies.find((entry) => normalizeRtn(entry.rtn) === clean);
    const select = $('#empresaGrupo');
    if (company) {
      select.value = String(company.id);
      $('#companyMatchMessage').textContent = `RTN confirmado en BD: ${company.name}.`;
      $('#companyMatchMessage').className = 'form-text text-success';
    } else {
      select.value = '';
      $('#companyMatchMessage').textContent = clean ? 'Ese RTN no coincide con una empresa CAP registrada. Selecciona la empresa correcta manualmente y verifica el dato.' : 'No se reconoció el RTN del cliente. Selecciona la empresa CAP contra el comprobante.';
      $('#companyMatchMessage').className = 'form-text text-warning';
    }
  };

  const applyProviderMatch = (rtn, suggestedName = '') => {
    const clean = matchRtnField(rtn);
    $('#rtnEmisor').value = clean;
    const provider = context.providers.find((entry) => normalizeRtn(entry.rtn) === clean);
    const select = $('#proveedorExistente');
    if (provider) {
      select.value = String(provider.id);
      $('#emisorNombre').value = provider.name;
      $('#categoriaProveedor').value = String(provider.category);
      $('#providerMatchMessage').textContent = `Proveedor reconocido por RTN: ${provider.name}.`;
      $('#providerMatchMessage').className = 'form-text text-success';
    } else {
      select.value = '';
      if (suggestedName && !$('#emisorNombre').value) $('#emisorNombre').value = suggestedName.slice(0, 150);
      $('#providerMatchMessage').textContent = clean ? 'No encontramos ese RTN en la BD. Completa el nombre y la categoría para registrar al proveedor.' : 'No se detectó RTN del emisor. Ingresa el nombre; puede guardarse sin vincular un proveedor hondureño.';
      $('#providerMatchMessage').className = 'form-text text-warning';
    }
  };

  $('#proveedorExistente').addEventListener('change', (event) => {
    const option = event.target.selectedOptions[0];
    if (!option || !option.value) return;
    $('#emisorNombre').value = option.dataset.name || '';
    $('#rtnEmisor').value = normalizeRtn(option.dataset.rtn || '');
    $('#categoriaProveedor').value = option.dataset.category || '';
  });
  $('#rtnCliente').addEventListener('input', (event) => {
    if (normalizeRtn(event.target.value).length === 14) applyCompanyMatch(event.target.value);
    else {
      $('#empresaGrupo').value = '';
      $('#companyMatchMessage').textContent = 'Completa los 14 dígitos del RTN para comprobar la empresa CAP.';
      $('#companyMatchMessage').className = 'form-text text-secondary';
    }
  });
  $('#rtnEmisor').addEventListener('input', (event) => {
    const clean = normalizeRtn(event.target.value);
    if (clean.length !== 14) {
      $('#proveedorExistente').value = '';
      $('#providerMatchMessage').textContent = 'Completa los 14 dígitos del RTN para buscar un proveedor registrado.';
      $('#providerMatchMessage').className = 'form-text text-secondary';
      return;
    }
    const match = context.providers.find((entry) => normalizeRtn(entry.rtn) === clean);
    if (match) applyProviderMatch(match.rtn, match.name);
    else {
      $('#proveedorExistente').value = '';
      $('#providerMatchMessage').textContent = 'RTN no encontrado; ingresa el nombre y categoría del proveedor.';
      $('#providerMatchMessage').className = 'form-text text-warning';
    }
  });

  const setValue = (selector, value) => {
    if (value !== null && value !== undefined && String(value).trim() !== '') $(selector).value = value;
  };
  const firstLineFor = (lines, pattern) => {
    const index = lines.findIndex((line) => pattern.test(line));
    return index < 0 ? '' : lines[index];
  };
  const amountFromLabel = (lines, patterns) => {
    for (const line of lines) {
      if (patterns.some((pattern) => pattern.test(line))) {
        const value = lastMoneyOnLine(line);
        if (value !== null) return value;
      }
    }
    return null;
  };
  const extractFields = (raw) => {
    const text = raw.replace(/\r/g, '\n').replace(/[\t ]+/g, ' ');
    const lines = text.split('\n').map((line) => line.trim()).filter(Boolean);
    const lower = text.toLocaleLowerCase('es');
    const amazon = /amazon|order\s*(?:number|#)|n[uú]mero\s+de\s+pedido/i.test(lower);
    const rtns = findRtns(lines);
    const clientRtn = amazon ? null : rtns.find((entry) => /cliente|comprador|recib[ií]\s+de|nombre:/i.test(entry.context));
    const supplierRtn = amazon ? null : rtns.find((entry) => entry !== clientRtn);
    const caiLine = firstLineFor(lines, /\bca[i1]\b/i);
    const caiMatch = caiLine.match(/\bCA[I1]\s*[:.]?\s*([A-Z0-9][A-Z0-9-]{7,})/i);
    const invoiceMatch = text.match(/(?:factura|recibo|n[úuº°.]?\s*o?)[\s:#nº°-]{0,16}([0-9]{3,}(?:[-/][0-9A-Z]+){1,5}|[0-9]{5,})/i);
    const dateLine = lines.find((line) => /fecha/i.test(line) && !/l[ií]mite|vencimiento|emisi[oó]n hasta/i.test(line)) || '';
    const dateMatch = (dateLine || text).match(/\b(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})\b/);
    let dateValue = '';
    if (dateMatch) {
      let year = Number(dateMatch[3]);
      if (year < 100) year += year < 50 ? 2000 : 1900;
      const month = String(Number(dateMatch[2])).padStart(2, '0');
      const day = String(Number(dateMatch[1])).padStart(2, '0');
      if (Number(month) <= 12 && Number(day) <= 31) dateValue = `${year}-${month}-${day}`;
    }

    const honorarios = /recibo\s+por\s+honorarios/i.test(lower);
    let type = amazon ? 'amazon' : (honorarios ? 'recibo_honorarios' : (/recibo/i.test(lower) ? 'recibo' : 'factura'));
    const getLabeled = (patterns) => {
      const line = lines.find((item) => patterns.some((pattern) => pattern.test(item)));
      return line ? lastMoneyOnLine(line) : null;
    };
    let total = amountFromLabel(lines, [/total\s+a\s+pagar/i, /total\s+neto\s+recibido/i, /total\s+por\s+honorarios/i, /^total\b/i, /total\s+pagado/i]);
    let subtotal = amountFromLabel(lines, [/sub[- ]?total/i, /importe\s+gravado/i, /subtotal/i]);
    const isvLine = lines.find((line) => /\bISV\b|impuesto\s+s(?:obre)?\s+ventas/i.test(line) && !/18\s*%/i.test(line));
    let isv = isvLine ? lastMoneyOnLine(isvLine) : null;
    if (subtotal === null && total !== null && isv !== null && total >= isv) subtotal = Number((total - isv).toFixed(2));
    if (total === null && subtotal !== null) total = Number((subtotal + (isv || 0)).toFixed(2));

    const currency = /\bUSD\b|US\$|\$\s*\d/.test(text) ? 'USD' : (/\bEUR\b|€/.test(text) ? 'EUR' : 'HNL');
    const companyNameLine = lines.find((line) => /cliente\s*:/i.test(line)) || lines.find((line) => /recib[ií]\s+de/i.test(line)) || '';
    const supplierName = lines.slice(0, 8).find((line) => line.length > 4 && !/r\.?t\.?n|cai\s*[:.]|factura|recibo|tel[eé]fono|correo|e-mail/i.test(line)) || '';
    const reference = amazon ? (text.match(/\b\d{3}-\d{7}-\d{7}\b/) || [])[0] : '';
    const items = [];
    const headerIndex = lines.findIndex((line) => /cant(?:idad)?/i.test(line) && /descrip/i.test(line));
    if (headerIndex >= 0) {
      for (const line of lines.slice(headerIndex + 1)) {
        if (/subtotal|total\s+a\s+pagar|importe\s+gravado|\bISV\b|exonerad[oa]|exent[oa]/i.test(line)) break;
        const itemMatch = line.match(/^\s*(\d{1,5}(?:[.,]\d{1,2})?)\s+(.{3,}?)\s+(?:L\.?\s*)?([\d,]+\.\d{2})\s+(?:L\.?\s*)?([\d,]+\.\d{2})\s*$/i);
        if (itemMatch && items.length < 30) {
          items.push({ description: itemMatch[2].trim(), quantity: parseMoney(itemMatch[1]) || 1, unitPrice: parseMoney(itemMatch[3]) || 0, discount: 0 });
        }
      }
    }
    return {
      type,
      rtnClient: clientRtn?.value || '',
      rtnSupplier: supplierRtn?.value || '',
      cai: caiMatch?.[1] || '',
      invoice: invoiceMatch?.[1] || '',
      date: dateValue,
      supplierName,
      customerLine: companyNameLine,
      reference,
      currency,
      subtotal,
      isv,
      total,
      exento: getLabeled([/exent[oa]/i]),
      exonerado: getLabeled([/exonerad[oa]/i]),
      gravado15: amountFromLabel(lines, [/gravado\s*15%/i]),
      discount: amountFromLabel(lines, [/descuentos?|rebajas?/i]),
      shipment: amountFromLabel(lines, [/env[ií]o|shipping/i]),
      withholding: amountFromLabel(lines, [/retenci[oó]n\s*I\.?S\.?R/i]),
      items,
      raw: text.slice(0, 60000),
    };
  };

  function addItemRow(item = {}) {
    const wrapper = document.createElement('div');
    wrapper.className = 'row g-2 align-items-end item-row';
    wrapper.innerHTML = '<div class="col-12 col-md-6"><label class="form-label small">Descripción</label><input class="form-control" name="detalle_descripcion[]" maxlength="255"></div>'
      + '<div class="col-4 col-md-2"><label class="form-label small">Cantidad</label><input class="form-control" name="detalle_cantidad[]" type="number" min="0.01" step="0.01"></div>'
      + '<div class="col-4 col-md-2"><label class="form-label small">Precio unitario</label><input class="form-control" name="detalle_precio[]" type="number" min="0" step="0.01"></div>'
      + '<div class="col-4 col-md-2"><label class="form-label small">Descuento</label><input class="form-control" name="detalle_descuento[]" type="number" min="0" step="0.01" value="0"><button class="btn btn-link text-danger btn-sm px-0 remove-item-row" type="button">Quitar</button></div>';
    wrapper.querySelector('[name="detalle_descripcion[]"]').value = item.description || '';
    wrapper.querySelector('[name="detalle_cantidad[]"]').value = item.quantity ?? '';
    wrapper.querySelector('[name="detalle_precio[]"]').value = item.unitPrice ?? '';
    wrapper.querySelector('[name="detalle_descuento[]"]').value = item.discount ?? '0';
    $('#itemRows').append(wrapper);
  }

  const toCanvas = async (file, pageNumber = 1) => {
    if (file.type !== 'application/pdf') {
      const image = new Image();
      const url = URL.createObjectURL(file);
      try {
        await new Promise((resolve, reject) => {
          image.onload = resolve;
          image.onerror = reject;
          image.src = url;
        });
        const scale = Math.min(2, 2200 / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        canvas.getContext('2d', { willReadFrequently: true }).drawImage(image, 0, 0, canvas.width, canvas.height);
        return canvas;
      } finally { URL.revokeObjectURL(url); }
    }
    if (!window.pdfjsLib) throw new Error('No se pudo cargar el lector de PDF. Comprueba la conexión e inténtalo de nuevo.');
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const bytes = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: bytes }).promise;
    const page = await pdf.getPage(pageNumber);
    const initial = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: Math.min(2, 2200 / Math.max(initial.width, initial.height)) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);
    await page.render({ canvasContext: canvas.getContext('2d', { willReadFrequently: true }), viewport }).promise;
    return canvas;
  };

  const initializeWorker = async () => {
    if (!window.Tesseract) throw new Error('No se pudo cargar el motor OCR. Comprueba la conexión a internet e inténtalo de nuevo.');
    if (!workerPromise) {
      let workerError = null;
      const loadPromise = Tesseract.createWorker('spa', 1, {
        workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.0.4/dist/worker.min.js',
        corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5.0.0',
        langPath: 'https://cdn.jsdelivr.net/npm/@tesseract.js-data/spa@1.0.0/4.0.0_best_int',
        errorHandler: (error) => { workerError = error; },
        logger: (message) => {
          if (message.status) {
            const statuses = {
              'loading tesseract core': 'Descargando motor OCR…',
              'loading language traineddata': 'Descargando modelo de español (primera vez)…',
              'initializing tesseract': 'Inicializando lector OCR…',
              'recognizing text': 'Reconociendo texto…',
            };
            $('#ocrStatus').textContent = statuses[message.status] || 'Preparando motor OCR…';
          }
          if (Number.isFinite(message.progress)) {
            const percent = Math.max(0, Math.min(100, Math.round(message.progress * 100)));
            $('#ocrPercent').textContent = `${percent}%`;
            $('#ocrProgress').style.width = `${percent}%`;
          }
        },
      });
      let timeoutId;
      let timedOut = false;
      const timeoutPromise = new Promise((resolve, reject) => {
        timeoutId = window.setTimeout(() => {
          timedOut = true;
          reject(new Error(
            workerError
              ? `Falló la carga del lector OCR: ${String(workerError.message || workerError)}`
              : 'La descarga del motor o del modelo OCR tardó demasiado. Comprueba tu conexión, desactiva bloqueadores para localhost y vuelve a intentarlo.'
          ));
        }, 120000);
      });
      loadPromise.then((worker) => {
        if (timedOut) worker.terminate();
      }).catch(() => {});
      workerPromise = Promise.race([loadPromise, timeoutPromise]).then((worker) => {
        window.clearTimeout(timeoutId);
        return worker;
      }).catch((error) => {
        window.clearTimeout(timeoutId);
        workerPromise = null;
        throw error;
      });
    }
    return workerPromise;
  };

  analyzeButton.addEventListener('click', async () => {
    const file = fileInput.files[0];
    if (!file) return;
    const validImage = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
    const validPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
    if (!validImage && !validPdf) return showOcrError('Formato no admitido. Usa JPG, PNG, WEBP o PDF.');
    if (file.size > 15 * 1024 * 1024) return showOcrError('El archivo supera el límite de 15 MB.');
      $('#ocrError').classList.add('d-none');
      $('#ocrProgressBlock').classList.remove('d-none');
      $('#ocrStatus').textContent = 'Conectando con el motor OCR…';
      $('#ocrPercent').textContent = '0%';
      $('#ocrProgress').style.width = '0%';
    analyzeButton.disabled = true;
    let worker;
    try {
      worker = await initializeWorker();
      let pages = 1;
      if (validPdf) {
        const documentPdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        pages = Math.min(documentPdf.numPages, 5);
      }
      const results = [];
      for (let page = 1; page <= pages; page += 1) {
        $('#ocrStatus').textContent = validPdf ? `Analizando página ${page} de ${pages}…` : 'Analizando imagen…';
        const canvas = await toCanvas(file, page);
        const result = await worker.recognize(canvas);
        results.push(result.data.text || '');
      }
      const raw = results.join('\n\n--- Página ---\n\n');
      fillForm(extractFields(raw));
      const reviewNotice = $('#reviewNotice');
      if (/\b(?:ISV|impuesto\s+s(?:obre)?\s+ventas|gravado)\s*18\s*%/i.test(raw)) {
        reviewNotice.textContent = 'El comprobante parece mencionar ISV 18%. Esta aplicación solo registra ISV al 15%; verifica el documento y no clasifiques ese importe automáticamente como 15%. El importe de ISV quedó sin completar.';
        reviewNotice.className = 'alert alert-warning';
      } else {
        reviewNotice.textContent = 'Los valores detectados son sugerencias. Confírmalos contra el comprobante.';
        reviewNotice.className = 'alert alert-info';
      }
      $('#reviewFields').dataset.ready = '1';
      $('#reviewFields').classList.remove('d-none');
      saveButton.classList.remove('d-none');
      $('#ocrStatus').textContent = 'Análisis terminado. Revisa los datos sugeridos.';
      $('#ocrPercent').textContent = `${pages} ${pages === 1 ? 'página' : 'páginas'}`;
      $('#reviewFields').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
      showOcrError(error instanceof Error ? error.message : 'No se pudo analizar el documento.');
    } finally {
      analyzeButton.disabled = false;
    }
  });

  function fillForm(data) {
    setValue('#tipoDocumento', data.type);
    setValue('#numeroDocumento', data.invoice);
    setValue('#referencia', data.reference);
    setValue('#cai', data.cai);
    setValue('#fechaDocumento', data.date);
    setValue('#moneda', data.currency);
    setValue('#rawTextPreview', data.raw);
    $('#rawOcrText').value = data.raw;
    if (data.rtnSupplier) applyProviderMatch(data.rtnSupplier, data.supplierName);
    else if (data.supplierName) $('#emisorNombre').value = data.supplierName.slice(0, 150);
    if (data.rtnClient) applyCompanyMatch(data.rtnClient);
    if (data.customerLine && !data.rtnClient) $('#companyMatchMessage').textContent = `OCR leyó: “${data.customerLine.slice(0, 180)}”. Confirma qué empresa CAP compró.`;
    const fields = [
      ['#subtotal', data.subtotal], ['#isv15', data.isv],
      ['#exento', data.exento], ['#exonerado', data.exonerado], ['#gravado15', data.gravado15],
      ['#descuento', data.discount], ['#envio', data.shipment], ['#isr', data.withholding],
    ];
    fields.forEach(([selector, value]) => { if (value !== null) setValue(selector, Number(value).toFixed(2)); });
    if (data.total !== null) setValue('#total', Number(data.total).toFixed(2));
    if (data.items?.length) {
      const firstRow = $('#itemRows .item-row');
      if (firstRow) firstRow.remove();
      data.items.forEach((item) => addItemRow(item));
    }
  }

  function showOcrError(message) {
    $('#ocrError').textContent = message;
    $('#ocrError').classList.remove('d-none');
  }

  $('#rawTextPreview').addEventListener('input', (event) => { $('#rawOcrText').value = event.target.value.slice(0, 60000); });

  form.addEventListener('submit', (event) => {
    if (mode === 'manual') $('#methodInput').value = 'manual';
    if (!form.reportValidity()) {
      event.preventDefault();
      return;
    }
    if ($('#rtnCliente').value && !$('#empresaGrupo').value) {
      event.preventDefault();
      $('#companyMatchMessage').textContent = 'Selecciona la empresa CAP correcta antes de guardar.';
      $('#companyMatchMessage').className = 'form-text text-danger';
      $('#empresaGrupo').focus();
    }
  });

  setMode('ocr');
})();
