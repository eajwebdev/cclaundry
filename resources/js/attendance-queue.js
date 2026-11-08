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
    async all() { return (await this.write('list')).filter(e => e.employee_id === this.employeeId).sort((a, b) => a.captured_at.localeCompare(b.captured_at)); },
    async pending() { return (await this.all()).filter(e => !e.rejected); },
    async discardRejected() { const rejected = (await this.all()).find(e => e.rejected); if (rejected) await this.write('delete', rejected.event_id); },
    async status(extra = {}) {
        const all = await this.all();
        const rejected = all.filter(e => e.rejected);
        return {
            pending: all.length - rejected.length,
            rejected: rejected.length > 0,
            message: rejected.length ? `${rejected.length} punch(es) were not accepted: ${rejected[0].rejected} Ask a manager to record a correction.` : '',
            ...extra,
        };
    },
    async flush() {
        if (this.syncing) return await this.status();
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
                } catch { return await this.status({ offline: true }); }
                const data = await response.json().catch(() => ({}));
                const reason = Object.values(data.errors || {})[0]?.[0] || data.message;
                // A punch the server refuses is set aside, so it never blocks the punches after it.
                if (response.status === 422 || response.status === 403) {
                    await this.write('put', { ...event, rejected: reason || 'Rejected by the server.' });
                    continue;
                }
                if (!response.ok) return await this.status({ message: reason || 'Sign in again to sync pending punches.' });
                await this.write('delete', event.event_id);
                last = data;
            }
            return await this.status({ data: last });
        } finally { this.syncing = false; }
    },
});
