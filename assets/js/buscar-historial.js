(() => {
    const form = document.getElementById('historyLiveSearch');
    const input = form?.querySelector('input[name="q"]');
    const suggestions = document.getElementById('historySuggestions');
    if (!form || !input || !suggestions) return;

    const mode = form.dataset.mode;
    const endpoint = form.dataset.endpoint;
    let clearServerQuery = new URLSearchParams(window.location.search).has('q');
    const cards = [...document.querySelectorAll('[data-live-search-item]')];
    const pagination = document.getElementById('historyPagination') || document.getElementById('purchasePagination');
    let timer = 0;
    let controller;
    const close = () => { suggestions.replaceChildren(); suggestions.classList.add('d-none'); };

    const render = (results) => {
        suggestions.replaceChildren();
        if (!results.length) {
            const empty = document.createElement('div');
            empty.className = 'px-3 py-2 small text-secondary';
            empty.textContent = 'No se encontraron coincidencias.';
            suggestions.append(empty);
        } else {
            results.forEach((item) => {
                const option = mode === 'perfil' ? document.createElement('a') : document.createElement('button');
                option.className = 'search-suggestion';
                option.setAttribute('role', 'option');
                if (mode === 'perfil') option.href = item.url;
                else option.type = 'button';
                const mark = document.createElement('span');
                mark.className = 'suggestion-mark';
                mark.textContent = (item.label || '?').trim().charAt(0).toLocaleUpperCase('es');
                const text = document.createElement('span');
                const label = document.createElement('strong');
                label.className = 'd-block';
                label.textContent = item.label || '';
                const detail = document.createElement('small');
                detail.className = 'text-secondary';
                detail.textContent = item.detail || '';
                text.append(label, detail);
                option.append(mark, text);
                if (mode === 'compras') option.addEventListener('click', () => {
                    input.value = item.label;
                    close();
                    form.requestSubmit();
                });
                suggestions.append(option);
            });
        }
        suggestions.classList.remove('d-none');
    };

    input.addEventListener('input', () => {
        const term = input.value.trim();
        const normalized = term.toLocaleLowerCase('es');
        cards.forEach((card) => card.classList.toggle('d-none', Boolean(normalized) && !card.dataset.liveSearchItem.includes(normalized)));
        if (pagination) pagination.classList.toggle('d-none', Boolean(normalized));
        if (!term) {
            clearTimeout(timer);
            controller?.abort();
            close();
            if (clearServerQuery) {
                clearServerQuery = false;
                form.requestSubmit();
            }
            return;
        }
        clearTimeout(timer);
        controller?.abort();
        timer = window.setTimeout(async () => {
            controller = new AbortController();
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('q', term);
            if (form.dataset.providerId) url.searchParams.set('id', form.dataset.providerId);
            try {
                const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
                if (!response.ok) throw new Error('search failed');
                const data = await response.json();
                if (input.value.trim() === term) render(data.results || []);
            } catch (error) {
                if (error.name !== 'AbortError' && input.value.trim() === term) close();
            }
        }, 180);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowDown') suggestions.querySelector('a,button')?.focus();
    });
    document.addEventListener('click', (event) => {
        if (!suggestions.contains(event.target) && event.target !== input) close();
    });
    if (input.value.trim()) input.dispatchEvent(new Event('input'));
})();
