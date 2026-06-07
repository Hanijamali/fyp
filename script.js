
// ===== DATA =====
const tutors = [
  {name:'Dr. Lim Wei', subject:'Mathematics', emoji:'👨‍🏫', tags:['SPM','STPM','University'], rate:'RM 80/hr', rating:'4.9', reviews:47, avail:'Weekdays'},
  {name:'Ms. Nur Anis', subject:'Physics', emoji:'👩‍🔬', tags:['SPM','STPM'], rate:'RM 75/hr', rating:'4.8', reviews:38, avail:'Weekends'},
  {name:'Mr. Rajesh Kumar', subject:'Chemistry', emoji:'🧑‍🔬', tags:['SPM','IGCSE'], rate:'RM 70/hr', rating:'4.6', reviews:29, avail:'Evenings'},
  {name:'Ms. Priya Nair', subject:'Biology', emoji:'👩‍🏫', tags:['SPM','STPM'], rate:'RM 65/hr', rating:'4.8', reviews:52, avail:'Weekdays'},
  {name:'Mr. Azrul Hizam', subject:'English Language', emoji:'👨‍💼', tags:['IELTS','SPM','Conversation'], rate:'RM 60/hr', rating:'4.7', reviews:61, avail:'Evenings'},
  {name:'Dr. Sarah Lim', subject:'Computer Science', emoji:'👩‍💻', tags:['Python','Java','Web Dev'], rate:'RM 90/hr', rating:'4.9', reviews:33, avail:'Weekdays'},
  {name:'Ms. Farah Diyana', subject:'Bahasa Malaysia', emoji:'👩‍🎓', tags:['SPM','Penulisan'], rate:'RM 55/hr', rating:'4.5', reviews:22, avail:'Weekends'},
  {name:'Mr. Chong Wei Lin', subject:'Additional Mathematics', emoji:'🧑‍🏫', tags:['SPM','Add Maths'], rate:'RM 80/hr', rating:'4.7', reviews:44, avail:'Evenings'},
];

let currentChipFilter = '';
let currentUser = null;

// ===== PAGE NAVIGATION =====
function showPage(id) {
  const pages = {
    home: 'index.html',
    login: 'login.html',
    signup: 'signup.html',
    search: 'search.php',
    'student-dash': 'student-dashboard.php',
    'tutor-dash': 'tutor-dashboard.php',
    'parent-dash': 'parent-dashboard.php',
    book: 'book-lesson.php',
    'admin-dash': 'admin-dashboard.php'
  };
  window.location.href = pages[id] || 'index.html';
}

function showSignup(role) {
  localStorage.setItem('signupRole', role);
  showPage('signup');
}

// ===== LOGIN =====
function doLogin() {
  const email = document.getElementById('login-email')?.value.trim();
  const pass = document.getElementById('login-password')?.value;
  const alert = document.getElementById('login-alert');

  if (!email || !pass) {
    showAlert(alert, 'error', 'Please enter your email and password.');
    return;
  }

  if (!email.includes('@')) {
    showAlert(alert, 'error', 'Please enter a valid email address.');
    return;
  }

  let role = 'student';

  if (email.toLowerCase().includes('tutor')) {
    role = 'tutor';
  } else if (email.toLowerCase().includes('parent')) {
    role = 'parent';
  } else if (email.toLowerCase().includes('admin')) {
    role = 'admin';
  }

  localStorage.setItem('userEmail', email);
  localStorage.setItem('userRole', role);

  showAlert(alert, 'success', '✅ Login successful! Redirecting...');

  setTimeout(() => {
    if (role === 'student') showPage('student-dash');
    else if (role === 'parent') showPage('parent-dash');
    else if (role === 'tutor') showPage('tutor-dash');
    else if (role === 'admin') showPage('admin-dash');
  }, 900);
}

