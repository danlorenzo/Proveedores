(() => {
    const csrfToken = document.body.dataset.csrfToken;
    if (!csrfToken) return;
    const renew = async () => {
        if (document.visibilityState !== 'visible') return;
        try {
            await fetch('../backend/controllers/renovar_sesion.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ csrf_token: csrfToken })
            });
        } catch (_) {
            // Se reintentará en el siguiente intervalo.
        }
    };
    window.setInterval(renew, 10 * 60 * 1000);
})();
