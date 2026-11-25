// Screens for the GovernIT Data Eraser Portal.
// Each screen is a function (ctx) => { render(root), ... } where ctx carries helpers.

function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function escapes(s) { if (!s) return '\u2013'; return esc(s); }
function fmtDate(iso) {
  if (typeof iso !== 'string') return '\u2013';
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '\u2013' : esc(date.toLocaleString('da-DK'));
}
function badgeStatus(s) {
  const map = { 'pass': 'badge-ok', 'fail': 'badge-bad', 'aborted': 'badge-warn', 'unknown': 'badge-neutral' };
  return map[s] || 'badge-neutral';
}
function pageHeader({ title, sub, actions }) {
  return '<div class="page-header"><h2>' + esc(title) + '</h2>' + (sub ? '<div class="sub">' + esc(sub) + '</div>' : '') +
    (actions ? '<div class="flex mt-8">' + actions + '</div>' : '') + '</div>';
}
function emptyState(msg) { return '<div class="empty">' + esc(msg) + '</div>'; }
function notice(type, msg) { return '<div class="notice notice-' + type + '">' + msg + '</div>'; }

// Modal helper returns object with .open(html), .close(), root element.
function makeModal() {
  const root = document.createElement('div');
  root.className = 'modal-overlay';
  root.style.display = 'none';
  root.innerHTML = '<div class="modal"><div class="modal-header"><span class="m-title"></span><button class="btn btn-sm btn-secondary m-x">&times;</button></div><div class="modal-body"></div></div>';
  root.addEventListener('click', e => { if (e.target === root) close(); });
  const close = () => { root.style.display = 'none'; root.querySelector('.modal-body').innerHTML = ''; };
  root.querySelector('.m-x').addEventListener('click', close);
  document.body.appendChild(root);
  return {
    root,
    open(html) { root.style.display = 'flex'; root.querySelector('.modal-body').innerHTML = html; },
    setTitle(t) { root.querySelector('.m-title').textContent = t; },
    close,
  };
}

// Async wrapper: renders loading, then the render fn. Returns { refresh }.
function asyncScreen(ctx, loader, refreshCb) {
  const root = document.createElement('div');
  let current = null;
  root.innerHTML = '<div class="empty">Indlæser\u2026</div>';
  const render = async () => {
    root.innerHTML = '<div class="empty">Indlæser\u2026</div>';
    try { current = await loader(root); }
    catch (e) { root.innerHTML = notice('bad', 'Fejl: ' + esc(e.message)); }
    if (refreshCb) refreshCb(current);
  };
  render();
  return { root, refresh: render, current: () => current };
}

