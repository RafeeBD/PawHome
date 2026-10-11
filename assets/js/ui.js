(function () {
  document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type=file]');
    if (!input) return;
    const label = zone.querySelector('.dz-main, [data-dz-label]');
    const original = label ? label.textContent : '';

    function show(files) {
      if (!files || !files.length) {
        if (label) label.textContent = original;
        return;
      }
      if (label) label.textContent = files.length === 1 ? files[0].name : files.length + ' files selected';
      if (zone.dataset.dropzone === 'photo' && files[0].type.startsWith('image/')) {
        zone.style.backgroundImage = 'url(' + URL.createObjectURL(files[0]) + ')';
        zone.style.backgroundSize = 'cover';
        zone.style.backgroundPosition = 'center';
        zone.classList.add('has-photo');
      }
    }

    zone.addEventListener('click', (e) => {
      if (e.target === input) return;
      e.preventDefault();
      input.click();
    });
    zone.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', () => show(input.files));
    ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => {
      e.preventDefault();
      zone.style.borderColor = '#C1663C';
    }));
    ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, () => { zone.style.borderColor = ''; }));
    zone.addEventListener('drop', (e) => {
      e.preventDefault();
      if (!e.dataTransfer.files.length) return;
      input.files = e.dataTransfer.files;
      show(input.files);
    });
  });

  function close(backdrop) {
    backdrop.classList.remove('open');
    if (location.hash === '#' + backdrop.id) {
      const y = window.scrollY;
      location.hash = '';
      window.scrollTo(0, y);
    }
  }
  document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href^="#"]');
    const linkHref = link ? link.getAttribute('href') : '';
    if (link && linkHref.length > 1 && !link.hasAttribute('data-close')) {
      const from = link.closest('.modal-backdrop');
      if (from) from.classList.remove('open');
      return;
    }
    const closer = e.target.closest('.modal-close, .icon-btn, [data-close]');
    const bd = closer ? closer.closest('.modal-backdrop') : null;
    if (bd) {
      e.preventDefault();
      close(bd);
      return;
    }
    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
      close(e.target);
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal-backdrop').forEach((bd) => {
      if (bd.classList.contains('open') || location.hash === '#' + bd.id) close(bd);
    });
  });

  document.addEventListener('submit', (e) => {
    const btn = e.submitter;
    const msg = (btn && btn.dataset.confirm) || e.target.dataset.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
})();
