(function () {
  const cards = Array.from(document.querySelectorAll('#petGrid .pet-card'));
  const count = document.getElementById('resultCount');
  const empty = document.getElementById('emptyNote');
  const minIn = document.getElementById('ageMin');
  const maxIn = document.getElementById('ageMax');
  const fill = document.getElementById('ageFill');
  const readout = document.getElementById('ageReadout');
  const maxYears = Number(minIn.max);

  const filters = { category: 'All', size: 'All' };
  document.querySelectorAll('.pill-row[data-filter]').forEach((row) => {
    const active = row.querySelector('.pill.active');
    if (active) filters[row.dataset.filter] = active.dataset.value;
  });

  const fmt = (y) => (y === 0 ? '0' : (Number.isInteger(y) ? String(y) : y.toFixed(1)));

  function syncSlider() {
    let lo = Number(minIn.value);
    let hi = Number(maxIn.value);
    if (lo > hi) {
      if (document.activeElement === minIn) { minIn.value = hi; lo = hi; } else { maxIn.value = lo; hi = lo; }
    }
    fill.style.left = (lo / maxYears) * 100 + '%';
    fill.style.right = (100 - (hi / maxYears) * 100) + '%';
    readout.textContent = fmt(lo) + ' – ' + fmt(hi) + ' yrs';
    minIn.style.zIndex = lo >= maxYears - 0.25 ? 3 : 1;
    return [lo, hi];
  }

  function apply() {
    const [lo, hi] = syncSlider();
    let shown = 0;
    cards.forEach((card) => {
      const months = Number(card.dataset.age);
      const years = months < 12 ? months / 12 : Math.floor(months / 12);
      const ok = (filters.category === 'All' || card.dataset.category === filters.category)
        && (filters.size === 'All' || card.dataset.size === filters.size)
        && years >= lo && years <= hi;
      card.hidden = !ok;
      if (ok) shown++;
    });
    count.textContent = shown + (shown === 1 ? ' pet available' : ' pets available');
    empty.hidden = shown !== 0;
  }

  document.querySelectorAll('.pill-row[data-filter]').forEach((row) => {
    const choose = (pill) => {
      row.querySelectorAll('.pill').forEach((p) => p.classList.toggle('active', p === pill));
      filters[row.dataset.filter] = pill.dataset.value;
      apply();
    };
    row.addEventListener('click', (e) => {
      const pill = e.target.closest('.pill');
      if (pill) choose(pill);
    });
    row.addEventListener('keydown', (e) => {
      const pill = e.target.closest('.pill');
      if (pill && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); choose(pill); }
    });
  });

  minIn.addEventListener('input', apply);
  maxIn.addEventListener('input', apply);
  apply();
})();
