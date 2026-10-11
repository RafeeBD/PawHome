(function () {
  const log = document.getElementById('chatLog');
  if (!log) return;
  const head = document.getElementById('chatPanelHead');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const sendBtn = form.querySelector('button');
  const list = document.getElementById('contactList');
  const search = document.getElementById('msgSearch');
  const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  let active = Number(log.dataset.active) || 0;
  let lastSig = '';

  function render(data) {
    const sig = data.messages.map((m) => m.id).join(',');
    if (sig === lastSig) return;
    const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
    lastSig = sig;
    log.innerHTML = '';
    if (!data.messages.length) {
      const p = document.createElement('p');
      p.className = 'empty-note';
      p.textContent = 'No messages yet — say hello to ' + data.name + '!';
      log.appendChild(p);
    }
    data.messages.forEach((m) => {
      const div = document.createElement('div');
      div.className = 'msg ' + (m.mine ? 'me' : 'them');
      div.textContent = m.body;
      div.title = m.time;
      log.appendChild(div);
    });
    if (nearBottom || !log.dataset.loaded) log.scrollTop = log.scrollHeight;
    log.dataset.loaded = '1';
  }

  async function load() {
    if (!active) return;
    try {
      const res = await fetch('api/messages.php?with=' + active, { headers: { Accept: 'application/json' } });
      if (!res.ok) return;
      render(await res.json());
      const item = list.querySelector('.contact-item[data-user-id="' + active + '"] .unread-dot');
      if (item) item.remove();
    } catch (e) {  }
  }

  function select(item) {
    list.querySelectorAll('.contact-item').forEach((c) => c.classList.toggle('active', c === item));
    active = Number(item.dataset.userId);
    head.textContent = item.dataset.name;
    input.disabled = false;
    sendBtn.disabled = false;
    lastSig = '';
    delete log.dataset.loaded;
    log.innerHTML = '';
    load();
    input.focus({ preventScroll: true });
  }

  list.addEventListener('click', (e) => {
    const item = e.target.closest('.contact-item');
    if (item) select(item);
  });
  list.addEventListener('keydown', (e) => {
    const item = e.target.closest('.contact-item');
    if (item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); select(item); }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = input.value.trim();
    if (!body || !active) return;
    sendBtn.disabled = true;
    const fd = new FormData();
    fd.append('with', active);
    fd.append('body', body);
    fd.append('csrf', csrf);
    try {
      const res = await fetch('api/messages.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' } });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data) {
        alert((data && data.error) || 'Message could not be sent. Please refresh and try again.');
        return;
      }
      input.value = '';
      render(data);
      log.scrollTop = log.scrollHeight;
      const item = list.querySelector('.contact-item[data-user-id="' + active + '"]');
      if (item) {
        item.querySelector('.contact-preview').textContent = 'You: ' + body;
        item.querySelector('.contact-time').textContent = 'now';
        list.prepend(item);
      }
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  });

  if (search) {
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      list.querySelectorAll('.contact-item').forEach((c) => {
        c.hidden = q !== '' && !c.dataset.name.toLowerCase().includes(q);
      });
    });
  }

  load();
  setInterval(() => { if (!document.hidden) load(); }, 5000);
})();
