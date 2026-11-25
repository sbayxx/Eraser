// GovernIT Data Eraser Portal – app shell: routing, navigation, login.
// Hash-based routing so it works without server-side URL rewriting.

(function () {
  const loginScreen = document.getElementById('login-screen');
  const appScreen = document.getElementById('app-screen');
  const navEl = document.getElementById('nav');
  const mainEl = document.getElementById('main');
  const loginForm = document.getElementById('login-form');
  const loginError = document.getElementById('login-error');

  const NAV = [
    { group: 'Overblik' },
    { id: 'dashboard', label: 'Dashboard', screen: 'dashboard' },
    { group: 'Administration' },
    { id: 'users', label: 'Brugere', screen: 'users' },
    { id: 'customers', label: 'Kunder', screen: 'customers' },
    { id: 'projects', label: 'Projekter', screen: 'projects' },
    { id: 'technicians', label: 'Teknikere', screen: 'technicians' },
    { id: 'profiles', label: 'Sletteprofiler', screen: 'profiles' },
    { group: 'Rapporter' },
    { id: 'reports', label: 'Rapporter & certifikater', screen: 'reports' },
    { group: 'Support' },
    { id: 'downloads', label: 'Klientprogram', screen: 'downloads' },
  ];

  let currentScreen = null;
  let screenState = null;

  function showLogin() {
    loginScreen.style.display = 'flex';
    appScreen.style.display = 'none';
  }
  function showApp(user) {
    loginScreen.style.display = 'none';
    appScreen.style.display = 'flex';
    renderNav(user);
    document.getElementById('nav-user').textContent = 'Logget ind som: ' + (user && (user.display_name || user.username));
    route();
  }

  function renderNav(user) {
    navEl.innerHTML = '';
    let first = null;
    NAV.forEach(item => {
      if (item.group) {
        const g = document.createElement('div');
        g.className = 'group';
        g.textContent = item.group;
        navEl.appendChild(g);
        return;
      }
      const a = document.createElement('a');
      a.href = '#' + item.screen;
      a.textContent = item.label;
      a.dataset.screen = item.screen;
      if (!first) first = item.screen;
      navEl.appendChild(a);
    });
    if (!location.hash && first) location.hash = first;
  }

  function activeNav(screen) {
    navEl.querySelectorAll('a').forEach(a => a.classList.toggle('active', a.dataset.screen === screen));
  }

  async function route() {
    if (!window.__user__) return;
    const screen = (location.hash || '#dashboard').replace('#', '');
    activeNav(screen);
    if (!Object.hasOwn(Screens, screen)) {
      const message = document.createElement('div');
      message.className = 'empty';
      message.textContent = 'Ukendt side';
      mainEl.replaceChildren(message);
      return;
    }
    if (currentScreen === screen && screenState) return;
    if (screenState) screenState.current?.();
    screenState = Screens[screen]();
    currentScreen = screen;
    const ctx = { user: window.__user__ };
    // Re-bind: replace main content with the screen's root.
    mainEl.innerHTML = '';
    mainEl.appendChild(screenState.root);
  }

  async function boot() {
    // Clear the obsolete browser-readable token from older versions.
    try { localStorage.removeItem('portal_token'); } catch { /* Storage can be disabled. */ }
    try {
      const user = await API.me();
      window.__user__ = user;
      showApp(user);
    } catch {
      showLogin();
    }
  }

  // Login
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginError.style.display = 'none';
    const btn = loginForm.querySelector('button');
    btn.disabled = true;
    try {
      const user = await API.login(document.getElementById('login-user').value.trim(),
        document.getElementById('login-pass').value);
      window.__user__ = user;
      showApp(user);
    } catch (err) {
      loginError.style.display = 'block';
      loginError.textContent = 'Login mislykkedes: ' + (err.unauthorized ? 'forkert brugernavn eller adgangskode' : err.message);
    } finally { btn.disabled = false; }
  });

  // Logout
  document.getElementById('nav-logout').addEventListener('click', async (e) => {
    e.preventDefault();
    try {
      await API.logout();
      window.__user__ = null;
      currentScreen = null;
      screenState = null;
      history.replaceState(null, '', location.pathname + location.search);
      mainEl.replaceChildren();
      showLogin();
    } catch {
      alert('Kunne ikke logge ud. Prøv igen.');
    }
  });

  window.addEventListener('hashchange', () => { currentScreen = null; screenState = null; route(); });

  boot();
})();
