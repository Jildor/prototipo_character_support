'use strict';

const RACES = { 1: 'Humano', 2: 'Orco', 3: 'Enano', 4: 'Elfo de la noche', 5: 'No-muerto', 6: 'Tauren', 7: 'Gnomo', 8: 'Trol', 10: 'Elfo de sangre', 11: 'Draenei' };
const CLASSES = { 1: 'Guerrero', 2: 'Paladín', 3: 'Cazador', 4: 'Pícaro', 5: 'Sacerdote', 6: 'Caballero de la Muerte', 7: 'Chamán', 8: 'Mago', 9: 'Brujo', 11: 'Druida' };
const MAPS = {
    0: 'Reinos del Este', 1: 'Kalimdor', 530: 'Terrallende', 571: 'Rasganorte', 609: 'Acherus (zona de inicio DK)',
    30: 'Valle de Alterac (BG)', 489: 'Garganta Grito de Guerra (BG)', 529: 'Cuenca de Arathi (BG)',
    566: 'Ojo de la Tormenta (BG)', 607: 'Playa de los Ancestros (BG)', 628: 'Isla de la Conquista (BG)',
};

const $ = (id) => document.getElementById(id);
let current = null;            // personaje seleccionado
let costs = {};                // coste en créditos por tipo de petición
const knownStatus = new Map(); // id de petición -> status, para resaltar los cambios

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function api(action, { query = {}, body = null, showSql = true } = {}) {
    const qs = new URLSearchParams({ action, ...query });
    const res = await fetch('api.php?' + qs, body
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
        : {});
    const json = await res.json();
    if (showSql) renderSql(json.sql);
    return json;
}

function message(kind, content) {
    const body = Array.isArray(content)
        ? '<ul>' + content.map((c) => `<li>${esc(c)}</li>`).join('') + '</ul>'
        : esc(content);
    return `<div class="msg ${kind}">${body}</div>`;
}

function renderSql(queries) {
    if (!queries?.length) return;
    $('sql').innerHTML = queries.map((q) => {
        const sql = q.sql.replace(/\s+/g, ' ').trim();
        const params = q.params.length ? `\n<span class="params">-- parámetros: ${esc(JSON.stringify(q.params))}</span>` : '';
        return `<pre><span class="db">-- BD ${esc(q.db)}</span>
${esc(sql)}${params}</pre>`;
    }).join('');
}

