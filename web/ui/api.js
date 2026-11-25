// API helper for the GovernIT Data Eraser Portal.
// The server keeps the browser session in an HttpOnly cookie.

const API = {
  async fetch(path, options = {}) {
    return fetch(path, {
      ...options,
      mode: 'same-origin',
      credentials: 'same-origin',
      headers: { ...options.headers, 'X-Eraser-Portal': '1' },
    });
  },

  async request(method, path, body) {
    const headers = {};
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const res = await API.fetch(path, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });
    let data = null;
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('json')) {
      try { data = await res.json(); } catch (e) { data = null; }
    }
    if (!res.ok) {
      const msg = data && data.error ? data.error : ('HTTP ' + res.status);
      const err = new Error(msg);
      err.status = res.status;
      if (res.status === 401) err.unauthorized = true;
      throw err;
    }
    return data;
  },

  async login(username, password) {
    const r = await API.request('POST', '/api/v1/auth/login', { username, password });
    return r.user;
  },

  async me() { return (await API.request('GET', '/api/v1/auth/me')).user; },
  logout: () => API.request('POST', '/api/v1/auth/logout'),

  // Master data
  getOrg: () => API.request('GET', '/api/v1/organisation'),
  putOrg: (d) => API.request('PUT', '/api/v1/organisation', d),
  listUsers: () => API.request('GET', '/api/v1/users'),
  createUser: (d) => API.request('POST', '/api/v1/users', d),
  updateUser: (id, d) => API.request('PUT', '/api/v1/users/' + id, d),
  listRoles: () => API.request('GET', '/api/v1/roles'),
  listCustomers: () => API.request('GET', '/api/v1/customers'),
  createCustomer: (d) => API.request('POST', '/api/v1/customers', d),
  updateCustomer: (id, d) => API.request('PUT', '/api/v1/customers/' + id, d),
  deactivateCustomer: (id) => API.request('DELETE', '/api/v1/customers/' + id),
  listProjects: () => API.request('GET', '/api/v1/projects'),
  createProject: (d) => API.request('POST', '/api/v1/projects', d),
  updateProject: (id, d) => API.request('PUT', '/api/v1/projects/' + id, d),
  deleteProject: (id) => API.request('DELETE', '/api/v1/projects/' + id),
  listTechnicians: () => API.request('GET', '/api/v1/technicians'),
  createTechnician: (d) => API.request('POST', '/api/v1/technicians', d),
  updateTechnician: (id, d) => API.request('PUT', '/api/v1/technicians/' + id, d),
  deleteTechnician: (id) => API.request('DELETE', '/api/v1/technicians/' + id),
  listProfiles: () => API.request('GET', '/api/v1/profiles'),
  createProfile: (d) => API.request('POST', '/api/v1/profiles', d),
  updateProfile: (id, d) => API.request('PUT', '/api/v1/profiles/' + id, d),
  deleteProfile: (id) => API.request('DELETE', '/api/v1/profiles/' + id),

  // Reports & certificates
  querySessions: () => API.request('GET', '/api/v1/query/sessions'),
  listReports: () => API.request('GET', '/api/v1/reports'),
  generateReport: (d) => API.request('POST', '/api/v1/reports/generate', d || {}),
  async downloadReport(id) {
    const response = await API.fetch('/api/v1/reports/' + encodeURIComponent(id) + '/pdf');
    if (!response.ok || !response.headers.get('Content-Type')?.startsWith('application/pdf')) {
      throw new Error('Rapporten kunne ikke hentes.');
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = 'certifikatrapport.pdf';
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  },
};