// ===== SIGNUP =====
function doSignup() {
  const alert = document.getElementById('signup-alert');
  const firstName = document.getElementById('signup-first-name')?.value.trim();
  const lastName = document.getElementById('signup-last-name')?.value.trim();
  const email = document.getElementById('signup-email')?.value.trim();
  const password = document.getElementById('signup-password')?.value;
  const confirmPassword = document.getElementById('signup-confirm-password')?.value;
  const role = document.querySelector('input[name="role"]:checked')?.value;

  if (!firstName || !lastName || !email || !password || !confirmPassword) {
    showAlert(alert, 'error', 'Please complete all required fields.');
    return;
  }

  if (!email.includes('@')) {
    showAlert(alert, 'error', 'Please enter a valid email address.');
    return;
  }

  if (password.length < 8) {
    showAlert(alert, 'error', 'Password must be at least 8 characters.');
    return;
  }

  if (password !== confirmPassword) {
    showAlert(alert, 'error', 'Password and confirm password do not match.');
    return;
  }

  showAlert(alert, 'success', '✅ Signup form validated. Submitting...');
  setTimeout(() => {
    if (role === 'tutor') showPage('tutor-dash');
    else if (role === 'parent') showPage('parent-dash');
    else showPage('student-dash');
  }, 900);
}

// Role toggle for signup
document.querySelectorAll('input[name="role"]').forEach(r => {
  r.addEventListener('change', function() {
    document.getElementById('tutor-extra').style.display = this.value === 'tutor' ? 'block' : 'none';
    document.getElementById('parent-extra').style.display = this.value === 'parent' ? 'block' : 'none';
  });
});

// ===== TUTOR SEARCH =====
function renderTutors() {
  const grid = document.getElementById('tutor-grid');
  if (!grid) return;
  const q = (document.getElementById('search-input')?.value || '').toLowerCase();
  const sub = document.getElementById('search-subject')?.value || '';
  let data = tutors.filter(t => {
    const matchQ = !q || t.name.toLowerCase().includes(q) || t.subject.toLowerCase().includes(q);
    const matchS = !sub || t.subject === sub;
    const matchC = !currentChipFilter || t.subject === currentChipFilter;
    return matchQ && matchS && matchC;
  });
  grid.innerHTML = data.map(t => `
    <div class="glass tutor-card" onclick="bookTutor('${t.name}','${t.subject}','${t.rate}','${t.emoji}')">
      <div class="tutor-avatar">${t.emoji}</div>
      <div class="tutor-name">${t.name}</div>
      <div class="tutor-subject">${t.subject}</div>
      <div class="tutor-tags">${t.tags.map(x=>`<span class="tag">${x}</span>`).join('')}</div>
      <div class="tutor-meta">
        <div><div class="stars">${'★'.repeat(Math.floor(t.rating))} ${t.rating}</div><div style="font-size:0.75rem;opacity:0.6;">${t.reviews} reviews</div></div>
        <div class="tutor-rate">${t.rate}</div>
      </div>
      <button class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:0.8rem;">Book Lesson</button>
    </div>
  `).join('') || '<div style="color:rgba(255,255,255,0.5);grid-column:1/-1;text-align:center;padding:2rem;">No tutors found for this search.</div>';
}

function filterTutors() { renderTutors(); }

function chipFilter(el, val) {
  document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  currentChipFilter = val;
  renderTutors();
}

function bookTutor(name, subject, rate, emoji) {
  localStorage.setItem('selectedTutor', JSON.stringify({name, subject, rate, emoji}));
  showPage('book');
}

function confirmBooking() {
  const alert = document.getElementById('book-alert');
  const date = document.getElementById('book-date').value;
  if (!date) {
    showAlert(alert, 'error', 'Please select a date.');
    return;
  }
  openModal('book-success-modal');
}