function duration(seconds) {
    if (seconds < 60) return `${seconds} s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
    return `${Math.floor(seconds / 3600)} h ${Math.floor((seconds % 3600) / 60)} min`;
}

// ---------------------------------------------------------------- Conexión

async function checkConnection() {
    const r = await api('status', { showSql: false });
    if (!r.ok || r.errors.length) {
        $('connection').innerHTML = message('err', r.errors);
        return;
    }
    const realm = r.data.realm;
    const auth = r.data.auth ? '' : ' · sin BD auth (cuenta destino por id; bans y existencia solo los comprueba el servidor)';
    costs = r.data.costs ?? {};
    document.querySelectorAll(".cost").forEach((el) => {
        const cost = +(costs[el.dataset.type] ?? 0);
        el.textContent = cost ? `(${cost} créditos)` : "(gratis)";
    });
    $('connection').textContent = `Conectado a ${r.data.version} · cooldown de desatasco: ${realm.unstuck_cooldown_minutes} min${auth}`;
}

// ---------------------------------------------------------------- Personaje

async function loadCharacter(q, showSql = true) {
    const r = await api('character', { query: { q }, showSql });
    if (!r.ok) {
        current = null;
        $('character').innerHTML = message('err', r.errors);
        $('actions').hidden = true;
        refreshRequests();
        return;
    }
    current = r.data;
    renderCharacter();
    refreshRequests();
}

function renderCharacter() {
    const c = current;
    const state = c.deleted ? '<span class="badge s2">Borrado</span>'
        : c.online ? '<span class="badge s0">Conectado</span>'
        : '<span class="badge s1">Desconectado</span>';
    const logout = c.logout_time ? `hace ${duration(c.logout_ago)}` : 'nunca';

    $('character').innerHTML = `
        <dl class="info">
            <dt>Personaje</dt><dd><strong>${esc(c.name)}</strong> (guid ${c.guid}) ${state}</dd>
            <dt>Cuenta</dt><dd>${c.account_name ? esc(c.account_name) + ' ' : ''}(id ${c.deleted ? c.deleteInfos_Account : c.account})</dd>
            <dt>Datos</dt><dd>Nivel ${c.level} · ${esc(RACES[c.race] ?? c.race)} · ${esc(CLASSES[c.class] ?? c.class)} · ${c.alliance ? 'Alianza' : 'Horda'}</dd>
            <dt>Posición</dt><dd>${esc(MAPS[c.map] ?? 'Mapa ' + c.map)} · zona ${c.zone}${c.in_flight ? ' · <strong>en vuelo</strong>' : ''}</dd>
            <dt>Última salida</dt><dd>${logout}</dd>
            <dt>Desatasco</dt><dd>${c.unstuck_cooldown ? `en espera, faltan ${duration(c.unstuck_cooldown)}` : 'disponible'}</dd>
        </dl>`;

    $('actions').hidden = false;
    $("credits-balance").innerHTML = `Créditos de la cuenta ${c.payer}: <strong id="balance">${c.balance}</strong>`;
    $("change-checks").innerHTML = c.change_checks.length ? message("err", c.change_checks) : "";
    $('unstuck-dest').textContent = 'Destino: ' + (c.alliance ? 'Páramos de Poniente' : 'Los Baldíos') + '. El personaje debe estar desconectado.';
    $('unstuck-checks').innerHTML = c.unstuck_checks.length
        ? message('err', ['La web no permitiría pedirlo ahora:', ...c.unstuck_checks])
        : '';
    $('restore-box').hidden = !c.deleted;
    if (c.deleted) {
        $('restore-info').textContent = `Vuelve a su cuenta original (id ${c.deleteInfos_Account}) con el nombre temporal `
            + `"${c.restore_name}" y tendrá que elegir un nombre nuevo al entrar al juego.`;
        $('restore-checks').innerHTML = c.restore_checks.length
            ? message('err', ['La web no permitiría pedirlo ahora:', ...c.restore_checks])
            : '';
    }
    $('requests-filter').textContent = $('only-character').checked ? `· ${c.name}` : '';
}

// ---------------------------------------------------------------- Acciones

async function sendRequest(action, body, target) {
    const r = await api(action, { body: { ...body, skip_checks: $('skip-checks').checked } });
    $(target).innerHTML = r.ok
        ? message('pend', `Petición ${r.data.id} creada. ${r.data.message}`)
        : message('err', r.errors);
    refreshRequests();
    if (current && r.ok) loadCharacter(String(current.guid), false);
}

$('search-form').addEventListener('submit', (e) => {
    e.preventDefault();
    $('action-result').innerHTML = '';
    loadCharacter($('search').value);
});

$('btn-restore').addEventListener('click', () => {
    sendRequest('restore', { guid: current.guid }, 'action-result');
});

$('btn-unstuck').addEventListener('click', () => {
    sendRequest('unstuck', { guid: current.guid }, 'action-result');
});

$('change-form').addEventListener('submit', (e) => {
    e.preventDefault();
    sendRequest('change_account', { guid: current.guid, account: $('target-account').value }, 'action-result');
});

$('raw-form').addEventListener('submit', (e) => {
    e.preventDefault();
    sendRequest('raw', { guid: +$('raw-guid').value, type: +$('raw-type').value, params: $('raw-params').value }, 'raw-result');
});

$("credits-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const r = await api("set_credits", { body: { account: current.payer, credits: +$("credits-value").value } });
    if (r.ok) $("balance").textContent = r.data.balance;
    loadCharacter(String(current.guid), false);
});

$('only-character').addEventListener('change', () => {
    if (current) renderCharacter();
    refreshRequests();
});

// ---------------------------------------------------------------- Lista de peticiones (sondeo cada 3 s)

async function refreshRequests() {
    const guid = $('only-character').checked && current ? current.guid : 0;
    const account = current?.payer ?? 0;
    const r = await api("requests", { query: { guid, account }, showSql: false });
    if (!r.ok) return;
    renderCharges(r.data.charges);
    if (r.data.balance !== null && $("balance")) $("balance").textContent = r.data.balance;

    let currentChanged = false;
    $("requests").innerHTML = r.data.requests.map((req) => {
        const prev = knownStatus.get(req.id);
        const changed = prev !== undefined && prev !== req.status;
        knownStatus.set(req.id, req.status);
        if (changed && current && req.guid === current.guid) currentChanged = true;

        const params = req.params ? `<div class="muted">${esc(req.params)}</div>` : '';
        return `<tr class="${changed ? 'flash' : ''}">
            <td>${req.id}</td>
            <td>${esc(req.name ?? '?')}<div class="muted">guid ${req.guid}</div></td>
            <td>${esc(req.type_name)}${params}</td>
            <td><span class="badge s${req.status}">${esc(req.status_name)}</span></td>
            <td>${esc(req.message)}${req.status ? `<div class="muted">código ${req.result_code}</div>` : ''}</td>
            <td>${esc(req.created_at)}</td>
            <td>${duration(req.wait_seconds)}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="7" class="muted">Sin peticiones.</td></tr>';

    // Si el servidor acaba de procesar una petición del personaje, se recargan sus datos (posición, cuenta…)
    if (currentChanged) loadCharacter(String(current.guid), false);
}

function renderCharges(charges) {
    $("charges").innerHTML = charges.map((ch) => `<tr>
        <td>${ch.id}</td>
        <td>${ch.account_id}</td>
        <td>${esc(ch.type_name)}<div class="muted">guid ${ch.guid}</div></td>
        <td>${ch.request_id ?? "-"}</td>
        <td>${ch.amount}</td>
        <td><span class="badge ${esc(ch.state)}">${esc(ch.state_name)}</span>${ch.note ? `<div class="muted">${esc(ch.note)}</div>` : ""}</td>
        <td>${esc(ch.created)}</td>
    </tr>`).join("") || `<tr><td colspan="7" class="muted">Sin movimientos.</td></tr>`;
}

checkConnection();
refreshRequests();
setInterval(refreshRequests, 3000);