// ---------------------------------------------------------------------------
// DASHBOARD
// ---------------------------------------------------------------------------
const Screens = {

  dashboard(ctx) {
    return asyncScreen(ctx, async (root) => {
      let sessions = [], reports = [];
      try { const q = await API.querySessions(); sessions = q.sessions || []; reports = q.reports || []; } catch (e) {}
      const certs = reports.filter(r => r.status === 'ready');
      const passed = sessions.filter(s => s.final_status === 'pass').length;
      const fails = sessions.filter(s => s.final_status === 'fail').length;
      root.innerHTML =
        pageHeader({ title: 'Dashboard' }) +
        '<div class="stats">' +
        stat('Antal sessioner', sessions.length) +
        stat('Best\u00e5ede', passed) +
        stat('Fejlede', fails) +
        stat('Certifikater', certs.length) +
        '</div>' +
        '<div class="card"><div class="card-header">Seneste sessioner</div><div class="card-body">' +
        (sessions.length === 0 ? emptyState('Ingen sessioner endnu.') : sessionsTable(sessions.slice(0, 10))) +
        '</div></div>';
    });
  },

  // -------------------------------------------------------------------------
  // USERS
  // -------------------------------------------------------------------------
  users(ctx) {
    const modal = makeModal();
    return asyncScreen(ctx, async (root, refresh) => {
      let users = [];
      try { users = (await API.listUsers()).users || []; } catch (e) {}
      root.innerHTML =
        pageHeader({ title: 'Brugere', sub: 'Web-portal login-adgange', actions: '<button class="btn btn-primary" id="add-user">+ Ny bruger</button>' }) +
        (users.length === 0 ? emptyState('Ingen brugere.') :
          '<div class="card"><div class="card-body"><table><thead><tr><th>Brugernavn</th><th>Navn</th><th>E-mail</th><th>Roller</th><th>Status</th><th></th></tr></thead><tbody>' +
          users.map(u =>
            '<tr><td><b>' + esc(u.username) + '</b></td><td>' + escapes(u.display_name) + '</td><td>' + escapes(u.email) + '</td>' +
            '<td>' + (u.roles || []).map(r => '<span class="badge badge-neutral">' + esc(r) + '</span>').join(' ') + '</td>' +
            '<td>' + statusBadge(u.active) + '</td>' +
            '<td><button class="btn btn-sm btn-secondary" data-edit="' + esc(u.id) + '">Redig\u00e9r</button></td></tr>').join('') +
          '</tbody></table></div></div>');
      root.querySelector('#add-user')?.addEventListener('click', () => userForm(modal, null, refresh));
      root.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => userForm(modal, b.dataset.edit, refresh)));
    });
  },

  // -------------------------------------------------------------------------
  // CUSTOMERS
  // -------------------------------------------------------------------------
  customers(ctx) {
    const modal = makeModal();
    return asyncScreen(ctx, async (root, refresh) => {
      let items = [];
      try { items = (await API.listCustomers()).customers || []; } catch (e) {}
      root.innerHTML =
        pageHeader({ title: 'Kunder', sub: items.length + ' kunder', actions: '<button class="btn btn-primary" id="add-cust">+ Ny kunde</button>' }) +
        (items.length === 0 ? emptyState('Ingen kunder.') :
          '<div class="card"><div class="card-body"><table><thead><tr><th>Navn</th><th>Kundenr.</th><th>Kontakt</th><th>E-mail</th><th>Status</th><th></th></tr></thead><tbody>' +
          items.map(c =>
            '<tr><td><b>' + esc(c.name) + '</b></td><td>' + esc(c.customer_number) + '</td><td>' + escapes(c.contact_person) + '</td><td>' + escapes(c.contact_email) + '</td>' +
            '<td>' + statusBadge(c.active) + '</td>' +
            '<td><button class="btn btn-sm btn-secondary" data-edit="' + esc(c.id) + '">Redig\u00e9r</button> ' +
            (c.active ? '<button class="btn btn-sm btn-danger" data-del="' + esc(c.id) + '">Arkiv\u00e9r</button>' : '') + '</td></tr>').join('') +
          '</tbody></table></div></div>');
      root.querySelector('#add-cust')?.addEventListener('click', () => customerForm(modal, null, refresh));
      root.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => customerForm(modal, b.dataset.edit, refresh)));
      root.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm('Arkiv\u00e9r kunden?')) return;
        try { await API.deactivateCustomer(b.dataset.del); refresh(); } catch (e) { alert(e.message); }
      }));
    });
  },

  // -------------------------------------------------------------------------
  // PROJECTS
  // -------------------------------------------------------------------------
  projects(ctx) {
    const modal = makeModal();
    return asyncScreen(ctx, async (root, refresh) => {
      let items = [], customers = [];
      try { const q = await API.listProjects(); items = q.projects || []; } catch (e) {}
      try { customers = (await API.listCustomers()).customers || []; } catch (e) {}
      const cmap = {}; customers.forEach(c => cmap[c.id] = c.name);
      root.innerHTML =
        pageHeader({ title: 'Projekter', sub: items.length + ' projekter', actions: '<button class="btn btn-primary" id="add-proj">+ Nyt projekt</button>' }) +
        (items.length === 0 ? emptyState('Ingen projekter.') :
          '<div class="card"><div class="card-body"><table><thead><tr><th>Navn</th><th>Projektnr.</th><th>Kunde</th><th>Status</th><th></th></tr></thead><tbody>' +
          items.map(p =>
            '<tr><td><b>' + esc(p.name) + '</b></td><td>' + esc(p.project_number) + '</td><td>' + escapes(cmap[p.customer_id]) + '</td>' +
            '<td>' + statusBadge(p.status === 'active') + '</td>' +
            '<td><button class="btn btn-sm btn-secondary" data-edit="' + esc(p.id) + '">Redig\u00e9r</button></td></tr>').join('') +
          '</tbody></table></div></div>');
      root.querySelector('#add-proj')?.addEventListener('click', () => projectForm(modal, null, refresh, customers, cmap));
      root.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => projectForm(modal, b.dataset.edit, refresh, customers, cmap)));
    });
  },

  // -------------------------------------------------------------------------
  // TECHNICIANS
  // -------------------------------------------------------------------------
  technicians(ctx) {
    const modal = makeModal();
    return asyncScreen(ctx, async (root, refresh) => {
      let items = [];
      try { items = (await API.listTechnicians()).technicians || []; } catch (e) {}
      root.innerHTML =
        pageHeader({ title: 'Teknikere', sub: items.length + ' teknikere', actions: '<button class="btn btn-primary" id="add-tech">+ Ny tekniker</button>' }) +
        (items.length === 0 ? emptyState('Ingen teknikere.') :
          '<div class="card"><div class="card-body"><table><thead><tr><th>Navn</th><th>Tekniker-id</th><th>E-mail</th><th>Status</th><th></th></tr></thead><tbody>' +
          items.map(t =>
            '<tr><td><b>' + esc(t.name) + '</b></td><td>' + escapes(t.technician_id) + '</td><td>' + escapes(t.email) + '</td>' +
            '<td>' + statusBadge(t.active) + '</td>' +
            '<td><button class="btn btn-sm btn-secondary" data-edit="' + esc(t.id) + '">Redig\u00e9r</button></td></tr>').join('') +
          '</tbody></table></div></div>');
      root.querySelector('#add-tech')?.addEventListener('click', () => technicianForm(modal, null, refresh));
      root.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => technicianForm(modal, b.dataset.edit, refresh)));
    });
  },

  // -------------------------------------------------------------------------
  // PROFILES
  // -------------------------------------------------------------------------
  profiles(ctx) {
    const modal = makeModal();
    return asyncScreen(ctx, async (root, refresh) => {
      let items = [];
      try { items = (await API.listProfiles()).profiles || []; } catch (e) {}
      root.innerHTML =
        pageHeader({ title: 'Sletteprofiler', sub: items.length + ' profiler', actions: '<button class="btn btn-primary" id="add-prof">+ Ny profil</button>' }) +
        (items.length === 0 ? emptyState('Ingen profiler.') :
          '<div class="card"><div class="card-body"><table><thead><tr><th>Navn</th><th>Metode</th><th>Verificering</th><th>Status</th><th></th></tr></thead><tbody>' +
          items.map(p =>
            '<tr><td><b>' + esc(p.name) + '</b></td><td>' + esc(p.method) + '</td><td>' + esc(p.verification) + '</td>' +
            '<td>' + statusBadge(p.active) + '</td>' +
            '<td><button class="btn btn-sm btn-secondary" data-edit="' + esc(p.id) + '">Redig\u00e9r</button></td></tr>').join('') +
          '</tbody></table></div></div>');
      root.querySelector('#add-prof')?.addEventListener('click', () => profileForm(modal, null, refresh));
      root.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => profileForm(modal, b.dataset.edit, refresh)));
    });
  },

  // -------------------------------------------------------------------------
  // REPORTS
  // -------------------------------------------------------------------------
  reports(ctx) {
    return asyncScreen(ctx, async (root, refresh) => {
      let reports = [], sessions = [], certs = [];
      try { const q = await API.querySessions(); sessions = q.sessions || []; certs = q.certificates || []; } catch (e) {}
      try {
        const r = await API.listReports();
        reports = (r && r.reports) || r || [];
        if (!Array.isArray(reports)) reports = [];
      } catch (e) { reports = []; }
      root.innerHTML =
        pageHeader({ title: 'Rapporter', sub: 'Certifikater og udarbejdede rapporter' }) +
        '<div class="card"><div class="card-header">Certifikater (' + certs.length + ')</div><div class="card-body">' +
        (certs.length === 0 ? emptyState('Ingen certifikater endnu.') :
          '<table><thead><tr><th>Kunde</th><th>Computer</th><th>Drev</th><th>Metode</th><th>Resultat</th><th>Dato</th></tr></thead><tbody>' +
          certs.map(c =>
            '<tr><td>' + esc(c.customer_name) + '</td><td>' + escapes(c.computer_serial) + '</td><td>' + escapes(c.drive_model) + '</td>' +
            '<td>' + esc(c.actual_method) + '</td>' +
            '<td><span class="badge ' + badgeStatus((c.status || '').toLowerCase()) + '">' + esc(c.status) + '</span></td>' +
            '<td>' + fmtDate(c.generation_date) + '</td></tr>').join('') +
          '</tbody></table>') +
        '</div></div>' +
        '<div class="card"><div class="card-header"><span>Udarbejdede rapporter</span><button class="btn btn-sm btn-primary" id="gen-report">Gener\u00e9r certifikatrapport</button></div><div class="card-body">' +
        (reports.length === 0 ? emptyState('Ingen rapporter udarbejdet endnu.') :
          '<table><thead><tr><th>Titel</th><th>Type</th><th>Antal</th><th>Status</th><th>Oprettet</th><th></th></tr></thead><tbody>' +
          reports.map(r =>
            '<tr><td>' + esc(r.title) + '</td><td>' + esc(r.kind) + '</td><td>' + esc(r.certificate_count) + '</td>' +
            '<td>' + reportBadge(r.status) + '</td><td>' + fmtDate(r.created_at) + '</td>' +
            '<td>' + (r.status === 'ready' ? '<button type="button" class="btn btn-sm btn-secondary" data-report="' + esc(r.id) + '">PDF</button>' : '') + '</td></tr>').join('') +
          '</tbody></table>') +
        '</div></div>';
      root.querySelectorAll('[data-report]').forEach(button => button.addEventListener('click', async () => {
        try { await API.downloadReport(button.dataset.report); }
        catch (error) { alert(error.message); }
      }));
      root.querySelector('#gen-report')?.addEventListener('click', async () => {
        try { await API.generateReport({}); refresh(); alert('Rapport genereret.'); }
        catch (e) { alert('Fejl: ' + e.message); }
      });
    });
  },

  // -------------------------------------------------------------------------
  // DOWNLOADS – klientprogrammet (static files under /downloads/)
  // -------------------------------------------------------------------------
  downloads(ctx) {
    const root = document.createElement('div');
    const macVersion = '0.0.11';
    const linuxVersion = '0.0.11';
    const dl = (file, label, size) =>
      '<div class="flex mb-8" style="justify-content:space-between;align-items:center;gap:12px">' +
      '<a class="btn btn-secondary" href="/downloads/' + file + '" download>' + esc(label) + '</a>' +
      '<span style="font-size:12px;color:var(--muted)">' + esc(file) + ' · ' + esc(size) + '</span></div>';
    root.innerHTML =
      pageHeader({ title: 'Klientprogram', sub: 'GovernIT Data Eraser Management – download til arbejdsstationen' }) +
      '<div class="card"><div class="card-header">Windows</div><div class="card-body">' +
        dl('GovernIT_Data_Eraser_Management_0.0.11_x64-setup.exe', 'Installer (.exe) – x64', '108 MB') +
        '<div class="muted">Klargøring af fysiske boot-USB-stik er deaktiveret.</div>' +
      '</div></div>' +
      '<div class="card"><div class="card-header">macOS</div><div class="card-body">' +
        dl('GovernIT_Data_Eraser_Management_' + macVersion + '_arm64.pkg', 'Installer (.pkg) – Apple Silicon', '110 MB') +
        dl('GovernIT_Data_Eraser_Management_' + macVersion + '_arm64.zip', 'Bærbar udgave (.zip) – Apple Silicon', '110 MB') +
        '<div style="font-size:12px;color:var(--muted)">Kræver macOS på Apple Silicon (arm64).</div>' +
      '</div></div>' +
      '<div class="card"><div class="card-header">Linux</div><div class="card-body">' +
        dl('GovernIT_Data_Eraser_Management_' + linuxVersion + '_amd64.deb', 'Debian/Ubuntu (.deb) – x86_64', '111 MB') +
        dl('GovernIT_Data_Eraser_Management_' + linuxVersion + '_amd64.AppImage', 'AppImage (.AppImage) – x86_64', '111 MB') +
        '<div style="font-size:12px;color:var(--muted)">AppImage kræver eksekveringsrettighed: chmod +x, kør derefter filen direkte.</div>' +
      '</div></div>';
    return { root };
  },
};

