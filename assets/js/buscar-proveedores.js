(() => {
    const input = document.getElementById('q');
    const suggestions = document.getElementById('providerSuggestions');
    const cards = [...document.querySelectorAll('.provider-link[data-provider-name]')];
    const pagination = document.getElementById('providerPagination');
    if (!input || !suggestions) return;

    let timer = 0;
    let controller = null;
    const closeSuggestions = () => {
        suggestions.replaceChildren();
        suggestions.classList.add('d-none');
    };
    const showResults = (results, term) => {
        suggestions.replaceChildren();
        if (!results.length) {
            const empty = document.createElement('div');
            empty.className = 'px-3 py-2 small text-secondary';
            empty.textContent = 'No se encontraron coincidencias.';
            suggestions.append(empty);
            suggestions.classList.remove('d-none');
            return;
        }
        results.forEach((provider) => {
            const link = document.createElement('a');
            link.className = 'search-suggestion';
            link.href = 'proveedor.php?id=' + encodeURIComponent(provider.id_proveedor);
            link.setAttribute('role', 'option');
            const mark = document.createElement('span');
            mark.className = 'suggestion-mark';
            if (provider.logo_url) {
                const image = document.createElement('img');
                image.src = provider.logo_url;
                image.alt = '';
                mark.append(image);
            } else {
                mark.textContent = (provider.nombre_empresa || '?').trim().charAt(0).toLocaleUpperCase('es');
            }
            const description = document.createElement('span');
            const name = document.createElement('strong');
            name.className = 'd-block';
            name.textContent = provider.nombre_empresa;
            const rtn = document.createElement('small');
            rtn.className = 'text-secondary';
            rtn.textContent = 'RTN ' + provider.rtn;
            description.append(name, rtn);
            link.append(mark, description);
            suggestions.append(link);
        });
        suggestions.classList.remove('d-none');
    };

    input.addEventListener('input', () => {
        const term = input.value.trim();
        const normalized = term.toLocaleLowerCase('es');
        cards.forEach((card) => {
            const matches = !normalized || card.dataset.providerName.includes(normalized)
                || card.dataset.providerRtn.includes(normalized);
            card.closest('[class*="col-"]').classList.toggle('d-none', !matches);
        });
        if (pagination) pagination.classList.toggle('d-none', Boolean(normalized));
        if (!term) {
            if (controller) controller.abort();
            closeSuggestions();
            return;
        }
        window.clearTimeout(timer);
        if (controller) controller.abort();
        timer = window.setTimeout(async () => {
            controller = new AbortController();
            const url = new URL('../backend/controllers/buscar_proveedores.php', window.location.href);
            url.searchParams.set('q', term);
            const category = new URLSearchParams(window.location.search).get('categoria');
            if (category) url.searchParams.set('categoria', category);
            try {
                const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
                if (!response.ok) throw new Error('search failed');
                const data = await response.json();
                if (input.value.trim() === term) showResults(data.results || [], term);
            } catch (error) {
                if (error.name !== 'AbortError' && input.value.trim() === term) closeSuggestions();
            }
        }, 180);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeSuggestions();
        if (event.key === 'ArrowDown') suggestions.querySelector('a')?.focus();
    });
    document.addEventListener('click', (event) => {
        if (!suggestions.contains(event.target) && event.target !== input) closeSuggestions();
    });
    if (input.value.trim()) input.dispatchEvent(new Event('input'));
})();
