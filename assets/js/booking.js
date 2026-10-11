(function () {
  const list = document.getElementById('vetList');
  if (!list) return;
  const hidden = document.getElementById('bookVetId');
  const slotSelect = document.getElementById('bookTimeSelect');
  const search = document.getElementById('vetSearch');

  async function loadSlots(vetId) {
    const keep = slotSelect.dataset.selected || '';
    slotSelect.innerHTML = '<option value="">Loading available times…</option>';
    slotSelect.disabled = true;
    try {
      const res = await fetch('api/slots.php?vet_id=' + encodeURIComponent(vetId), { headers: { Accept: 'application/json' } });
      const data = await res.json();
      slotSelect.innerHTML = '';
      if (!res.ok || !data.slots || !data.slots.length) {
        slotSelect.innerHTML = '<option value="">' + ((data && data.error) || 'No open times in the next two weeks') + '</option>';
        return;
      }
      data.slots.forEach((s) => {
        const opt = document.createElement('option');
        opt.value = s.value;
        opt.textContent = s.label;
        if (s.value === keep) opt.selected = true;
        slotSelect.appendChild(opt);
      });
    } catch (e) {
      slotSelect.innerHTML = '<option value="">Could not load times — check your connection</option>';
    } finally {
      slotSelect.disabled = false;
      slotSelect.dataset.selected = '';
    }
  }

  function choose(item) {
    list.querySelectorAll('.vet-item').forEach((v) => {
      const on = v === item;
      v.classList.toggle('selected', on);
      v.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    hidden.value = item.dataset.vetId;
    loadSlots(item.dataset.vetId);
  }

  list.addEventListener('click', (e) => {
    const item = e.target.closest('.vet-item');
    if (item) choose(item);
  });
  list.addEventListener('keydown', (e) => {
    const item = e.target.closest('.vet-item');
    if (item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); choose(item); }
  });

  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    list.querySelectorAll('.vet-item').forEach((v) => {
      v.hidden = q !== '' && !v.textContent.toLowerCase().includes(q);
    });
  });

  if (hidden.value && hidden.value !== '0') loadSlots(hidden.value);
  else slotSelect.innerHTML = '<option value="">Choose a vet first</option>';
})();
