// Photos stay in this device's IndexedDB until this employee's session syncs them.
window.attendancePunchQueue = (employeeId, url) => ({
    employeeId, url, syncing: false,
    async db() {
        return await new Promise((resolve, reject) => {
            const request = indexedDB.open('cclaundry-attendance', 1);
            request.onupgradeneeded = () => request.result.createObjectStore('punches', { keyPath: 'event_id' });
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    },
    async write(action, data) {
        const db = await this.db();
        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction('punches', action === 'list' ? 'readonly' : 'readwrite');
                const store = tx.objectStore('punches');
                const request = action === 'list' ? store.getAll() : action === 'put' ? store.put(data) : store.delete(data);
                let result;
                request.onsuccess = () => { result = request.result; };
                tx.oncomplete = () => resolve(result);
                tx.onerror = () => reject(tx.error);
                tx.onabort = () => reject(tx.error);
            });
        } finally { db.close(); }
    },
    async enqueue(payload) {
        const event = { ...payload, employee_id: this.employeeId, event_id: crypto.randomUUID(), captured_at: new Date().toISOString() };
        await this.write('put', event);
        return event;
    },
    async pending() { return (await this.write('list')).filter(e => e.employee_id === this.employeeId).sort((a, b) => a.captured_at.localeCompare(b.captured_at)); },
    async discardRejected() { const pending = await this.pending(); if (pending.length) await this.write('delete', pending[0].event_id); },
    async flush() {
        if (this.syncing) return { pending: (await this.pending()).length };
        this.syncing = true;
        let last = null;
        try {
            const events = await this.pending();
            for (const event of events) {
                let response;
                try {
                    response = await fetch(this.url, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify(event), signal: AbortSignal.timeout(15000),
                    });
                } catch { return { pending: (await this.pending()).length, offline: true }; }
                const data = await response.json().catch(() => ({}));
                if (!response.ok) return { rejected: response.status === 422 || response.status === 403, pending: (await this.pending()).length, message: Object.values(data.errors || {})[0]?.[0] || data.message || 'Sign in again to sync pending punches.' };
                await this.write('delete', event.event_id);
                last = data;
            }
            return { pending: 0, data: last };
        } finally { this.syncing = false; }
    },
});
