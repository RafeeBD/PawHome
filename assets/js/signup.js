(function () {
  const form = document.getElementById('signupForm');
  if (!form) return;

  const MAX_FILES = 5;
  const MAX_BYTES = 5 * 1024 * 1024;
  const OK_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
  const personName = /^[\p{L}][\p{L}\s.'\-]{1,99}$/u;
  const shelterName = /^[\p{L}\p{N}][\p{L}\p{N}\s.,'&\-()]{1,99}$/u;
  const code = /^[A-Za-z0-9][A-Za-z0-9\-\/]{2,49}$/;
  const email = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  const role = () => (form.querySelector('input[name="role"]:checked') || {}).value || 'adopter';
  const val = (id) => (document.getElementById(id).value || '').trim();

  const rules = {
    name: () => {
      const v = val('name');
      if (!v) return role() === 'shelter' ? 'Shelter name is required.' : 'Full name is required.';
      if (role() === 'shelter') return shelterName.test(v) ? '' : "Shelter name must be 2–100 characters (letters, numbers, spaces and . , ' & - ( ) only).";
      return personName.test(v) ? '' : 'Name must be 2–100 characters and contain only letters, spaces, dots, apostrophes or hyphens.';
    },
    email: () => {
      const v = val('email');
      if (!v) return 'Email is required.';
      return email.test(v) ? '' : 'Enter a valid email address, e.g. name@example.com.';
    },
    phone: () => {
      const v = val('phone');
      if (!v) return 'Phone number is required.';
      const digits = v.replace(/\D/g, '').length;
      return /^\+?[0-9\s\-()]{7,20}$/.test(v) && digits >= 7 && digits <= 15 ? '' : 'Enter a valid phone number (7–15 digits; +, spaces, dashes and brackets allowed).';
    },
    password: () => {
      const v = document.getElementById('password').value;
      if (!v) return 'Password is required.';
      if (v.length < 8) return 'Password must be at least 8 characters.';
      if (!/[A-Za-z]/.test(v)) return 'Password must include at least one letter.';
      if (!/[0-9]/.test(v)) return 'Password must include at least one number.';
      return '';
    },
    confirm_password: () => {
      const v = document.getElementById('confirm_password').value;
      if (!v) return 'Please confirm your password.';
      return v === document.getElementById('password').value ? '' : 'Passwords do not match.';
    },
    contact_person: () => personName.test(val('contact_person')) ? '' : 'Enter the full name of the contact person (letters, spaces, dots, apostrophes or hyphens).',
    registration_number: () => code.test(val('registration_number')) ? '' : 'Registration number must be 3–50 characters: letters, numbers, - or /.',
    address: () => val('address').length >= 5 ? '' : "Enter the shelter's full address.",
    description: () => val('description').length >= 20 ? '' : 'Tell us about the shelter in at least 20 characters.',
    clinic: () => val('clinic').length >= 2 ? '' : 'Clinic or workplace is required.',
    licence_number: () => code.test(val('licence_number')) ? '' : 'Licence number must be 3–50 characters: letters, numbers, - or /.',
    qualification: () => val('qualification').length >= 2 ? '' : 'Qualification is required (e.g. DVM, university).',
    specialization: () => val('specialization').length >= 2 ? '' : 'Specialization is required.',
    years_experience: () => {
      const v = val('years_experience');
      return /^\d{1,2}$/.test(v) && Number(v) <= 60 ? '' : 'Years of experience must be a whole number between 0 and 60.';
    },
    bio: () => val('bio').length >= 20 ? '' : 'Write a short professional bio of at least 20 characters.',
    documents: () => {
      const files = Array.from(document.getElementById('documents').files);
      if (!files.length) return 'Upload at least one verification document.';
      if (files.length > MAX_FILES) return 'You can upload up to 5 documents.';
      for (const f of files) {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!OK_EXT.includes(ext)) return '"' + f.name + '" must be a PDF, JPG, PNG or WEBP file.';
        if (f.size > MAX_BYTES) return '"' + f.name + '" is larger than 5 MB.';
      }
      return '';
    },
  };

  const roleFields = {
    adopter: [],
    shelter: ['contact_person', 'registration_number', 'address', 'description', 'documents'],
    vet: ['clinic', 'licence_number', 'qualification', 'specialization', 'years_experience', 'bio', 'documents'],
  };
  const commonFields = ['name', 'email', 'phone', 'password', 'confirm_password'];

  function showError(field, message) {
    const input = document.getElementById(field);
    const anchor = field === 'documents' ? document.getElementById('fileList') : input;
    const target = field === 'documents' ? document.getElementById('docDrop') : input;
    let span = document.getElementById('err-' + field);
    if (message) {
      if (!span) {
        span = document.createElement('span');
        span.className = 'field-error';
        span.id = 'err-' + field;
        anchor.insertAdjacentElement('afterend', span);
      }
      span.textContent = message;
      target.classList.add('is-invalid');
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', span.id);
    } else {
      if (span) span.remove();
      target.classList.remove('is-invalid');
      input.removeAttribute('aria-invalid');
    }
  }

  function check(field) {
    const message = rules[field]();
    showError(field, message);
    return !message;
  }

  function syncRole() {
    const r = role();
    form.querySelectorAll('fieldset[data-role]').forEach((fs) => {
      const active = fs.dataset.role.split(' ').includes(r);
      fs.disabled = !active;
      if (!active) {
        fs.querySelectorAll('[id]').forEach((el) => { if (rules[el.id]) showError(el.id, ''); });
      }
    });
  }

  form.querySelectorAll('input[name="role"]').forEach((r) => r.addEventListener('change', () => {
    syncRole();
    if (document.getElementById('err-name')) check('name');
  }));
  syncRole();

  Object.keys(rules).forEach((field) => {
    const el = document.getElementById(field);
    if (!el || field === 'documents') return;
    el.addEventListener('blur', () => { if (el.value !== '' || document.getElementById('err-' + field)) check(field); });
    el.addEventListener('input', () => { if (document.getElementById('err-' + field)) check(field); });
  });
  document.getElementById('password').addEventListener('input', () => {
    if (document.getElementById('confirm_password').value) check('confirm_password');
  });

  const docInput = document.getElementById('documents');
  const docDrop = document.getElementById('docDrop');
  const fileList = document.getElementById('fileList');
  function listFiles() {
    fileList.innerHTML = '';
    Array.from(docInput.files).forEach((f) => {
      const li = document.createElement('li');
      li.textContent = '📄 ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
      fileList.appendChild(li);
    });
    check('documents');
  }
  docInput.addEventListener('change', listFiles);
  ['dragenter', 'dragover'].forEach((ev) => docDrop.addEventListener(ev, (e) => { e.preventDefault(); docDrop.style.borderColor = '#E07B3D'; }));
  ['dragleave', 'drop'].forEach((ev) => docDrop.addEventListener(ev, () => { docDrop.style.borderColor = ''; }));
  docDrop.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer.files.length) {
      docInput.files = e.dataTransfer.files;
      listFiles();
    }
  });

  form.addEventListener('submit', (e) => {
    const fields = commonFields.concat(roleFields[role()] || []);
    const bad = fields.filter((f) => !check(f));
    if (bad.length) {
      e.preventDefault();
      const first = document.getElementById(bad[0]);
      (bad[0] === 'documents' ? docDrop : first).scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (bad[0] !== 'documents') first.focus({ preventScroll: true });
    }
  });
})();