function stat(label, value) {
  return '<div class="stat-card"><div class="label">' + esc(label) + '</div><div class="value">' + esc(value) + '</div></div>';
}
function statusBadge(active) {
  return '<span class="badge ' + (active ? 'badge-ok' : 'badge-neutral') + '">' + (active ? 'aktiv' : 'inaktiv') + '</span>';
}
function reportBadge(s) {
  const m = { 'ready': 'badge-ok', 'failed': 'badge-bad', 'pending': 'badge-warn' };
  return '<span class="badge ' + (m[s] || 'badge-neutral') + '">' + esc(s) + '</span>';
}
function sessionsTable(sessions) {
  return '<table><thead><tr><th>Status</th><th>Kunde</th><th>Projekt</th><th>Start</th><th>Slut</th></tr></thead><tbody>' +
    sessions.map(s =>
      '<tr><td><span class="badge ' + badgeStatus(s.final_status || 'unknown') + '">' + esc(s.final_status || '') + '</span></td>' +
      '<td>' + escapes(s.customer_name) + '</td><td>' + escapes(s.project_name) + '</td>' +
      '<td>' + fmtDate(s.started_at) + '</td><td>' + fmtDate(s.finished_at) + '</td></tr>').join('') +
    '</tbody></table>';
}

// ---------------------------------------------------------------------------
// FORM HELPERS
// ---------------------------------------------------------------------------
function field(label, inputHtml, hint) {
  return '<div class="field"><label>' + esc(label) + '</label>' + inputHtml + (hint ? '<div style="font-size:11px;color:var(--muted)">' + hint + '</div>' : '') + '</div>';
}
function inputText(val, name, ph) { return '<input type="text" name="' + name + '" value="' + esc(val) + '" placeholder="' + esc(ph || '') + '">'; }
function inputCheck(checked) { return '<input type="checkbox" name="active" ' + (checked ? 'checked' : '') + '>'; }