// ===== DASHBOARD TABS =====
function switchTab(id) {
  ['s-overview','s-lessons','s-progress','s-tools','s-feedback'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('#page-student-dash .menu-item').forEach(m => m.classList.remove('active'));
  const el = document.getElementById(id);
  if (el) el.style.display = 'flex', el.style.flexDirection = 'column', el.style.display = 'block';
  event.currentTarget.classList.add('active');
}

function switchTutorTab(id) {
  ['t-overview','t-requests','t-availability','t-analytics','t-profile','t-payment'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('#page-tutor-dash .menu-item').forEach(m => m.classList.remove('active'));
  const el = document.getElementById(id);
  if (el) el.style.display = 'block';
  event.currentTarget.classList.add('active');
}

function switchParentTab(id) {
  ['p-overview','p-progress','p-budget','p-approve'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('#page-parent-dash .menu-item').forEach(m => m.classList.remove('active'));
  const el = document.getElementById(id);
  if (el) el.style.display = 'block';
  event.currentTarget.classList.add('active');
}

function lessonTab(btn, id) {
  ['upcoming-lessons','history-lessons'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  btn.closest('.tab-wrap').querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(id).style.display = 'block';
}

function adminTab(btn, id) {
  ['a-users','a-tutors','a-feedback','a-dispute','a-analytics'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('#page-admin-dash .tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(id).style.display = 'block';
}

// ===== FEEDBACK =====
function setStars(n) {
  document.querySelectorAll('.star-rating span').forEach((s, i) => {
    s.classList.toggle('active', i < n);
  });
}

function submitFeedback() {
  document.getElementById('feedback-success').style.display = 'block';
  setTimeout(() => document.getElementById('feedback-success').style.display = 'none', 3000);
}

// ===== TUTOR ACTIONS =====
function acceptRequest(btn) {
  const row = btn.closest('tr');
  row.querySelector('td:nth-last-child(2)').innerHTML = '<span class="status-badge status-confirmed">Accepted</span>';
  row.querySelector('td:last-child').innerHTML = '—';
}

function rejectRequest(btn) {
  btn.closest('tr').style.opacity = '0.4';
  btn.closest('tr').querySelector('td:last-child').innerHTML = '<span style="color:#e05c5c;font-size:0.85rem;">Rejected</span>';
}

function approveTutor(rowId, alertId) {
  document.getElementById(rowId).style.opacity = '0.4';
  showAlert(document.getElementById(alertId), 'success', '✅ Tutor approved successfully!');
}

function rejectTutor(rowId, alertId) {
  document.getElementById(rowId).style.opacity = '0.4';
  showAlert(document.getElementById(alertId), 'error', 'Tutor recommendation rejected.');
}

// ===== AVAILABILITY =====
function toggleSlot(el) {
  el.classList.toggle('taken');
  el.classList.toggle('open');
}

// ===== MODALS =====
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

// ===== ALERTS =====
function showAlert(el, type, msg) {
  el.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML = '', 3000);
}

// ===== INIT =====
renderTutors();


// ===== MULTI-PAGE INIT =====
document.addEventListener('DOMContentLoaded', function () {
  renderTutors();

  const savedRole = localStorage.getItem('signupRole');
  if (savedRole) {
    const r = document.getElementById('r-' + savedRole);
    if (r) {
      r.checked = true;
      r.dispatchEvent(new Event('change'));
      localStorage.removeItem('signupRole');
    }
  }

  const selectedTutor = localStorage.getItem('selectedTutor');
  if (selectedTutor) {
    try {
      const t = JSON.parse(selectedTutor);
      if (document.getElementById('book-name')) document.getElementById('book-name').textContent = t.name;
      if (document.getElementById('book-subject')) document.getElementById('book-subject').textContent = t.subject;
      if (document.getElementById('book-rate')) document.getElementById('book-rate').textContent = t.rate;
      if (document.getElementById('book-avatar')) document.getElementById('book-avatar').textContent = t.emoji;
      if (document.getElementById('calc-rate')) document.getElementById('calc-rate').textContent = t.rate;
    } catch (e) {}
  }
});
