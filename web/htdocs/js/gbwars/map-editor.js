/**
 * Game Boy Wars 3 map creator (admin).
 *
 * Edits a draft in its logical form -- terrain grid + unit list + metadata --
 * and saves it to the server, which validates everything again and only
 * builds the game's checksummed file on download/publish. Drawing follows
 * the site's own map viewer (map-renderer.js): staggered rows, terrain from
 * terrain_16x16.png, water from water.png, units from units_16x16.png.
 */
(function () {
    'use strict';

    const cfg = JSON.parse(document.getElementById('editor-config').textContent);
    const T = cfg.i18n;
    const $ = (id) => document.getElementById(id);

    const TILE = 16;            // source sprite size
    const SHEET_COLS = 8;       // both sprite sheets are 8 tiles wide
    const MIN_SIZE = 20, MAX_SIZE = 50;
    const DEFAULT_TILE = 0x29;  // Sea
    const PLAIN = 0x20;
    const RS_BASE = 0x01, WM_BASE = 0x0C;
    const WATER_ROW = { 0x2A: 0, 0x29: 1, 0x28: 2, 0x22: 3, 0x23: 3 };
    const TERRAIN_GROUPS = [
        ['g_terrain', 0x20, 0x2A],
        ['g_neutral', 0x17, 0x1F],
        ['g_rs', 0x01, 0x0B],
        ['g_wm', 0x0C, 0x16],
    ];
    const UNIT_MIN = 2, UNIT_MAX = 103;   // must match GameboyWars3Util::UNIT_ID_MIN/MAX

    const allowedChars = new Set(Array.from(cfg.charset));

    // ---- state ------------------------------------------------------------
    let draftId = cfg.draft.id || 0;
    let W = cfg.draft.width, H = cfg.draft.height;
    let tiles = unb64(cfg.draft.tiles);
    let unitAt = new Map();                      // tile index -> unit id
    cfg.draft.units.forEach((u) => unitAt.set(u.y * W + u.x, u.unit_id));
    let publishedNum = cfg.draft.published_map_num;
    let publishCount = cfg.draft.publish_count;

    const sel = { tool: 'paint', tile: PLAIN, unit: 54 };
    let zoom = 2;
    let showGrid = false;
    let dirty = false;
    let hover = null;
    const undoStack = [], redoStack = [];
    let names = { terrain: {}, units: {} };
    let img = {};

    // ---- helpers ----------------------------------------------------------
    function unb64(str) {
        const bin = atob(str);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    }
    function b64(u8) {
        let s = '';
        for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
        return btoa(s);
    }
    const hex2 = (n) => '0x' + n.toString(16).toUpperCase().padStart(2, '0');
    const terrainName = (id) => names.terrain[id] || ('Terrain ' + hex2(id));
    const unitName = (id) => names.units[id] || ('Unit ' + id);
    const px = () => TILE * zoom;

    function loadImage(url) {
        return new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = () => reject(new Error('Could not load ' + url));
            i.src = url;
        });
    }

    // ---- drawing ------------------------------------------------------------
    const base = $('me-base'), over = $('me-over');
    const bctx = base.getContext('2d'), octx = over.getContext('2d');

    function drawTerrain(ctx, id, dx, dy, size) {
        const sx = (id % SHEET_COLS) * TILE, sy = Math.floor(id / SHEET_COLS) * TILE;
        ctx.drawImage(img.terrain, sx, sy, TILE, TILE, dx, dy, size, size);
        if (id in WATER_ROW) ctx.drawImage(img.water, 0, WATER_ROW[id] * TILE, TILE, TILE, dx, dy, size, size);
    }
    function drawUnit(ctx, id, dx, dy, size) {
        const sx = (id % SHEET_COLS) * TILE, sy = Math.floor(id / SHEET_COLS) * TILE;
        ctx.drawImage(img.units, sx, sy, TILE, TILE, dx, dy, size, size);
    }
    function tileOrigin(x, y) {
        const s = px();
        return [x * s + (y % 2 === 1 ? s / 2 : 0), y * s];
    }

    function sizeCanvases() {
        const s = px();
        const w = W * s + s / 2, h = H * s;
        [base, over].forEach((c) => { c.width = w; c.height = h; c.style.width = w + 'px'; c.style.height = h + 'px'; });
        $('me-stack').style.width = w + 'px';
        $('me-stack').style.height = h + 'px';
    }

    function render() {
        const s = px();
        bctx.imageSmoothingEnabled = false;
        bctx.fillStyle = '#000';
        bctx.fillRect(0, 0, base.width, base.height);
        for (let y = 0; y < H; y++) {
            for (let x = 0; x < W; x++) {
                const [dx, dy] = tileOrigin(x, y);
                drawTerrain(bctx, tiles[y * W + x], dx, dy, s);
            }
        }
        unitAt.forEach((id, i) => {
            const [dx, dy] = tileOrigin(i % W, Math.floor(i / W));
            drawUnit(bctx, id, dx, dy, s);
        });
        if (showGrid) {
            bctx.strokeStyle = 'rgba(0,0,0,0.35)';
            bctx.lineWidth = 1;
            for (let y = 0; y < H; y++) {
                for (let x = 0; x < W; x++) {
                    const [dx, dy] = tileOrigin(x, y);
                    bctx.strokeRect(dx + 0.5, dy + 0.5, s - 1, s - 1);
                }
            }
        }
        drawCursor();
        updateWarnings();
        updateCounts();
    }

    function drawCursor() {
        octx.clearRect(0, 0, over.width, over.height);
        if (!hover) return;
        const s = px();
        const [dx, dy] = tileOrigin(hover.x, hover.y);
        octx.lineWidth = 2;
        octx.strokeStyle = '#ffd400';
        octx.strokeRect(dx + 1, dy + 1, s - 2, s - 2);
    }

    let renderQueued = false;
    function queueRender() {
        if (renderQueued) return;
        renderQueued = true;
        requestAnimationFrame(() => { renderQueued = false; render(); });
    }

    // ---- geometry -----------------------------------------------------------
    function pixelToTile(mx, my) {
        const s = px();
        const y = Math.floor(my / s);
        const x = Math.floor((mx - (y % 2 === 1 ? s / 2 : 0)) / s);
        if (x < 0 || x >= W || y < 0 || y >= H) return null;
        return { x, y };
    }
    // Odd rows sit half a tile to the right, so a tile touches two tiles in
    // the row above and two in the row below (six neighbours, like the game).
    function neighbours(i) {
        const x = i % W, y = Math.floor(i / W);
        const out = [];
        if (x > 0) out.push(i - 1);
        if (x < W - 1) out.push(i + 1);
        const dxs = (y % 2 === 1) ? [0, 1] : [-1, 0];
        [-1, 1].forEach((dy) => {
            const ny = y + dy;
            if (ny < 0 || ny >= H) return;
            dxs.forEach((d) => {
                const nx = x + d;
                if (nx >= 0 && nx < W) out.push(ny * W + nx);
            });
        });
        return out;
    }

    // ---- undo / redo --------------------------------------------------------
    function snapshot() {
        return { W, H, tiles: tiles.slice(), units: Array.from(unitAt.entries()) };
    }
    function restore(s) {
        W = s.W; H = s.H; tiles = s.tiles.slice(); unitAt = new Map(s.units);
        $('me-width').value = W; $('me-height').value = H;
        sizeCanvases();
        markDirty();
        queueRender();
    }
    function pushUndo() {
        undoStack.push(snapshot());
        if (undoStack.length > 100) undoStack.shift();
        redoStack.length = 0;
        syncHistoryButtons();
    }
    function undo() {
        if (!undoStack.length) return;
        redoStack.push(snapshot());
        restore(undoStack.pop());
        syncHistoryButtons();
    }
    function redo() {
        if (!redoStack.length) return;
        undoStack.push(snapshot());
        restore(redoStack.pop());
        syncHistoryButtons();
    }
    function syncHistoryButtons() {
        $('me-undo').disabled = !undoStack.length;
        $('me-redo').disabled = !redoStack.length;
    }

    // ---- editing ------------------------------------------------------------
    let strokeStarted = false;
    function beginStroke() { strokeStarted = false; }
    function change() {
        if (!strokeStarted) { pushUndo(); strokeStarted = true; }
        markDirty();
    }

    function applyAt(t, isStart) {
        const i = t.y * W + t.x;
        switch (sel.tool) {
            case 'paint':
                if (tiles[i] !== sel.tile) { change(); tiles[i] = sel.tile; }
                break;
            case 'fill':
                if (isStart) floodFill(i);
                break;
            case 'unit':
                if (unitAt.get(i) !== sel.unit) { change(); unitAt.set(i, sel.unit); }
                break;
            case 'erase':
                if (unitAt.has(i)) { change(); unitAt.delete(i); }
                break;
            case 'pick':
                pick(i);
                break;
        }
    }

    function floodFill(start) {
        const target = tiles[start];
        if (target === sel.tile) return;
        change();
        const stack = [start];
        tiles[start] = sel.tile;
        while (stack.length) {
            const i = stack.pop();
            neighbours(i).forEach((n) => {
                if (tiles[n] === target) { tiles[n] = sel.tile; stack.push(n); }
            });
        }
    }

    function pick(i) {
        if ((sel.tool === 'unit' || sel.tool === 'erase') && unitAt.has(i)) {
            setUnit(unitAt.get(i));
            setTool('unit');
        } else {
            setTile(tiles[i]);
            if (sel.tool === 'pick') setTool('paint');
        }
    }

    // ---- palette ------------------------------------------------------------
    function swatch(kind, id, drawFn) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'me-swatch';
        b.dataset[kind] = id;
        b.title = (kind === 'tile' ? terrainName(id) + ' (' + hex2(id) + ')' : unitName(id));
        const c = document.createElement('canvas');
        c.width = TILE; c.height = TILE;
        const cx = c.getContext('2d');
        cx.imageSmoothingEnabled = false;
        drawFn(cx);
        b.appendChild(c);
        return b;
    }

    function buildPalettes() {
        const tp = $('me-terrain-palette');
        tp.textContent = '';
        TERRAIN_GROUPS.forEach(([key, from, to]) => {
            const h = document.createElement('h4');
            h.textContent = T[key];
            tp.appendChild(h);
            const row = document.createElement('div');
            row.className = 'me-swatches';
            for (let id = from; id <= to; id++) {
                row.appendChild(swatch('tile', id, (cx) => drawTerrain(cx, id, 0, 0, TILE)));
            }
            tp.appendChild(row);
        });

        const up = $('me-unit-palette');
        up.textContent = '';
        [['g_units_rs', 0], ['g_units_wm', 1]].forEach(([key, parity]) => {
            const h = document.createElement('h4');
            h.textContent = T[key];
            up.appendChild(h);
            const row = document.createElement('div');
            row.className = 'me-swatches';
            for (let id = UNIT_MIN; id <= UNIT_MAX; id++) {
                if (id % 2 !== parity) continue;
                row.appendChild(swatch('unit', id, (cx) => { drawTerrain(cx, PLAIN, 0, 0, TILE); drawUnit(cx, id, 0, 0, TILE); }));
            }
            up.appendChild(row);
        });
        highlightSelection();
    }

    function highlightSelection() {
        document.querySelectorAll('.me-swatch').forEach((b) => {
            const on = (b.dataset.tile !== undefined && +b.dataset.tile === sel.tile)
                || (b.dataset.unit !== undefined && +b.dataset.unit === sel.unit);
            b.classList.toggle('is-picked', on);
        });
        $('me-current-tile').textContent = terrainName(sel.tile);
        $('me-current-unit').textContent = unitName(sel.unit);
    }
    function setTile(id) { sel.tile = id; highlightSelection(); }
    function setUnit(id) { sel.unit = id; highlightSelection(); }
    // One palette box, a dropdown choosing what it shows. Unit placement
    // shows the units; painting and filling show the terrain; erase and pick
    // leave whatever is open. Setting .value does not fire "change", so the
    // tool and the dropdown never chase each other.
    function showPalette(kind) {
        $('me-palette-kind').value = kind;
        $('me-terrain-palette').hidden = kind !== 'terrain';
        $('me-unit-palette').hidden = kind !== 'units';
        $('me-current-tile-line').hidden = kind !== 'terrain';
        $('me-current-unit-line').hidden = kind !== 'units';
        $('me-count-wrap').hidden = kind !== 'units';
    }
    function setTool(t) {
        sel.tool = t;
        document.querySelectorAll('.me-tool').forEach((b) => b.classList.toggle('is-picked', b.dataset.tool === t));
        if (t === 'unit') showPalette('units');
        else if (t === 'paint' || t === 'fill') showPalette('terrain');
    }

    // ---- warnings and counters ------------------------------------------------
    function updateCounts() {
        $('me-unit-count').textContent = unitAt.size;
    }
    function nameProblem(text, max) {
        const chars = Array.from(text);
        if (chars.length > max) return T.err_too_long.replace('%d', max);
        for (const ch of chars) {
            if (!allowedChars.has(ch) && !(/[a-z]/.test(ch) && allowedChars.has(ch.toUpperCase()))) {
                return T.err_chars.replace('%s', ch);
            }
        }
        return '';
    }
    function updateWarnings() {
        const list = [];
        let rs = 0, wm = 0;
        for (let i = 0; i < tiles.length; i++) { if (tiles[i] === RS_BASE) rs++; else if (tiles[i] === WM_BASE) wm++; }
        if (rs !== 1) list.push(T.warn_rs_base.replace('%d', rs));
        if (wm !== 1) list.push(T.warn_wm_base.replace('%d', wm));
        if (!$('me-map-name').value.trim()) list.push(T.warn_no_name);
        const np = nameProblem($('me-map-name').value.trim(), 8);
        if (np) list.push(T.field_map_name + ': ' + np);
        const cp = nameProblem($('me-category').value.trim(), 9);
        if (cp) list.push(T.field_category + ': ' + cp);
        const ul = $('me-warnings');
        ul.textContent = '';
        (list.length ? list : [T.check_ok]).forEach((w) => { const li = document.createElement('li'); li.textContent = w; ul.appendChild(li); });
        $('me-warnings-box').classList.toggle('is-ok', list.length === 0);
    }

    // ---- saving ---------------------------------------------------------------
    function setStatus(msg, kind) {
        const el = $('me-status');
        el.textContent = msg;
        el.className = 'me-status' + (kind ? ' me-status--' + kind : '');
    }
    function markDirty() {
        dirty = true;
        setStatus(T.unsaved, 'warn');
    }

    function payload() {
        const units = [];
        unitAt.forEach((id, i) => units.push({ x: i % W, y: Math.floor(i / W), unit_id: id }));
        return {
            id: draftId, width: W, height: H, tiles: b64(tiles), units,
            map_name: $('me-map-name').value, category: $('me-category').value,
            player_gold: +$('me-player-gold').value, enemy_gold: +$('me-enemy-gold').value,
            player_materials: +$('me-player-mat').value, enemy_materials: +$('me-enemy-mat').value,
            price_yen: +$('me-price').value, name_e: $('me-name-e').value, category_e: $('me-category-e').value,
        };
    }

    async function post(action, extra) {
        const fd = new FormData();
        fd.append(cfg.csrfField, cfg.csrfToken);
        fd.append('form_action', action);
        Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
        const res = await fetch(cfg.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
        let data;
        try { data = await res.json(); } catch (e) { throw new Error(T.err_session); }
        return data;
    }

    async function save() {
        const np = nameProblem($('me-map-name').value.trim(), 8) || nameProblem($('me-category').value.trim(), 9);
        if (np) { setStatus(T.save_failed + ' ' + np, 'bad'); return false; }
        setStatus(T.saving);
        $('me-save').disabled = true;
        try {
            const data = await post('save', { draft: JSON.stringify(payload()) });
            if (!data.ok) { setStatus(T.save_failed + ' ' + data.error, 'bad'); return false; }
            if (!draftId) history.replaceState(null, '', cfg.endpoint + '?id=' + data.id);
            draftId = data.id;
            dirty = false;
            setStatus(T.saved + ' ' + new Date().toLocaleTimeString(), 'ok');
            syncActions();
            return true;
        } catch (e) {
            setStatus(T.save_failed + ' ' + e.message, 'bad');
            return false;
        } finally {
            $('me-save').disabled = false;
        }
    }

    async function publish() {
        if (!window.confirm(T.publish_confirm)) return;
        if ((dirty || !draftId) && !(await save())) return;
        setStatus(T.publishing);
        try {
            const data = await post('publish', { id: draftId });
            if (!data.ok) { setStatus(T.publish_failed + ' ' + data.error, 'bad'); return; }
            publishedNum = data.number; publishCount++;
            syncActions();
            setStatus(T.published.replace('%s', data.number), 'ok');
        } catch (e) {
            setStatus(T.publish_failed + ' ' + e.message, 'bad');
        }
    }

    async function download() {
        if ((dirty || !draftId) && !(await save())) return;
        window.location.href = cfg.endpoint + '?id=' + draftId + '&download=1';
    }

    function syncActions() {
        const badge = $('me-published');
        badge.hidden = !publishedNum;
        if (publishedNum) badge.textContent = T.published_as + ' #' + publishedNum + (publishCount > 1 ? ' ×' + publishCount : '');
        $('me-publish').textContent = publishedNum ? T.publish_again : T.publish;
    }

    // ---- resizing -------------------------------------------------------------
    function resize(nw, nh) {
        if (nw === W && nh === H) return;
        pushUndo();
        const nt = new Uint8Array(nw * nh).fill(DEFAULT_TILE);
        for (let y = 0; y < Math.min(H, nh); y++) {
            for (let x = 0; x < Math.min(W, nw); x++) nt[y * nw + x] = tiles[y * W + x];
        }
        const nu = new Map();
        unitAt.forEach((id, i) => {
            const x = i % W, y = Math.floor(i / W);
            if (x < nw && y < nh) nu.set(y * nw + x, id);
        });
        W = nw; H = nh; tiles = nt; unitAt = nu;
        sizeCanvases();
        markDirty();
        queueRender();
    }
    function onSizeChange() {
        const nw = Math.round(+$('me-width').value), nh = Math.round(+$('me-height').value);
        if (nw < MIN_SIZE || nw > MAX_SIZE || nh < MIN_SIZE || nh > MAX_SIZE) {
            $('me-width').value = W; $('me-height').value = H;
            setStatus(T.err_size, 'bad');
            return;
        }
        resize(nw, nh);
    }

    // ---- zoom / grid ------------------------------------------------------------
    // The biggest zoom at which the whole map fits the frame; the frame
    // scrolls when it does not, but a first look at the whole map is better.
    function autoZoom() {
        const avail = $('me-stage').clientWidth - 4;
        for (const z of [3, 2]) if ((W * TILE + TILE / 2) * z <= avail) return z;
        return 1;
    }
    function setZoom(z) {
        zoom = z;
        document.querySelectorAll('.me-zoom').forEach((b) => b.classList.toggle('is-picked', +b.dataset.zoom === z));
        sizeCanvases();
        queueRender();
    }

    // ---- pointer input ----------------------------------------------------------
    let painting = false, lastIndex = -1;
    function tileFromEvent(e) {
        const r = over.getBoundingClientRect();
        return pixelToTile(e.clientX - r.left, e.clientY - r.top);
    }
    over.addEventListener('contextmenu', (e) => e.preventDefault());
    over.addEventListener('pointerdown', (e) => {
        const t = tileFromEvent(e);
        if (!t) return;
        e.preventDefault();
        if (e.button === 2) { pick(t.y * W + t.x); queueRender(); return; }
        if (e.button !== 0) return;
        over.setPointerCapture(e.pointerId);
        painting = true;
        lastIndex = -1;
        beginStroke();
        lastIndex = t.y * W + t.x;
        applyAt(t, true);
        queueRender();
    });
    over.addEventListener('pointermove', (e) => {
        const t = tileFromEvent(e);
        const moved = (t ? t.x + ',' + t.y : '') !== (hover ? hover.x + ',' + hover.y : '');
        hover = t;
        if (t) {
            const i = t.y * W + t.x;
            const u = unitAt.get(i);
            $('me-hover').textContent = '(' + t.x + ', ' + t.y + ') ' + terrainName(tiles[i]) + (u ? ' · ' + unitName(u) : '');
        } else {
            $('me-hover').textContent = '';
        }
        if (painting && t && t.y * W + t.x !== lastIndex) {
            lastIndex = t.y * W + t.x;
            applyAt(t, false);
            queueRender();
        } else if (moved) {
            drawCursor();
        }
    });
    over.addEventListener('pointerleave', () => { hover = null; drawCursor(); $('me-hover').textContent = ''; });
    const stopPainting = () => { painting = false; };
    over.addEventListener('pointerup', stopPainting);
    over.addEventListener('pointercancel', stopPainting);

    // ---- controls ----------------------------------------------------------------
    function bindControls() {
        document.querySelectorAll('.me-tool').forEach((b) => b.addEventListener('click', () => setTool(b.dataset.tool)));
        document.querySelectorAll('.me-zoom').forEach((b) => b.addEventListener('click', () => setZoom(+b.dataset.zoom)));
        $('me-terrain-palette').addEventListener('click', (e) => {
            const b = e.target.closest('.me-swatch');
            if (!b) return;
            setTile(+b.dataset.tile);
            if (sel.tool !== 'fill') setTool('paint');
        });
        $('me-unit-palette').addEventListener('click', (e) => {
            const b = e.target.closest('.me-swatch');
            if (!b) return;
            setUnit(+b.dataset.unit);
            setTool('unit');
        });
        $('me-palette-kind').addEventListener('change', (e) => {
            showPalette(e.target.value);
            if (e.target.value === 'units') setTool('unit');
            else if (sel.tool === 'unit' || sel.tool === 'erase') setTool('paint');
        });
        $('me-undo').addEventListener('click', undo);
        $('me-redo').addEventListener('click', redo);
        $('me-save').addEventListener('click', save);
        $('me-download').addEventListener('click', download);
        $('me-publish').addEventListener('click', publish);
        $('me-grid').addEventListener('change', (e) => { showGrid = e.target.checked; queueRender(); });
        $('me-width').addEventListener('change', onSizeChange);
        $('me-height').addEventListener('change', onSizeChange);

        ['me-map-name', 'me-category', 'me-player-gold', 'me-enemy-gold', 'me-player-mat', 'me-enemy-mat',
            'me-price', 'me-name-e', 'me-category-e'].forEach((id) => {
            $(id).addEventListener('input', () => { markDirty(); updateWarnings(); });
        });

        document.addEventListener('keydown', (e) => {
            const inField = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''));
            const mod = e.ctrlKey || e.metaKey;
            if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
            if (inField) return;
            if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
            if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
            if (mod || e.altKey) return;
            const map = { p: 'paint', f: 'fill', u: 'unit', e: 'erase', i: 'pick' };
            const k = e.key.toLowerCase();
            if (map[k]) setTool(map[k]);
            else if (k === 'g') { $('me-grid').checked = !$('me-grid').checked; showGrid = $('me-grid').checked; queueRender(); }
        });

        window.addEventListener('beforeunload', (e) => {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
    }

    function fillForm() {
        const d = cfg.draft;
        $('me-map-name').value = d.map_name;
        $('me-category').value = d.category;
        $('me-width').value = W; $('me-height').value = H;
        $('me-player-gold').value = d.player_gold; $('me-enemy-gold').value = d.enemy_gold;
        $('me-player-mat').value = d.player_materials; $('me-enemy-mat').value = d.enemy_materials;
        $('me-price').value = d.price_yen;
        $('me-name-e').value = d.name_e; $('me-category-e').value = d.category_e;
    }

    // ---- start ----------------------------------------------------------------------
    async function start() {
        fillForm();
        bindControls();
        setStatus(T.loading);
        try {
            const [terrain, units, water] = await Promise.all([
                loadImage('/images/gbwars/terrain_16x16.png'),
                loadImage('/images/gbwars/units_16x16.png'),
                loadImage('/images/gbwars/water.png'),
            ]);
            img = { terrain, units, water };
            try {
                const res = await fetch('/gbwars/api/strings.php?lang=' + encodeURIComponent(cfg.lang));
                if (res.ok) {
                    const s = await res.json();
                    names = { terrain: s.terrain || {}, units: s.units || {} };
                }
            } catch (e) { /* names fall back to ids */ }
        } catch (e) {
            setStatus(e.message, 'bad');
            return;
        }
        buildPalettes();
        sizeCanvases();
        setZoom(autoZoom());
        setTool('paint');
        syncHistoryButtons();
        syncActions();
        render();
        setStatus(draftId ? T.saved_state : T.new_state);
        dirty = false;
    }

    start();
})();