function formModal(modal, title, formHtml, submitLabel, onSubmit) {
  modal.setTitle(title);
  modal.open(
    '<form id="data-form">' + formHtml +
    '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">' +
    '<button type="button" class="btn btn-secondary" data-cancel>Annull\u00e9r</button>' +
    '<button type="submit" class="btn btn-primary">' + esc(submitLabel) + '</button></div></form>');
  modal.root.querySelector('[data-cancel]').addEventListener('click', modal.close);
  const form = modal.root.querySelector('#data-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    try { await onSubmit(form); modal.close(); } catch (err) { alert('Fejl: ' + err.message); btn.disabled = false; }
  });
}

function userForm(modal, id, refresh) {
  return loadFor('Bruger', async (existing) => {
    let roles = [];
    try { roles = (await API.listRoles()).roles || []; } catch (e) {}
    const roleChips = roles.map(r =>
      '<label class="flex mb-8"><input type="checkbox" name="roles" value="' + esc(r.name) + '" ' +
      ((existing && (existing.roles || []).includes(r.name)) ? 'checked' : '') + '> ' + esc(r.name) + '</label>').join('');
    const isNew = !existing;
    formModal(modal, isNew ? 'Ny bruger' : 'Redig\u00e9r bruger',
      field('Brugernavn', inputText(existing ? existing.username : '', 'username', 'admin')) +
      field('Navn', inputText(existing ? existing.display_name : '', 'display_name')) +
      field('E-mail', inputText(existing ? existing.email || '' : '', 'email')) +
      (isNew ? field('Adgangskode', '<input type="password" name="password" required>') : '') +
      field('Aktiv', inputCheck(existing ? existing.active : true)) +
      '<div class="field"><label>Roller</label>' + (roleChips || 'Ingen roller') + '</div>',
      isNew ? 'Opret' : 'Gem',
      async (form) => {
        const fd = new FormData(form);
        const roleNames = fd.getAll('roles').map(String);
        const data = {
          username: fd.get('username'), display_name: fd.get('display_name'), email: fd.get('email'),
          active: fd.get('active') === 'on', roles: roleNames,
        };
        if (isNew) data.password = fd.get('password');
        if (isNew) await API.createUser(data); else await API.updateUser(id, data);
        refresh();
      });
  }, id);
}

