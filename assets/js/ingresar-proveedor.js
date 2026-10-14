(() => {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const mode = window.CAP_PROVIDER_MODE === 'manual' ? 'manual' : 'ocr';
  $('#entryMethod').value = mode;
  $('#ocrBox').classList.toggle('d-none', mode !== 'ocr');
  $('#providerRtn').addEventListener('input', (event) => { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 14); });
  $('#providerPhone').addEventListener('input', (event) => { event.target.value = event.target.value.replace(/[^\d+(),;\/|\s-]/g, '').slice(0, 64); });
  $('#readScan').addEventListener('click', async () => {
    const file = $('#scanFile').files[0];
    if (!file) { $('#ocrStatus').textContent = 'Elige primero una imagen o PDF.'; return; }
    if (file.size > 15 * 1024 * 1024) { $('#ocrStatus').textContent = 'El archivo no puede superar 15 MB.'; return; }
    if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) { $('#ocrStatus').textContent = 'Formato no permitido. Usa JPG, PNG, WEBP o PDF.'; return; }
    try {
      $('#readScan').disabled = true;
      $('#ocrStatus').textContent = 'Preparando OCR…';
      let source = file;
      if (file.type === 'application/pdf') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        const page = await pdf.getPage(1); const viewport = page.getViewport({ scale: 1.7 });
        const canvas = document.createElement('canvas'); canvas.width = viewport.width; canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise; source = canvas;
      }
      const result = await Tesseract.recognize(source, 'spa', {
        workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.0.4/dist/worker.min.js',
        corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5.0.0',
        langPath: 'https://cdn.jsdelivr.net/npm/@tesseract.js-data/spa@1.0.0/4.0.0_best_int',
        logger: (m) => { if (m.status) $('#ocrStatus').textContent = m.status === 'recognizing text' ? `Leyendo documento: ${Math.round((m.progress || 0) * 100)}%` : 'Cargando lector de español…'; },
      });
      const text = result.data.text || '';
      const rtn = text.match(/(?:\d[\d\s-]{12,18}\d)/g)?.map((v) => v.replace(/\D/g, '')).find((v) => v.length === 14);
      const phones = [...new Set([...text.matchAll(/(?:\+?504[\s-]?)?([23789]\d{3}[\s-]?\d{4})/g)].map((match) => match[1].replace(/\D/g, '')).filter((phone) => phone.length === 8))];
      const email = text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i)?.[0];
      const firstLine = text.split(/\r?\n/).map((v) => v.trim()).find((v) => v.length > 3 && !/rtn|cai|factura|recibo|tel[eé]fono/i.test(v));
      const address = text.split(/\r?\n/).map((v) => v.trim()).find((v) => /\b(?:plaza|aldea|colonia|barrio|calle|avenida|blvd\.?|boulevard|residencial|carretera)\b/i.test(v));
      if (rtn) $('#providerRtn').value = rtn;
      if (phones.length) $('#providerPhone').value = phones.join(', ');
      if (email) $('#providerEmail').value = email;
      if (firstLine && !$('#providerName').value) $('#providerName').value = firstLine.slice(0, 150);
      if (address) $('#providerAddress').value = address.slice(0, 255);
      $('#ocrStatus').textContent = 'Lectura terminada. Revisa y completa la ficha manualmente; OCR no encontró algunos campos.';
      $('#providerForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) { $('#ocrStatus').textContent = `No se pudo leer el archivo: ${error.message || 'revisa tu conexión e inténtalo otra vez.'}`; }
    finally { $('#readScan').disabled = false; }
  });
  $('#providerForm').addEventListener('submit', (event) => { if (mode === 'manual') $('#entryMethod').value = 'manual'; });
})();
