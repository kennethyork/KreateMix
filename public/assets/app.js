/* MixCreate — vanilla JS, no build step. */
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const base = ($('meta[name="base-url"]') || {}).content || '';
    const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const iconUrl = (() => { const u = $('use[href*="icons.svg"]'); return u ? u.getAttribute('href').split('#')[0] : base + '/assets/icons.svg'; })();
    const icon = (name) => `<svg class="icon" aria-hidden="true"><use href="${iconUrl}#${name}"></use></svg>`;

    function toast(msg, type) {
        const root = $('#toasts');
        if (!root) return alert(msg);
        const el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' error' : '');
        el.textContent = msg;
        root.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    async function api(path, opts = {}) {
        const headers = Object.assign({ 'X-CSRF-Token': csrf(), 'X-Requested-With': 'fetch', Accept: 'application/json' }, opts.headers || {});
        let body = opts.body;
        if (body && !(body instanceof FormData) && typeof body !== 'string') {
            body = JSON.stringify(body);
            headers['Content-Type'] = 'application/json';
        }
        const res = await fetch(base + path, { method: opts.method || 'GET', headers, body, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'Request failed (' + res.status + ')');
        return data;
    }

    /** Upload with progress, resolves with the media object. */
    function upload(file, onProgress) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('_token', csrf());
            const xhr = new XMLHttpRequest();
            xhr.open('POST', base + '/media');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'fetch');
            xhr.upload.onprogress = (e) => e.lengthComputable && onProgress && onProgress(e.loaded / e.total);
            xhr.onload = () => {
                let data = {};
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
                xhr.status >= 200 && xhr.status < 300 ? resolve(data.media) : reject(new Error(data.error || 'Upload failed (' + xhr.status + ')'));
            };
            xhr.onerror = () => reject(new Error('Upload failed. Check your connection.'));
            xhr.send(fd);
        });
    }

    function progressRow(container, name) {
        const row = document.createElement('div');
        row.className = 'progress-row';
        row.innerHTML = `<span>${esc(name)}</span><div class="progress-bar"><i></i></div>`;
        container.appendChild(row);
        return { set: (p) => { row.querySelector('i').style.width = Math.round(p * 100) + '%'; }, done: () => row.remove() };
    }

    function wireDropzone(zone, input, onFiles) {
        input.addEventListener('change', () => { onFiles(Array.from(input.files)); input.value = ''; });
        ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('over'); }));
        ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('over'); }));
        zone.addEventListener('drop', (e) => onFiles(Array.from(e.dataTransfer.files)));
    }

    /* ---------- Global UI ---------- */
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-toggle-sidebar]');
        if (t) document.body.classList.toggle('sidebar-open');

        const fc = e.target.closest('.flash-close');
        if (fc) fc.closest('.flash').remove();

        const copy = e.target.closest('[data-copy]');
        if (copy) {
            navigator.clipboard?.writeText(copy.textContent.trim()).then(() => toast('Copied'), () => {
                const r = document.createRange(); r.selectNodeContents(copy);
                const s = getSelection(); s.removeAllRanges(); s.addRange(r);
            });
        }

        const theme = e.target.closest('[data-theme-toggle]');
        if (theme) {
            const dark = document.documentElement.dataset.theme
                ? document.documentElement.dataset.theme === 'dark'
                : matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.dataset.theme = dark ? 'light' : 'dark';
            try { localStorage.setItem('mc-theme', document.documentElement.dataset.theme); } catch (err) { /* private mode */ }
        }

        const open = e.target.closest('[data-open-dialog]');
        if (open) $('#' + open.dataset.openDialog)?.showModal();
        const close = e.target.closest('[data-close-dialog]');
        if (close) close.closest('dialog')?.close();

        // Close open dropdowns/workspace menu when clicking elsewhere.
        $$('details.dropdown[open], details.ws-switch[open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; });
    });

    // Confirm before destructive submits. Works for forms and for individual submit buttons.
    document.addEventListener('submit', (e) => {
        const form = e.target;
        const btn = e.submitter;
        const msg = (btn && btn.dataset.confirm) || form.dataset.confirm;
        if (msg && !confirm(msg)) { e.preventDefault(); return; }
        if (btn && btn.hasAttribute('data-needs-date')) {
            const d = form.querySelector('[name="date"]');
            if (d && !d.value) { e.preventDefault(); toast('Pick a date to schedule.', 'error'); d.focus(); return; }
        }
        // Prevent double submits.
        setTimeout(() => $$('button[type="submit"]', form).forEach((b) => { b.disabled = true; }), 0);
    }, true);
    // A form whose submit is triggered by a button belonging to another form via form="" still fires on that form.

    $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

    $$('.flash-success').forEach((f) => setTimeout(() => f.remove(), 5000));

    const tzSelect = $('[data-detect-timezone]');
    if (tzSelect) {
        try {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (tz && $$('option', tzSelect).some((o) => o.value === tz) && tzSelect.value === 'UTC') tzSelect.value = tz;
        } catch (e) { /* ignore */ }
    }

    // Hash tabs (Library page).
    const tabs = $('[data-tabs]');
    if (tabs) {
        const links = $$('a', tabs);
        const show = () => {
            const id = (location.hash || links[0].getAttribute('href')).slice(1);
            const target = document.getElementById(id) ? id : links[0].getAttribute('href').slice(1);
            $$('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === target));
            links.forEach((l) => l.classList.toggle('active', l.getAttribute('href') === '#' + target));
        };
        document.body.setAttribute('data-tabs-ready', '');
        window.addEventListener('hashchange', show);
        show();
        window.scrollTo(0, 0);
    }

    /* ---------- Media page ---------- */
    const mediaPage = $('[data-media-page]');
    if (mediaPage) {
        const zone = $('.dropzone', mediaPage);
        const input = $('input[type=file]', mediaPage);
        const prog = $('.upload-progress', mediaPage);
        wireDropzone(zone, input, async (files) => {
            let ok = 0;
            for (const f of files) {
                const p = progressRow(prog, f.name);
                try { await upload(f, p.set); ok++; } catch (err) { toast(f.name + ': ' + err.message, 'error'); }
                p.done();
            }
            if (ok) location.reload();
        });
    }

    /* ---------- Charts (inline SVG) ---------- */
    $$('[data-chart]').forEach((el) => {
        const series = JSON.parse(el.dataset.series || '[]');
        const type = el.dataset.chart;
        const pts = series.filter((s) => s.value !== null);
        if (!pts.length || (type === 'line' && pts.length < 2) || pts.every((p) => p.value === 0 && type === 'bar')) {
            el.innerHTML = '<div class="chart-empty">No data yet</div>';
            return;
        }
        const W = el.clientWidth || 500, H = el.clientHeight || 220, padL = 40, padB = 22, padT = 8, padR = 6;
        const vals = pts.map((p) => p.value);
        let min = type === 'bar' ? 0 : Math.min(...vals), max = Math.max(...vals);
        if (min === max) { max += 1; if (type !== 'bar') min = Math.max(0, min - 1); }
        const span = max - min; min = type === 'bar' ? 0 : Math.max(0, Math.floor(min - span * 0.1)); max = Math.ceil(max + span * 0.1);
        const n = series.length;
        const x = (i) => padL + (n === 1 ? (W - padL - padR) / 2 : i * (W - padL - padR) / (n - 1));
        const y = (v) => padT + (H - padT - padB) * (1 - (v - min) / (max - min || 1));
        const fmt = (v) => v >= 10000 ? (v / 1000).toFixed(1) + 'k' : Math.round(v).toLocaleString();
        let svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(el.dataset.label)} chart"><g class="grid">`;
        const ticks = 4;
        for (let t = 0; t <= ticks; t++) {
            const v = min + (max - min) * t / ticks;
            svg += `<line x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/>`;
        }
        svg += '</g><g class="axis">';
        for (let t = 0; t <= ticks; t++) {
            const v = min + (max - min) * t / ticks;
            svg += `<text x="${padL - 6}" y="${y(v) + 4}" text-anchor="end">${fmt(v)}</text>`;
        }
        const every = Math.max(1, Math.ceil(n / 6));
        series.forEach((s, i) => {
            if (i % every === 0 || i === n - 1) {
                const d = new Date(s.date + 'T00:00:00');
                svg += `<text x="${x(i)}" y="${H - 4}" text-anchor="middle">${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</text>`;
            }
        });
        svg += '</g>';
        if (type === 'bar') {
            const bw = Math.max(2, (W - padL - padR) / n * 0.6);
            series.forEach((s, i) => {
                const v = s.value || 0;
                svg += `<rect class="bar" x="${x(i) - bw / 2}" y="${y(v)}" width="${bw}" height="${Math.max(0, y(min) - y(v))}" rx="2" data-i="${i}"/>`;
            });
        } else {
            let d = '', first = null, last = null;
            series.forEach((s, i) => {
                if (s.value === null) return;
                d += (d ? 'L' : 'M') + x(i) + ' ' + y(s.value);
                if (first === null) first = i;
                last = i;
            });
            svg += `<path class="area" d="${d}L${x(last)} ${y(min)}L${x(first)} ${y(min)}Z"/><path class="line" d="${d}"/>`;
        }
        svg += '</svg><div class="chart-tip" hidden></div>';
        el.innerHTML = svg;
        const tip = $('.chart-tip', el);
        const svgEl = $('svg', el);
        svgEl.addEventListener('mousemove', (e) => {
            const r = svgEl.getBoundingClientRect();
            const px = (e.clientX - r.left) * (W / r.width);
            const i = Math.max(0, Math.min(n - 1, Math.round((px - padL) / ((W - padL - padR) / Math.max(1, n - 1)))));
            const s = series[i];
            if (!s || s.value === null) { tip.hidden = true; return; }
            tip.hidden = false;
            tip.textContent = new Date(s.date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ': ' + Number(s.value).toLocaleString();
            tip.style.left = (x(i) * r.width / W) + 'px';
            tip.style.top = (y(s.value || 0) * r.height / H) + 'px';
        });
        svgEl.addEventListener('mouseleave', () => { tip.hidden = true; });
    });

    /* ---------- Composer ---------- */
    const form = $('[data-composer]');
    if (form) composer(form);

    function composer(form) {
        const cfg = JSON.parse($('#composer-data').textContent);
        const accounts = new Map(cfg.accounts.map((a) => [a.id, a]));
        const content = $('#content');
        const strip = $('#media-strip');
        const counters = $('#counters');
        const previews = $('#previews');
        let media = cfg.media.slice();

        const selected = () => $$('input[name="accounts[]"]:checked', form).map((i) => accounts.get(Number(i.value))).filter(Boolean);
        const overrideFor = (id) => { const t = form.querySelector(`textarea[name="overrides[${id}]"]`); return t ? t.value.trim() : ''; };
        const applyVars = (text) => text.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (m, k) => (k in cfg.variables ? cfg.variables[k] : m));
        const countFor = (text, provider) => {
            text = applyVars(text);
            if (provider === 'x') text = text.replace(/https?:\/\/\S+/g, 'x'.repeat(23));
            return Array.from(text).length;
        };

        function renderMedia() {
            strip.innerHTML = '';
            media.forEach((m, i) => {
                const el = document.createElement('div');
                el.className = 'media-item';
                el.draggable = !cfg.readonly;
                el.dataset.index = i;
                const isVideo = m.mime.startsWith('video/');
                el.innerHTML = (isVideo ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video><span class="play">${icon('play')}</span>` : `<img src="${esc(m.url)}" alt="">`)
                    + `<input type="hidden" name="media[]" value="${m.id}">`
                    + (cfg.readonly ? '' : `<button type="button" class="remove" aria-label="Remove">${icon('x')}</button>`);
                strip.appendChild(el);
            });
            update();
        }

        strip.addEventListener('click', (e) => {
            const rm = e.target.closest('.remove');
            if (!rm) return;
            media.splice(Number(rm.closest('.media-item').dataset.index), 1);
            renderMedia();
        });

        // Drag to reorder media.
        let dragFrom = null;
        strip.addEventListener('dragstart', (e) => { const it = e.target.closest('.media-item'); if (!it) return; dragFrom = Number(it.dataset.index); it.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        strip.addEventListener('dragend', () => { dragFrom = null; renderMedia(); });
        strip.addEventListener('dragover', (e) => {
            e.preventDefault();
            const it = e.target.closest('.media-item');
            if (dragFrom === null || !it) return;
            const to = Number(it.dataset.index);
            if (to === dragFrom) return;
            const [m] = media.splice(dragFrom, 1);
            media.splice(to, 0, m);
            dragFrom = to;
            $$('.media-item', strip).forEach((el, i) => { el.dataset.index = i; });
            renderMediaKeepDrag();
        });
        function renderMediaKeepDrag() {
            const items = $$('.media-item', strip);
            media.forEach((m, i) => {
                const el = items.find((x) => x.querySelector('input').value == m.id);
                if (el) { strip.appendChild(el); el.dataset.index = i; el.classList.toggle('dragging', i === dragFrom); }
            });
        }

        function linkify(text) {
            return esc(text)
                .replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (m, k) => `<span class="var">${k in cfg.variables ? esc(cfg.variables[k]) : m}</span>`)
                .replace(/(https?:\/\/[^\s<]+)/g, '<span class="hl">$1</span>')
                .replace(/(^|\s)([#@][\w.]+)/gu, '$1<span class="hl">$2</span>');
        }

        function update() {
            const accts = selected();
            const main = content.value;

            // Show override fields only for selected accounts.
            $$('[data-override-for]', form).forEach((el) => {
                const id = Number(el.dataset.overrideFor);
                const on = accts.some((a) => a.id === id);
                el.hidden = !on;
                const a = accounts.get(id);
                const ta = el.querySelector('textarea');
                if (!cfg.readonly) ta.placeholder = main;
                const p = cfg.providers[a.provider];
                const counter = el.querySelector('[data-override-count]');
                if (ta.value.trim() && p) {
                    const n = countFor(ta.value, a.provider);
                    counter.textContent = n + '/' + p.limit;
                    counter.classList.toggle('over', n > p.limit);
                } else counter.textContent = '';
            });

            // Character counters, one per platform in use.
            if (counters) {
                const seen = new Set();
                let html = '';
                accts.forEach((a) => {
                    if (seen.has(a.provider) || overrideFor(a.id)) return;
                    seen.add(a.provider);
                    const p = cfg.providers[a.provider];
                    if (!p) return;
                    const n = countFor(main, a.provider);
                    const left = p.limit - n;
                    html += `<span class="counter${left < 0 ? ' over' : ''}" title="${esc(p.name)}: ${n} of ${p.limit}"><span class="pdot" style="--pc:${p.color}">${esc(abbr(a.provider))}</span>${left}</span>`;
                });
                counters.innerHTML = html;
            }

            // Previews.
            if (!accts.length) {
                previews.innerHTML = '<p class="muted small">Select accounts to see how the post will look.</p>';
                return;
            }
            const videos = media.filter((m) => m.mime.startsWith('video/')).length;
            previews.innerHTML = accts.map((a) => {
                const p = cfg.providers[a.provider] || { name: a.provider, limit: 99999, rules: { max_media: 4 } };
                const text = overrideFor(a.id) || main;
                const n = countFor(text, a.provider);
                const shown = media.slice(0, p.rules.max_media);
                const warns = [];
                if (n > p.limit) warns.push(`${n - p.limit} characters over the ${p.limit} limit`);
                if (p.rules.requires_video && !videos) warns.push(p.name + ' needs a video');
                else if (p.rules.requires_media && !media.length) warns.push(p.name + ' needs an image or video');
                if (media.length > p.rules.max_media) warns.push(`Only the first ${p.rules.max_media} media file${p.rules.max_media > 1 ? 's' : ''} allowed`);
                if (!a.authorized) warns.push('This account needs reconnecting');
                const av = a.avatar ? `<img src="${esc(a.avatar)}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">` : '';
                return `<article class="preview">
                    <div class="preview-top">
                        <span class="acct-avatar"><span class="avatar sm">${esc(initials(a.name))}${av}</span><span class="pbadge" style="--pc:${p.color}">${esc(abbr(a.provider))}</span></span>
                        <div><div class="preview-name">${esc(a.name)}</div><div class="preview-handle">${a.username ? '@' + esc(a.username) + ' · ' : ''}${esc(p.name)}</div></div>
                    </div>
                    <div class="preview-text">${text.trim() ? linkify(text) : '<span class="muted">No text</span>'}</div>
                    ${shown.length ? `<div class="preview-media n${Math.min(shown.length, 4)}">${shown.slice(0, 4).map((m) => m.mime.startsWith('video/') ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video>` : `<img src="${esc(m.url)}" alt="">`).join('')}</div>` : ''}
                    <div class="preview-actions">${icon('reply')}${icon('repost')}${icon('heart')}</div>
                    ${warns.map((w) => `<div class="preview-warn">${icon('alert')}<span>${esc(w)}</span></div>`).join('')}
                </article>`;
            }).join('');
        }

        function initials(name) {
            return String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
        }
        function abbr(key) {
            return { x: 'X', mastodon: 'M', bluesky: 'B', facebook: 'f', instagram: 'IG', linkedin: 'in', youtube: 'YT', threads: 'Th', tiktok: 'TT', pinterest: 'P', google_business: 'G', sandbox: 'S' }[key] || key[0].toUpperCase();
        }

        form.addEventListener('input', update);
        form.addEventListener('change', update);

        $('[data-select-all-accounts]', form)?.addEventListener('click', () => {
            const boxes = $$('input[name="accounts[]"]', form);
            const all = boxes.every((b) => b.checked);
            boxes.forEach((b) => { b.checked = !all; });
            update();
        });

        // Insert text at the cursor (hashtag groups, variables).
        function insertAtCursor(text) {
            const s = content.selectionStart ?? content.value.length, e = content.selectionEnd ?? s;
            content.value = content.value.slice(0, s) + text + content.value.slice(e);
            content.focus();
            content.selectionStart = content.selectionEnd = s + text.length;
            update();
        }
        $$('[data-insert]', form).forEach((b) => b.addEventListener('click', () => { insertAtCursor(b.dataset.insert); b.closest('details').open = false; }));
        $$('[data-template]', form).forEach((b) => b.addEventListener('click', () => {
            if (content.value.trim() && !confirm('Replace the current text with this template?')) return;
            content.value = b.dataset.template;
            b.closest('details').open = false;
            update();
        }));

        $('[data-save-template]', form)?.addEventListener('click', async () => {
            if (!content.value.trim()) return toast('Write something first.', 'error');
            const name = prompt('Template name');
            if (!name) return;
            try {
                await api('/library/templates', { method: 'POST', body: { name, content: content.value, media: media.map((m) => m.id), _token: csrf() } });
                toast('Template saved');
            } catch (err) { toast(err.message, 'error'); }
        });

        $('[data-new-tag]', form)?.addEventListener('click', async (e) => {
            const name = prompt('New tag name');
            if (!name) return;
            const colors = ['#4f46e5', '#0891b2', '#059669', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed'];
            try {
                const { tag } = await api('/tags', { method: 'POST', body: { name, color: colors[Math.floor(Math.random() * colors.length)], _token: csrf() } });
                if (form.querySelector(`input[name="tags[]"][value="${tag.id}"]`)) { form.querySelector(`input[name="tags[]"][value="${tag.id}"]`).checked = true; return; }
                const label = document.createElement('label');
                label.className = 'tag-chip';
                label.style.setProperty('--tc', tag.color);
                label.innerHTML = `<input type="checkbox" name="tags[]" value="${tag.id}" checked><span>${esc(tag.name)}</span>`;
                e.target.closest('[data-new-tag]').before(label);
            } catch (err) { toast(err.message, 'error'); }
        });

        // Media picker dialog.
        const dlg = $('#media-dialog');
        let picked = [];
        let library = [];
        async function loadLibrary() {
            const grid = $('#media-library');
            try {
                const data = await api('/media');
                library = data.media;
                renderLibrary();
            } catch (err) { grid.innerHTML = `<p class="error-text">${esc(err.message)}</p>`; }
        }
        function renderLibrary() {
            const grid = $('#media-library');
            if (!library.length) { grid.innerHTML = '<p class="muted">No media yet. Upload some above.</p>'; return; }
            grid.innerHTML = library.map((m) => {
                const idx = picked.indexOf(m.id);
                return `<button type="button" class="media-pick${idx >= 0 ? ' selected' : ''}" data-id="${m.id}" title="${esc(m.name)}">
                    ${m.is_video ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video><span class="play">${icon('play')}</span>` : `<img src="${esc(m.url)}" alt="" loading="lazy">`}
                    <span class="num">${idx >= 0 ? idx + 1 : ''}</span></button>`;
            }).join('');
        }
        $('#media-library')?.addEventListener('click', (e) => {
            const b = e.target.closest('.media-pick');
            if (!b) return;
            const id = Number(b.dataset.id);
            const i = picked.indexOf(id);
            i >= 0 ? picked.splice(i, 1) : picked.push(id);
            renderLibrary();
        });
        $('#media-insert')?.addEventListener('click', () => {
            picked.forEach((id) => {
                const m = library.find((x) => x.id === id);
                if (m && !media.some((x) => x.id === id)) media.push({ id: m.id, url: m.url, mime: m.mime, name: m.name });
            });
            dlg.close();
            renderMedia();
        });
        $$('[data-open-media]', form).forEach((b) => b.addEventListener('click', () => {
            picked = [];
            dlg.showModal();
            loadLibrary();
        }));
        const dz = $('#dropzone');
        if (dz) {
            wireDropzone(dz, $('#media-file'), async (files) => {
                const prog = $('#upload-progress');
                for (const f of files) {
                    const p = progressRow(prog, f.name);
                    try {
                        const m = await upload(f, p.set);
                        library.unshift(m);
                        picked.push(m.id);
                    } catch (err) { toast(f.name + ': ' + err.message, 'error'); }
                    p.done();
                }
                renderLibrary();
            });
        }
        // Paste images straight into the editor.
        content?.addEventListener('paste', async (e) => {
            const files = Array.from(e.clipboardData?.files || []).filter((f) => f.type.startsWith('image/'));
            if (!files.length) return;
            e.preventDefault();
            for (const f of files) {
                try { const m = await upload(f); media.push({ id: m.id, url: m.url, mime: m.mime, name: m.name }); renderMedia(); toast('Image added'); }
                catch (err) { toast(err.message, 'error'); }
            }
        });

        // Warn before leaving with unsaved changes.
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

        renderMedia();
    }
})();