function customerForm(modal, id, refresh) {
  return loadFor('Customer', async (existing) => {
    const c = existing || {};
    formModal(modal, id ? 'Redig\u00e9r kunde' : 'Ny kunde',
      field('Navn', inputText(c.name || '', 'name')) +
      field('Kundenummer', inputText(c.customer_number || '', 'customer_number')) +
      field('Kontaktperson', inputText(c.contact_person || '', 'contact_person')) +
      field('E-mail', inputText(c.contact_email || '', 'contact_email')) +
      field('Telefon', inputText(c.contact_phone || '', 'contact_phone')) +
      field('Adresse', inputText(c.address || '', 'address')) +
      field('Noter', '<textarea name="notes">' + esc(c.notes || '') + '</textarea>') +
      field('Aktiv', inputCheck(c.active !== false)),
      id ? 'Gem' : 'Opret',
      async (form) => {
        const fd = new FormData(form);
        const data = { name: fd.get('name'), customer_number: fd.get('customer_number'), contact_person: fd.get('contact_person'),
          contact_email: fd.get('contact_email'), contact_phone: fd.get('contact_phone'), address: fd.get('address'),
          notes: fd.get('notes'), active: fd.get('active') === 'on' };
        if (id) await API.updateCustomer(id, data); else await API.createCustomer(data);
        refresh();
      });
  }, id);
}

