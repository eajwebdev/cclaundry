window.staffScheduling = (cells, templates) => ({
    cells, templates, selected: [], editing: [], multi: false, pickedTemplate: null,
    editorOpen: false, settingsOpen: false, publishOpen: false,
    clipboard: null, pasteMode: false, holdTimer: null, held: false,
    form: { template: 'opening', ...templates.opening, note: '' },
    cellClick(cell) {
        if (this.held) { this.held = false; return; }
        if (this.multi) {
            this.selected = this.selected.includes(cell) ? this.selected.filter(c => c !== cell) : [...this.selected, cell];
            return;
        }
        this.editing = [cell];
        this.form = { ...(this.cells[cell] || { template: 'opening', ...this.templates.opening, note: '' }) };
        if (this.pasteMode && this.clipboard) this.form = { ...this.clipboard };
        else if (this.pickedTemplate) this.applyTemplate(this.pickedTemplate);
        this.editorOpen = true;
    },
    applyTemplate(key) {
        this.form = { template: key, ...this.templates[key], note: this.form.note || '' };
    },
    chooseTemplate(key) {
        this.pickedTemplate = key;
        this.pasteMode = false;
        if (this.selected.length) { this.editing = [...this.selected]; this.applyTemplate(key); this.editorOpen = true; }
    },
    editSelected() {
        this.editing = [...this.selected];
        this.form = { ...(this.cells[this.selected[0]] || { template: 'opening', ...this.templates.opening, note: '' }) };
        this.editorOpen = true;
    },
    dropTemplate(event, cell) {
        const key = event.dataTransfer.getData('text/plain');
        if (!this.templates[key]) return;
        this.editing = [cell]; this.applyTemplate(key); this.editorOpen = true;
    },
    copyCurrent() { this.clipboard = { ...this.form }; this.editorOpen = false; this.pasteMode = true; },
    hold(cell) {
        this.held = false;
        this.holdTimer = window.setTimeout(() => {
            if (this.cells[cell]) { this.clipboard = { ...this.cells[cell] }; this.pasteMode = true; this.held = true; }
        }, 650);
    },
    releaseHold() { window.clearTimeout(this.holdTimer); },
    resize(event, cell, field) {
        const timeline = event.target.closest('[data-timeline]');
        const bounds = timeline.getBoundingClientRect();
        const data = { ...this.cells[cell] };
        let minute = null;
        const move = e => {
            const ratio = Math.min(1, Math.max(0, (e.clientX - bounds.left) / bounds.width));
            minute = Math.round((Number(timeline.dataset.opening) + ratio * Number(timeline.dataset.span)) / 15) * 15;
        };
        const up = e => {
            move(e);
            window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
            this.editing = [cell]; this.form = data;
            this.form[field] = `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
            this.editorOpen = true;
        };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up, { once: true });
    },
});
