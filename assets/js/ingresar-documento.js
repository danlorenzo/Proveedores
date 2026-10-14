(() => {
  'use strict';

  const $ = (selector) => document.querySelector(selector);
  const fileInput = $('#documentFile');
  const analyzeButton = $('#analyzeButton');
  const saveButton = $('#saveButton');
  const form = $('#documentForm');
  const context = window.CAP_OCR_CONTEXT || { companies: [], providers: [] };
  const embeddedMode = document.body.classList.contains('embedded-entry');
  const loadingModalElement = $('#ocrLoadingModal');
  const loadingModal = bootstrap.Modal.getOrCreateInstance(loadingModalElement, { backdrop: !embeddedMode });
  const showWorkflowModal = (element) => {
    if (!embeddedMode) {
      bootstrap.Modal.getOrCreateInstance(element).show();
      return;
    }
    element.style.display = 'block';
    element.classList.add('show');
    element.removeAttribute('aria-hidden');
  };
  const hideWorkflowModal = (element) => {
    if (!embeddedMode) {
      bootstrap.Modal.getOrCreateInstance(element).hide();
      return;
    }
    element.classList.remove('show');
    element.style.display = 'none';
    element.setAttribute('aria-hidden', 'true');
  };
  const circleCircumference = 2 * Math.PI * 44;
  let ocrPageCount = 1;
  let ocrCurrentPage = 1;
  const updateOcrProgress = (fraction) => {
    const value = Math.max(0, Math.min(1, fraction));
    $('#ocrCircleValue').style.strokeDashoffset = String(circleCircumference * (1 - value));
    $('#ocrPercent').textContent = `${Math.round(value * 100)}%`;
    $('.ocr-progress-circle').setAttribute('aria-valuenow', String(Math.round(value * 100)));
  };
  if (!fileInput || !form) return;

  let workerPromise = null;
  let mode = 'ocr';
  const setMode = (nextMode) => {
    mode = nextMode;
    $('#methodInput').value = nextMode;
    $('#ocrPanel').classList.toggle('d-none', nextMode !== 'ocr');
    $('#reviewFields').classList.toggle('d-none', nextMode === 'ocr' && !$('#reviewFields').dataset.ready);
    saveButton.classList.toggle('d-none', nextMode === 'ocr' && !$('#reviewFields').dataset.ready);
    if (nextMode === 'manual') showWorkflowModal($('#reviewModal'));
    document.querySelectorAll('[data-entry-mode]').forEach((button) => {
      button.classList.toggle('active', button.dataset.entryMode === nextMode);
      button.setAttribute('aria-pressed', button.dataset.entryMode === nextMode ? 'true' : 'false');
    });
  };

  document.querySelectorAll('[data-entry-mode]').forEach((button) => {
    button.addEventListener('click', () => setMode(button.dataset.entryMode));
  });
  if (embeddedMode) {
    const closeReview = () => window.parent.postMessage({ type: 'cap-entry-close' }, window.location.origin);
    $('#reviewCloseButton').removeAttribute('data-bs-dismiss');
    $('#reviewCloseButton').addEventListener('click', closeReview);
    $('#reviewCancelButton').addEventListener('click', (event) => {
      event.preventDefault();
      closeReview();
    });
  }
  $('#addItemRow').addEventListener('click', () => addItemRow());
  $('#itemRows').addEventListener('click', (event) => {
    if (!event.target.closest('.find-product-image')) return;
    const row = event.target.closest('.item-row');
    const description = row.querySelector('.item-description').value.trim();
    if (!description) { row.querySelector('.item-description').focus(); return; }
    const query = `${description} producto imagen Honduras`;
    window.open(`https://www.google.com/search?tbm=isch&q=${encodeURIComponent(query)}`, '_blank', 'noopener,noreferrer');
  });
  $('#itemRows').addEventListener('click', (event) => {
    if (!event.target.closest('.remove-item-row')) return;
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) event.target.closest('.item-row').remove();
    else event.target.closest('.item-row').querySelectorAll('input').forEach((input) => { input.value = ''; });
  });
  $('#fileButton').addEventListener('click', () => fileInput.click());
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

  const consumeStagedFile = async () => {
    const db = await new Promise((resolve, reject) => {
      const request = indexedDB.open('cap-mobile-entry', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('files', { keyPath: 'id' });
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const transaction = db.transaction('files', 'readwrite');
    const store = transaction.objectStore('files');
    const file = await new Promise((resolve, reject) => {
      const request = store.get('pending');
      request.onsuccess = () => {
        const record = request.result;
        store.delete('pending');
        resolve(record ? record.file : null);
      };
      request.onerror = () => reject(request.error);
    });
    await new Promise((resolve, reject) => {
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
    db.close();
    return file;
  };
  fileInput.addEventListener('change', () => setSelectedFile(fileInput.files[0]));

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
  const findPhones = (text) => [...new Set(
    [...text.matchAll(/(?:\+?504[\s-]?)?([23789]\d{3}[\s-]?\d{4})/g)]
      .map((match) => normalizeRtn(match[1]))
      .filter((phone) => phone.length === 8)
  )];

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

  const normalizeProviderName = (value) => String(value || '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ').trim();
  const showNewProviderFields = (show) => {
    const group = $('#newProviderFields');
    group.classList.toggle('d-none', !show);
    group.querySelectorAll('input, button').forEach((field) => { field.disabled = !show; });
  };
  const applyProviderMatch = (rtn, suggestedName = '') => {
    const clean = matchRtnField(rtn);
    $('#rtnEmisor').value = clean;
    const byRtn = clean ? context.providers.find((entry) => normalizeRtn(entry.rtn) === clean) : null;
    const targetName = normalizeProviderName(suggestedName);
    const byName = !byRtn && targetName.length >= 5
      ? context.providers.find((entry) => {
        const savedName = normalizeProviderName(entry.name);
        return (!clean || !entry.rtn || normalizeRtn(entry.rtn) === clean) && (savedName === targetName
          || (Math.min(savedName.length, targetName.length) >= 8 && (savedName.includes(targetName) || targetName.includes(savedName))));
      })
      : null;
    const provider = byRtn || byName;
    showNewProviderFields(!provider);
    if (provider) {
      $('#emisorNombre').value = provider.name;
      $('#providerMatchMessage').textContent = byRtn
        ? `Proveedor reconocido por RTN: ${provider.name}.`
        : `Posible proveedor existente: ${provider.name}. El servidor confirmará la coincidencia al guardar.`;
      $('#providerMatchMessage').className = byRtn ? 'form-text text-success' : 'form-text text-warning';
    } else {
      if (suggestedName) $('#emisorNombre').value = suggestedName.slice(0, 150);
      $('#providerMatchMessage').textContent = clean
        ? 'No hay coincidencia por RTN en la lista local; el servidor verificará de nuevo y creará la ficha si no existe.'
        : 'El servidor buscará el nombre al guardar y creará la ficha si es un proveedor nuevo.';
      $('#providerMatchMessage').className = 'form-text text-secondary';
    }
  };

  $('#emisorNombre').addEventListener('input', () => {
    const typedName = normalizeProviderName($('#emisorNombre').value);
    const exactMatch = context.providers.find((entry) => normalizeProviderName(entry.name) === typedName);
    showNewProviderFields(!exactMatch);
    $('#providerMatchMessage').textContent = exactMatch
      ? `Posible proveedor existente: ${exactMatch.name}. El servidor confirmará la coincidencia al guardar.`
      : 'El servidor buscará el nombre al guardar y creará la ficha si es un proveedor nuevo; se asignará “Sin clasificar”.';
    $('#providerMatchMessage').className = exactMatch ? 'form-text text-success' : 'form-text text-secondary';
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
      $('#providerMatchMessage').textContent = $('#emisorNombre').value.trim()
        ? 'El servidor buscará el nombre al guardar y creará la ficha si es un proveedor nuevo.'
        : 'El OCR buscará la ficha por RTN; si es un proveedor nuevo, la creará al guardar.';
      $('#providerMatchMessage').className = 'form-text text-secondary';
      return;
    }
    const match = context.providers.find((entry) => normalizeRtn(entry.rtn) === clean);
    if (match) applyProviderMatch(match.rtn, match.name);
    else applyProviderMatch(clean, $('#emisorNombre').value);
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
    const clientRtn = amazon ? null : (rtns.find((entry) => /cliente|comprador|recib[ií]\s+de|nombre:|sr\.?\s*\(?a\)?|direcci[oó]n\s*:/i.test(entry.context))
      || (rtns.length > 1 ? rtns[rtns.length - 1] : null));
    const supplierRtn = amazon ? null : (rtns.find((entry) => entry !== clientRtn) || (rtns.length === 1 ? rtns[0] : null));
    const caiLine = lines.find((line) => /C\.?\s*A\.?\s*[I1]\.?\s*:/i.test(line)) || '';
    const caiMatch = (caiLine || text).match(/C\.?\s*A\.?\s*[I1]\.?\s*:?\s*([A-Z0-9][A-Z0-9-]{7,})/i);
    const invoiceMatch = text.match(/(?:factura|recibo)\s*(?:n(?:[úuº°.]|um(?:ero)?)?\s*)?[#º°.:\-\s]*([0-9]{3,}(?:[-/][0-9A-Z]+){1,5}|[0-9]{5,})/i)
      || text.match(/\bN\s*[º°.]\s*([0-9]{3,}(?:[-/][0-9A-Z]+){1,5})/i);
    const invoiceNumberMatch = text.match(/(?:factura|recibo)[^\n]{0,20}?N\s*[º°o.]?\s*([0-9]{3}[-/]\d{3}[-/]\d{2}[-/]\d{5,})/i) || invoiceMatch;
    const dateLine = lines.find((line) => /fecha/i.test(line) && !/l[ií]mite|vencimiento|emisi[oó]n hasta/i.test(line))
      || lines.find((line) => /D[I1]A\s*M[E3]S\s*A[NÑ]O/i.test(line)) || '';
    const dateLineIndex = lines.indexOf(dateLine);
    const dateContext = dateLine && /D[I1]A\s*M[E3]S\s*A[NÑ]O/i.test(dateLine)
      ? lines.slice(dateLineIndex, dateLineIndex + 3).join(' ')
      : (dateLine || text);
    let dateMatch = dateContext.match(/\b(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})\b/);
    if (!dateMatch) {
      const boxedDate = dateContext.match(/D[I1]A\s*M[E3]S\s*A[NÑ]O[\s\S]{0,55}?(\d{1,2})\D{1,4}(\d{1,2})\D{1,4}(\d{2,4})/i);
      if (boxedDate) dateMatch = [boxedDate[0], boxedDate[1], boxedDate[2], boxedDate[3]];
    }
    if (!dateMatch) {
      const separatedDate = dateContext.match(/\b(\d{1,2})\s+(\d{1,2})\s+(\d{2,4})\b/);
      if (separatedDate) dateMatch = separatedDate;
    }
    let dateValue = '';
    if (dateMatch) {
      let year = Number(dateMatch[3]);
      if (year < 100) year += year < 50 ? 2000 : 1900;
      const month = String(Number(dateMatch[2])).padStart(2, '0');
      const day = String(Number(dateMatch[1])).padStart(2, '0');
      if (Number(month) <= 12 && Number(day) <= 31) dateValue = `${year}-${month}-${day}`;
    }

    const honorarios = /recibo\s+por\s+honorarios/i.test(lower);
    const invoiceSignals = /\bfactura\b|\bCAI\b|rango\s+autorizado|fecha\s+l[ií]mite/i.test(text);
    const receiptSignals = /\brecibo\b|comprobante\s+de\s+pago|voucher/i.test(text);
    let type = amazon ? 'amazon' : (honorarios ? 'recibo_honorarios' : (invoiceSignals ? 'factura' : (receiptSignals ? 'recibo' : 'factura')));
    const getLabeled = (patterns) => {
      const line = lines.find((item) => patterns.some((pattern) => pattern.test(item)));
      return line ? lastMoneyOnLine(line) : null;
    };
    const total = amountFromLabel(lines, [/total\s+a\s+pagar/i, /total\s+neto\s+recibido/i, /total\s+por\s+honorarios/i, /^total\b/i, /total\s+pagado/i]);
    let subtotal = amountFromLabel(lines, [/sub[- ]?total/i, /importe\s+gravado/i, /subtotal/i]);
    const isvLine = lines.find((line) => /\bI\.?\s*S\.?\s*V\.?(?=\s|$|\d)|impuesto\s+s(?:obre)?\s+ventas/i.test(line) && !/18\s*%/i.test(line));
    let isv = isvLine ? lastMoneyOnLine(isvLine) : null;

    const currency = /\bUSD\b|US\$|\$\s*\d/.test(text) ? 'USD' : (/\bEUR\b|€/.test(text) ? 'EUR' : 'HNL');
    const companyNameLine = lines.find((line) => /cliente\s*:|sr\.?\s*\(?a\)?\s*:/i.test(line)) || lines.find((line) => /recib[ií]\s+de/i.test(line)) || '';
    const supplierName = lines.slice(0, 8).find((line) => line.length > 4 && !/r\.?t\.?n|cai\s*[:.]|factura|recibo|tel[eé]fono|correo|e-mail|plaza|aldea|colonia|barrio|calle|avenida|\b(?:tel|cel)\.?\s*:/i.test(line)) || '';
    const email = (text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i) || [])[0] || '';
    const phones = findPhones(text);
    const addressStart = lines.findIndex((line) => /\b(?:aldea|colonia|barrio|calle|avenida|blvd\.?|boulevard|residencial|km\.?|carretera|localidad)\b/i.test(line));
    let address = '';
    if (addressStart >= 0) {
      const addressLines = [];
      for (const line of lines.slice(addressStart, addressStart + 4)) {
        if (/e-?mail|correo|\b(?:tel|cel)\.?\s*:?|r\.?t\.?n|\bcai\b|factura/i.test(line)) break;
        addressLines.push(line);
      }
      address = addressLines.join(', ').slice(0, 255);
    }
    const reference = amazon ? (text.match(/\b\d{3}-\d{7}-\d{7}\b/) || [])[0] : '';
    const items = [];
    const headerIndex = lines.findIndex((line, index) => /cant(?:idad)?/i.test(line)
      && (/descrip/i.test(line) || /descrip/i.test(lines[index + 1] || '') || /descrip/i.test(lines[index + 2] || '')));
    if (headerIndex >= 0) {
      let currentItem = null;
      for (const line of lines.slice(headerIndex + 1)) {
        if (/rango\s+autorizado|fecha\s+l[ií]mite|\bCAI\b|importe\s+(?:exonerado|exento|gravado)|total\s+a\s+pagar|cantidad\s+en\s+letras|la\s+factura\s+es/i.test(line)) break;
        const amountMatches = [...line.matchAll(/(?:L\.?\s*)?\d[\d,]*\.\d{2}/g)];
        const quantityMatch = line.match(/^\s*(\d{1,5}(?:[.,]\d{1,2})?)\s+(.+)$/);
        if (quantityMatch && amountMatches.length > 0 && items.length < 30) {
          const firstAmountIndex = amountMatches[0].index;
          const descriptionStart = quantityMatch[0].length - quantityMatch[2].length;
          let description = line.slice(descriptionStart, firstAmountIndex).trim();
          description = description.replace(/[|\s,;:-]+$/, '');
          if (description) {
            currentItem = { description, quantity: parseMoney(quantityMatch[1]), unitPrice: parseMoney(amountMatches[0][0]), discount: null };
            items.push(currentItem);
          }
        } else if (currentItem && line.trim()) {
          currentItem.description = `${currentItem.description} ${line.trim()}`.slice(0, 255);
        } else if (quantityMatch && items.length < 30) {
          currentItem = { description: quantityMatch[2].trim(), quantity: parseMoney(quantityMatch[1]), unitPrice: null, discount: null };
          items.push(currentItem);
        }
      }
    }
    return {
      type,
      rtnClient: clientRtn?.value || '',
      rtnSupplier: supplierRtn?.value || '',
      cai: caiMatch?.[1] || '',
      invoice: invoiceNumberMatch?.[1] || '',
      date: dateValue,
      supplierName,
      email,
      phones,
      address,
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
    const wrapper = document.createElement('tr');
    wrapper.className = 'item-row';
    wrapper.innerHTML = '<td><input class="form-control item-description" name="detalle_descripcion[]" maxlength="255" aria-label="Artículo o servicio"></td>'
      + '<td><input class="form-control" name="detalle_cantidad[]" type="number" min="0.01" step="0.01" aria-label="Cantidad (si aparece)"></td>'
      + '<td><input class="form-control" name="detalle_precio[]" type="number" min="0" step="0.01" aria-label="Precio unitario (si aparece)"></td>'
      + '<td><input class="form-control" name="detalle_descuento[]" type="number" min="0" step="0.01" aria-label="Descuento (si aparece)"></td>'
      + '<td><div class="input-group"><input class="form-control item-image-url" name="detalle_imagen_url[]" type="url" maxlength="255" placeholder="URL HTTPS" aria-label="Imagen de referencia"><button class="btn btn-outline-secondary find-product-image" type="button">Buscar</button></div></td>'
      + '<td><button class="btn btn-link text-danger btn-sm remove-item-row" type="button">Quitar</button></td>';
    wrapper.querySelector('[name="detalle_descripcion[]"]').value = item.description || '';
    if (wrapper.querySelector('[name="detalle_imagen_url[]"]')) wrapper.querySelector('[name="detalle_imagen_url[]"]').value = item.imageUrl || '';
    wrapper.querySelector('[name="detalle_cantidad[]"]').value = item.quantity ?? '';
    wrapper.querySelector('[name="detalle_precio[]"]').value = item.unitPrice ?? '';
    wrapper.querySelector('[name="detalle_descuento[]"]').value = item.discount ?? '';
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
            updateOcrProgress(((ocrCurrentPage - 1) + message.progress) / ocrPageCount);
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
    $('#ocrStatus').textContent = 'Conectando con el motor OCR…';
    updateOcrProgress(0);
    if (embeddedMode) showWorkflowModal(loadingModalElement);
    else loadingModal.show();
    analyzeButton.disabled = true;
    let worker;
    try {
      worker = await initializeWorker();
      await worker.setParameters({ tessedit_pageseg_mode: '6', preserve_interword_spaces: '1' });
      let pages = 1;
      if (validPdf) {
        const documentPdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        pages = Math.min(documentPdf.numPages, 5);
      }
      ocrPageCount = pages;
      const results = [];
      for (let page = 1; page <= pages; page += 1) {
        ocrCurrentPage = page;
        $('#ocrStatus').textContent = validPdf ? `Analizando página ${page} de ${pages}…` : 'Analizando imagen…';
        updateOcrProgress((page - 1) / pages);
        const canvas = await toCanvas(file, page);
      const result = await worker.recognize(canvas);
        results.push(result.data.text || '');
      }
      const raw = results.join('\n\n--- Página ---\n\n');
      fillForm(extractFields(raw));
      if (document.body.classList.contains('embedded-entry') && window.parent !== window) {
        window.parent.postMessage({ type: 'cap-entry-title', title: 'Revisar comprobante' }, window.location.origin);
      }
      const reviewNotice = $('#reviewNotice');
      const selectedDocumentType = $('#tipoDocumento').selectedOptions[0]?.textContent || 'comprobante';
      if (/\b(?:ISV|impuesto\s+s(?:obre)?\s+ventas|gravado)\s*18\s*%/i.test(raw)) {
        reviewNotice.textContent = `El comprobante parece ser: ${selectedDocumentType}. También parece mencionar ISV 18%; verifica ese dato, ya que el sistema registra ISV al 15%. Los campos detectados son sugerencias y puedes corregirlos.`;
        reviewNotice.className = 'alert alert-warning';
      } else {
        reviewNotice.textContent = `El comprobante parece ser: ${selectedDocumentType}. Los campos detectados son sugerencias; verifica y corrige los datos antes de guardar.`;
        reviewNotice.className = 'alert alert-info';
      }
      $('#reviewFields').dataset.ready = '1';
      $('#reviewFields').classList.remove('d-none');
      saveButton.classList.remove('d-none');
      $('#ocrStatus').textContent = 'Análisis terminado. Revisa los datos sugeridos.';
      updateOcrProgress(1);
      if (embeddedMode) {
        hideWorkflowModal(loadingModalElement);
        showWorkflowModal($('#reviewModal'));
      } else {
        loadingModalElement.addEventListener('hidden.bs.modal', () => {
          bootstrap.Modal.getOrCreateInstance($('#reviewModal')).show();
        }, { once: true });
        loadingModal.hide();
      }
    } catch (error) {
      if (embeddedMode) hideWorkflowModal(loadingModalElement);
      else loadingModal.hide();
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
    if (data.rtnSupplier || data.supplierName) applyProviderMatch(data.rtnSupplier, data.supplierName);
    if (data.email) $('#emailProveedor').value = data.email.slice(0, 120);
    if (data.phones?.length) $('#phone').value = data.phones.join(', ');
    if (data.address) $('#direccionProveedor').value = data.address.slice(0, 255);
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
    if (document.body.classList.contains('embedded-entry')) {
      const embeddedError = $('#embeddedOcrError');
      if (embeddedError) {
        embeddedError.textContent = `No se pudo procesar el comprobante: ${message} Cierra esta ventana y vuelve a seleccionar el archivo.`;
        embeddedError.classList.remove('d-none');
      }
      return;
    }
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

  setMode(context.initialMode === 'manual' ? 'manual' : 'ocr');
  if (new URLSearchParams(window.location.search).get('seleccion') === '1') {
    consumeStagedFile().then((file) => {
      if (!file) return;
      setSelectedFile(file);
      analyzeButton.click();
    }).catch(() => {});
  }
})();