function projectForm(modal, id, refresh, customers, cmap) {
  return loadFor('Project', async (existing) => {
    const p = existing || {};
    const custOpts = customers.map(c => '<option value="' + esc(c.id) + '" ' + ((p.customer_id || '') === c.id ? 'selected' : '') + '>' + esc(c.name) + '</option>').join('');
    formModal(modal, id ? 'Redig\u00e9r projekt' : 'Nyt projekt',
      field('Navn', inputText(p.name || '', 'name')) +
      field('Projektnummer', inputText(p.project_number || '', 'project_number')) +
      field('Kunde', '<select name="customer_id">' + custOpts + '</select>') +
      field('Sagsnr.', inputText(p.case_number || '', 'case_number')) +
      field('Reference', inputText(p.reference || '', 'reference')) +
      field('Ordrenr.', inputText(p.order_number || '', 'order_number')) +
      field('Aktiv', inputCheck(p.status !== 'archived')),
      id ? 'Gem' : 'Opret',
      async (form) => {
        const fd = new FormData(form);
        const data = { name: fd.get('name'), project_number: fd.get('project_number'), customer_id: fd.get('customer_id'),
          case_number: fd.get('case_number'), reference: fd.get('reference'), order_number: fd.get('order_number'),
          status: fd.get('active') === 'on' ? 'active' : 'archived' };
        if (id) await API.updateProject(id, data); else await API.createProject(data);
        refresh();
      });
  }, id);
}

