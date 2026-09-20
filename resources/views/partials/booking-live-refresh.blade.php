<script>
(() => {
    const form = document.getElementById('tracking-refresh-form');
    const label = document.getElementById('tracking-refresh-label');
    const button = document.getElementById('tracking-refresh-button');
    if (!form || !label || !button) return;

    const endpoint = @js(route('track.status'));
    const scrollKey = @js('tracking-scroll-'.$pickupRequest->reference_no);
    const version = @js($pickupRequest->trackingVersion());
    let timer = null;
    let checking = false;

    try {
        const savedScroll = sessionStorage.getItem(scrollKey);
        if (savedScroll !== null) {
            sessionStorage.removeItem(scrollKey);
            window.scrollTo(0, Number(savedScroll) || 0);
        }
    } catch (_) {}

    const schedule = () => {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(check, 5000);
    };

    async function check() {
        if (checking || document.hidden) return;
        checking = true;
        button.disabled = true;
        label.textContent = 'Checking your laundry status...';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 419) {
                const tokenResponse = await fetch(@js(route('csrf.token')), { cache: 'no-store' });
                if (tokenResponse.ok) form.elements['_token'].value = (await tokenResponse.json()).token;
                throw new Error('Session refreshed; checking again soon.');
            }
            if (!response.ok) throw new Error('Could not check status. Retrying soon.');

            const result = await response.json();
            if (result.version !== version) {
                try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (_) {}
                label.textContent = 'New update found. Refreshing...';
                window.location.reload();
                return;
            }
            label.textContent = 'Up to date · checked just now';
        } catch (error) {
            label.textContent = error.message || 'Could not check status. Retrying soon.';
        } finally {
            checking = false;
            button.disabled = false;
            schedule();
        }
    }

    button.addEventListener('click', check);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else check();
    });
    window.addEventListener('online', check);
    schedule();
})();
</script>
