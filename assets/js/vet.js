(function () {
  document.querySelectorAll('[data-record-tab]').forEach((tabBtn) => {
    tabBtn.addEventListener('click', () => {
      const target = tabBtn.getAttribute('data-record-tab');
      document.querySelectorAll('[data-record-tab]').forEach((btn) => {
        const active = btn === tabBtn;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.getElementById('dueRecords').hidden = target !== 'due';
      document.getElementById('historyRecords').hidden = target !== 'history';
    });
  });

  const form = document.getElementById('checkupForm');
  if (!form) return;

  function renumber(list) {
    list.querySelectorAll(':scope > div').forEach((row, i) => {
      row.querySelectorAll('[name]').forEach((el) => {
        el.name = el.name.replace(/^(\w+)\[\d+\]/, '$1[' + i + ']');
      });
    });
  }

  function addRow(list) {
    const first = list.querySelector(':scope > div');
    const row = first.cloneNode(true);
    row.querySelectorAll('input, textarea').forEach((el) => { el.value = ''; el.classList.remove('is-invalid'); });
    row.querySelectorAll('select').forEach((el) => { el.selectedIndex = 0; });
    row.querySelectorAll('.field-error').forEach((el) => el.remove());
    list.appendChild(row);
    renumber(list);
    const firstInput = row.querySelector('input');
    if (firstInput) firstInput.focus();
  }

  function wireList(listId, addId) {
    const list = document.getElementById(listId);
    const add = document.getElementById(addId);
    if (!list || !add) return;
    add.addEventListener('click', () => addRow(list));
    list.addEventListener('click', (e) => {
      if (!e.target.matches('[data-remove-row]')) return;
      const rows = list.querySelectorAll(':scope > div');
      const row = e.target.closest('.medicine-row, .vaccine-form');
      if (rows.length > 1) {
        row.remove();
        renumber(list);
      } else {
        row.querySelectorAll('input, textarea').forEach((el) => { el.value = ''; });
      }
    });
  }
  wireList('medicineList', 'addMedicine');
  wireList('vaccineList', 'addVaccine');

  const noMeds = document.getElementById('noMeds');
  function syncMeds() {
    const off = noMeds.checked;
    document.querySelectorAll('#medicineList input, #medicineList button, #addMedicine').forEach((el) => { el.disabled = off; });
    document.getElementById('medicineList').style.opacity = off ? 0.5 : 1;
  }
  if (noMeds) {
    noMeds.addEventListener('change', syncMeds);
    syncMeds();
  }

  const choice = document.getElementById('vaccineChoice');
  const section = document.getElementById('vaccineSection');
  document.querySelectorAll('[data-vaccine-choice]').forEach((btn) => {
    btn.addEventListener('click', () => {
      choice.value = btn.dataset.vaccineChoice;
      document.querySelectorAll('[data-vaccine-choice]').forEach((b) => {
        const on = b === btn;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      section.hidden = choice.value === 'none';
    });
  });
})();