function technicianForm(modal, id, refresh) {
  return loadFor('Technician', async (existing) => {
    const t = existing || {};
    formModal(modal, id ? 'Redig\u00e9r tekniker' : 'Ny tekniker',
      field('Navn', inputText(t.name || '', 'name')) +
      field('Tekniker-id', inputText(t.technician_id || '', 'technician_id')) +
      field('Initialer', inputText(t.initials || '', 'initials')) +
      field('E-mail', inputText(t.email || '', 'email')) +
      field('Organisation', inputText(t.organisation || '', 'organisation')) +
      field('Aktiv', inputCheck(t.active !== false)),
      id ? 'Gem' : 'Opret',
      async (form) => {
        const fd = new FormData(form);
        const data = { name: fd.get('name'), technician_id: fd.get('technician_id'), initials: fd.get('initials'),
          email: fd.get('email'), organisation: fd.get('organisation'), active: fd.get('active') === 'on' };
        if (id) await API.updateTechnician(id, data); else await API.createTechnician(data);
        refresh();
      });
  }, id);
}

function profileForm(modal, id, refresh) {
  return loadFor('Profile', async (existing) => {
    const p = existing || {};
    const methods = ['clear', 'purge', 'cryptographic_erase', 'device_sanitize', 'overwrite', 'custom'];
    const methodOpts = methods.map(m => '<option value="' + m + '" ' + (p.method === m ? 'selected' : '') + '>' + m + '</option>').join('');
    formModal(modal, id ? 'Redig\u00e9r profil' : 'Ny profil',
      field('Navn', inputText(p.name || '', 'name')) +
      field('Version', inputText(p.version || '1', 'version')) +
      field('Metode', '<select name="method">' + methodOpts + '</select>') +
      field('Verificering', '<select name="verification">' + ['readback', 'device_status', 'readback_and_device_status', 'none'].map(v => '<option ' + (p.verification === v ? 'selected' : '') + ' value="' + v + '">' + v + '</option>').join('') + '</select>') +
      field('Krav godkendelse', inputCheck(p.approval_required)) +
      field('Aktiv', inputCheck(p.active !== false)),
      id ? 'Gem' : 'Opret',
      async (form) => {
        const fd = new FormData(form);
        const data = { name: fd.get('name'), version: fd.get('version'), method: fd.get('method'),
          verification: fd.get('verification'), approval_required: fd.get('approval_required') === 'on',
          active: fd.get('active') === 'on', allowed_media_types: p.allowed_media_types || [], standards: p.standards || [] };
        if (id) await API.updateProfile(id, data); else await API.createProfile(data);
        refresh();
      });
  }, id);
}

// Generic loader: resolves the single entity by id (or null for new).
async function loadFor(entity, build, id) {
  let existing = null;
  if (id) {
    // Resolve from the current list endpoint; fall back to a targeted fetch attempt.
    let list = [];
    try {
      const key = { 'Bruger': 'users', 'Customer': 'customers', 'Project': 'projects', 'Technician': 'technicians', 'Profile': 'profiles' }[entity];
      const q = await API.request('GET', '/api/v1/' + (key === 'users' ? 'users' : key));
      list = (q[key] || q) || [];
    } catch (e) {}
    existing = list.find(x => x.id === id) || null;
  }
  await build(existing);
}
