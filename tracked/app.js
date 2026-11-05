/**
 * TrackED v2.0 — app.js
 * SPA Navigation + Schedule Manager + Irregular Students + Live Feed
 */
"use strict";

const App = {
    currentSection: 'dashboard',
    liveInterval: null,
    autoRefresh: true,
    allStudents: [],
    allSchedules: [],
    reportData: [],
    deleteTargetId: null,
    deleteTargetName: null,
    regHistory: [],
    todayDay: new Date().toLocaleDateString('en-US', { weekday: 'long' }),
};

/* ═══════════════════════════
   INIT
═══════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    initNav();
    initClock();
    initSidebarToggle();
    initRegistrationForm();
    initPhotoUpload();
    initModals();
    initReports();
    initRecordsFilters();
    initScheduleForm();

    // Initial loads
    fetchLiveScans();
    fetchTodaySchedules();
    checkConnection();
    startAutoRefresh();

    // Demo: simulate invalid RFID after 10s
});

/* ═══════════════════════════
   NAVIGATION
═══════════════════════════ */
function initNav() {
    const titles = {
        dashboard:'Dashboard', registration:'Student Registration',
        records:'Student Records', reports:'Attendance Reports',
        schedules:'Schedule Manager', settings:'Settings',
    };
    document.querySelectorAll('.nav-item[data-section]').forEach(item => {
        item.addEventListener('click', e => {
            e.preventDefault();
            const sec = item.dataset.section;
            if (sec === App.currentSection) return;
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            item.classList.add('active');
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.getElementById(`section-${sec}`)?.classList.add('active');
            document.getElementById('page-title').textContent = titles[sec] || sec;
            App.currentSection = sec;
            onSectionEnter(sec);
            if (window.innerWidth <= 768) document.body.classList.remove('sidebar-open');
        });
    });
}

function onSectionEnter(sec) {
    if (sec === 'dashboard')  { fetchLiveScans(); fetchTodaySchedules(); }
    if (sec === 'records')    { fetchStudents(); }
    if (sec === 'schedules')  { fetchSchedules(); fetchIrregularStudents(); }
    if (sec === 'registration') {
        setTimeout(() => document.getElementById('rfid_uid')?.focus(), 100);
    }
}

/* ═══════════════════════════
   CLOCK
═══════════════════════════ */
function initClock() {
    const clockEl = document.getElementById('topbar-clock');
    const dateEl  = document.getElementById('topbar-date');
    function tick() {
        const n = new Date();
        const h = (n.getHours()%12||12).toString().padStart(2,'0');
        const m = n.getMinutes().toString().padStart(2,'0');
        const s = n.getSeconds().toString().padStart(2,'0');
        const ap = n.getHours()>=12?'PM':'AM';
        if (clockEl) clockEl.textContent = `${h}:${m}:${s} ${ap}`;
        if (dateEl) {
            const days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
            dateEl.textContent = `${days[n.getDay()]}, ${months[n.getMonth()]} ${n.getDate()}, ${n.getFullYear()}`;
        }
    }
    tick(); setInterval(tick, 1000);
}

/* ═══════════════════════════
   SIDEBAR TOGGLE
═══════════════════════════ */
function initSidebarToggle() {
    document.getElementById('menu-toggle')?.addEventListener('click', () => {
        if (window.innerWidth <= 768) document.body.classList.toggle('sidebar-open');
        else document.body.classList.toggle('sidebar-collapsed');
    });
    document.body.addEventListener('click', e => {
        if (window.innerWidth <= 768 && document.body.classList.contains('sidebar-open'))
            if (!e.target.closest('.sidebar') && !e.target.closest('#menu-toggle'))
                document.body.classList.remove('sidebar-open');
    });
}

/* ═══════════════════════════
   LIVE SCAN FEED
═══════════════════════════ */
function fetchLiveScans() {
    const tbody = document.getElementById('live-tbody');
    const badge = document.getElementById('last-refresh');
    const btn   = document.getElementById('refresh-btn');
    if (btn) btn.classList.add('spinning');

    fetch('index.php?action=get_live_scans')
        .then(r => r.json())
        .then(data => {
            renderLiveTable(data, tbody);
            if (badge) badge.textContent = `Updated ${fmtTime(new Date())}`;
        })
        .catch(() => {
            if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><p>Could not load live feed.</p></td></tr>`;
        })
        .finally(() => { if (btn) btn.classList.remove('spinning'); });
}

function renderLiveTable(scans, tbody) {
    if (!tbody) return;
    if (!scans?.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><i class="ph ph-scan" style="font-size:2rem;opacity:.3;"></i><p>No scans today.</p></td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    scans.forEach((s, i) => {
        const dt  = new Date(s.scan_time);
        const tr  = document.createElement('tr');
        if (i === 0) tr.className = 'new-row';
        tr.innerHTML = `
            <td><span style="color:var(--text-muted);font-size:.8rem;">${i+1}</span></td>
            <td><div class="student-cell">
                <div class="student-avatar">${s.photo_path?`<img src="${esc(s.photo_path)}" alt="">`:initials(s.full_name)}</div>
                <div><div class="student-name">${esc(s.full_name)}</div><div class="student-meta">${esc(s.student_id_no||'')}</div></div>
            </div></td>
            <td><div class="student-name">${esc(s.course)}</div><div class="student-meta">${esc(s.year_level)} — Sec. ${esc(s.section||'—')}</div></td>
            <td>${esc(s.section||'—')}</td>
            <td><span class="scan-time">${dt.toLocaleDateString('en-PH',{month:'short',day:'numeric'})}, ${fmtTime(dt)}</span></td>
            <td>${statusBadge(s.status)}</td>
            <td>${s.is_irregular==1?`<span class="badge badge-irregular"><i class="ph ph-shuffle"></i> Irregular</span>`:`<span class="badge badge-regular"><i class="ph ph-check"></i> Regular</span>`}</td>`;
        tbody.appendChild(tr);
    });
}

function startAutoRefresh() {
    App.liveInterval = setInterval(() => {
        if (App.currentSection === 'dashboard' && App.autoRefresh) fetchLiveScans();
    }, 5000);
    document.getElementById('refresh-btn')?.addEventListener('click', fetchLiveScans);
    document.getElementById('auto-refresh-toggle')?.addEventListener('change', function() {
        App.autoRefresh = this.checked;
        showToast(this.checked ? 'Auto-refresh ON' : 'Auto-refresh OFF', '', this.checked ? 'success' : 'info');
    });
}

/* ═══════════════════════════
   TODAY'S ACTIVE SCHEDULES
═══════════════════════════ */
function fetchTodaySchedules() {
    fetch('index.php?action=get_schedules')
        .then(r => r.json())
        .then(data => { App.allSchedules = data; renderTodaySchedules(data); })
        .catch(() => {});
}

function renderTodaySchedules(schedules) {
    const el = document.getElementById('today-schedules-list');
    if (!el) return;

    const today = App.todayDay;
    const active = schedules.filter(s => (s.days||[]).includes(today));

    if (!schedules.length) {
        el.innerHTML = `<p class="today-no-sched">No schedules configured yet. Go to <strong>Schedule Manager</strong> to set them up.</p>`;
        return;
    }

    // Check if any schedule is active today
    const hasAnyToday = schedules.some(s => Array.isArray(s.days) && s.days.includes(App.todayDay));

    if (!hasAnyToday) {
        el.innerHTML = `
            <div class="today-no-sched" style="width:100%;padding:1.25rem 1.5rem;">
                <i class="ph ph-coffee" style="font-size:1.5rem;opacity:.4;display:block;margin-bottom:.4rem;"></i>
                No classes scheduled for <strong>${App.todayDay}</strong>.
                ${App.todayDay === 'Sunday' || App.todayDay === 'Saturday' ? 'Enjoy the weekend! 😊' : 'Check Schedule Manager to configure.'}
            </div>`;
        return;
    }

    el.innerHTML = '';
    schedules.forEach(s => {
        const isToday = (s.days||[]).includes(today);
        const chip = document.createElement('div');
        chip.className = `today-sched-chip${isToday?' active-today':''}`;
        chip.innerHTML = `
            <span class="chip-course">${esc(s.course)}</span>
            <span class="chip-year">${esc(s.year_level)}</span>
            <div style="display:flex;align-items:center;gap:.35rem;margin-top:.1rem;width:100%;">
                <span class="chip-time">⏰ ${s.ontime_cutoff?.substring(0,5)||'--'}</span>
                <span style="font-size:.68rem;color:var(--text-muted);">cutoff</span>
            </div>
            ${isToday ? `<span style="font-size:.68rem;font-weight:600;color:var(--success);margin-top:.1rem;">● Active Today</span>` : ''}`;
        el.appendChild(chip);
    });

    if (!active.length) {
        const note = document.createElement('p');
        note.className = 'today-no-sched';
        note.style.width = '100%';
        note.textContent = `No classes scheduled for ${today}.`;
        el.appendChild(note);
    }
}

/* ═══════════════════════════
   CONNECTION STATUS
═══════════════════════════ */
function checkConnection() {
    const dot = document.getElementById('status-dot');
    const txt = document.getElementById('status-text');
    fetch('index.php?action=get_live_scans')
        .then(r => {
            if (r.ok) { dot?.classList.add('connected'); if(txt) txt.textContent='Database Connected'; }
            else throw new Error();
        })
        .catch(() => { dot?.classList.add('error'); if(txt) txt.textContent='Connection Error'; });
}

/* ═══════════════════════════
   REGISTRATION FORM
═══════════════════════════ */
function initRegistrationForm() {
    const form      = document.getElementById('reg-form');
    const rfidInput = document.getElementById('rfid_uid');
    const clearBtn  = document.getElementById('clear-form-btn');
    const mobile    = document.getElementById('parent_mobile');
    const submitBtn = document.getElementById('reg-submit-btn');
    const irrChk    = document.getElementById('is_irregular_chk');
    const irrBox    = document.getElementById('irregular-schedule-box');
    const courseEl  = document.getElementById('reg-course');
    const yearEl    = document.getElementById('reg-year');

    // RFID flash on input
    rfidInput?.addEventListener('input', () => {
        const ind = document.getElementById('rfid-indicator');
        if (ind) { ind.style.color = '#16a34a'; setTimeout(() => ind.style.color = '', 600); }
    });
    rfidInput?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); document.querySelector('[name="student_id_no"]')?.focus(); }
    });

    // Mobile format
    mobile?.addEventListener('focus', () => { if (!mobile.value) mobile.value = '+63'; });
    mobile?.addEventListener('input', () => {
        let v = mobile.value.replace(/[^\d+]/g,'');
        if (!v.startsWith('+63')) v = '+63' + v.replace(/\D/g,'');
        if (v.length > 13) v = v.slice(0,13);
        mobile.value = v;
    });

    // Irregular toggle
    irrChk?.addEventListener('change', () => {
        if (irrBox) irrBox.style.display = irrChk.checked ? 'block' : 'none';
    });

    // Schedule preview on course/year change
    function updateSchedPreview() {
        const c = courseEl?.value;
        const y = yearEl?.value;
        if (!c || !y) return;
        const box     = document.getElementById('schedule-preview-box');
        const content = document.getElementById('sched-preview-content');
        if (!box || !content) return;

        // Look in cached schedules
        const found = App.allSchedules.find(s => s.course === c && s.year_level === y);
        box.style.display = 'block';
        if (found) {
            const days = found.days || [];
            content.innerHTML = `
                <div class="preview-days">${days.map(d=>`<span class="preview-day-tag">${d}</span>`).join('')}</div>
                <div class="preview-times">
                    ✅ On Time: before <strong>${found.ontime_cutoff?.substring(0,5)||'07:30'}</strong>
                    &nbsp;|&nbsp; ⚠️ Late: until <strong>${found.late_cutoff?.substring(0,5)||'08:30'}</strong>
                </div>`;
        } else {
            content.innerHTML = `<p class="preview-no-sched">No schedule set for ${c} ${y}. Please configure it in Schedule Manager first.</p>`;
        }
    }

    // Fetch schedules if not yet loaded, then update preview
    courseEl?.addEventListener('change', () => {
        if (!App.allSchedules.length) fetch('index.php?action=get_schedules').then(r=>r.json()).then(d=>{App.allSchedules=d;updateSchedPreview();});
        else updateSchedPreview();
    });
    yearEl?.addEventListener('change', () => {
        if (!App.allSchedules.length) fetch('index.php?action=get_schedules').then(r=>r.json()).then(d=>{App.allSchedules=d;updateSchedPreview();});
        else updateSchedPreview();
    });

    // Clear
    clearBtn?.addEventListener('click', () => {
        form?.reset();
        clearPhotoPreview();
        if (irrBox) irrBox.style.display = 'none';
        document.getElementById('schedule-preview-box').style.display = 'none';
        rfidInput?.focus();
        showToast('Form Cleared','','info');
    });

    // Submit
    form?.addEventListener('submit', async e => {
        e.preventDefault();
        if (!validateRegForm()) return;
        setLoading(submitBtn, true, 'Registering...');
        try {
            const fd = new FormData(form);
            const photo = document.getElementById('student_photo');
            if (photo?.files[0]) fd.set('student_photo', photo.files[0]);
            const res  = await fetch('index.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                showToast('Registered!', data.message, 'success');
                addRegHistory(fd.get('full_name'), fd.get('course'), fd.get('year_level'));
                form.reset(); clearPhotoPreview();
                if (irrBox) irrBox.style.display = 'none';
                document.getElementById('schedule-preview-box').style.display = 'none';
                rfidInput?.focus();
            } else if (data.status === 'error') {
                showToast('Failed', data.message, 'error');
            } else {
                showToast('Notice', data.message, 'info');
            }
        } catch { showToast('Network Error','Check connection.','error'); }
        finally  { setLoading(submitBtn, false, '<i class="ph ph-user-plus"></i> Register Student'); }
    });
}

function validateRegForm() {
    const v = id => document.getElementById(id)?.value?.trim();
    const q = n  => document.querySelector(`[name="${n}"]`)?.value?.trim();
    if (!v('rfid_uid'))        { showToast('RFID Required','Scan the card first.','warning'); return false; }
    if (!q('student_id_no'))   { showToast('Student ID Required','','warning'); return false; }
    if (!q('full_name'))       { showToast('Name Required','','warning'); return false; }
    if (!q('course'))          { showToast('Course Required','Select a course.','warning'); return false; }
    if (!q('year_level'))      { showToast('Year Level Required','Select a year level.','warning'); return false; }
    if (!q('parent_mobile'))   { showToast('Mobile Required','','warning'); return false; }
    const mob = q('parent_mobile');
    if (mob && !/^\+63\d{10}$/.test(mob)) { showToast('Invalid Mobile','Must be +63 + 10 digits.','warning'); return false; }
    return true;
}

function addRegHistory(name, course, year) {
    App.regHistory.unshift({ name, course, year, time: new Date() });
    if (App.regHistory.length > 5) App.regHistory.pop();
    const el = document.getElementById('reg-history-list');
    if (!el) return;
    el.innerHTML = '';
    App.regHistory.forEach(h => {
        const d = document.createElement('div');
        d.className = 'history-item';
        d.innerHTML = `
            <div class="history-avatar">${initials(h.name)}</div>
            <div>
                <div class="name">${esc(h.name)}</div>
                <div class="time">${esc(h.course)} ${esc(h.year)} · ${fmtTime(h.time)}</div>
            </div>`;
        el.appendChild(d);
    });
}

/* ═══════════════════════════
   PHOTO UPLOAD
═══════════════════════════ */
function initPhotoUpload() {
    const input  = document.getElementById('student_photo');
    const circle = document.getElementById('photo-preview-circle');
    const zone   = document.getElementById('photo-drop-zone');

    input?.addEventListener('change',  e => { if (e.target.files[0]) previewPhoto(e.target.files[0]); });
    circle?.addEventListener('click',  () => input?.click());
    zone?.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragging'); });
    zone?.addEventListener('dragleave',() => zone.classList.remove('dragging'));
    zone?.addEventListener('drop',     e => {
        e.preventDefault(); zone.classList.remove('dragging');
        const f = e.dataTransfer.files[0];
        if (f?.type.startsWith('image/')) {
            previewPhoto(f);
            const dt = new DataTransfer(); dt.items.add(f);
            if (input) input.files = dt.files;
        } else showToast('Invalid File','Use JPG or PNG.','warning');
    });
}

function previewPhoto(file) {
    if (file.size > 2*1024*1024) { showToast('Too Large','Max 2MB.','warning'); return; }
    const r = new FileReader();
    r.onload = e => {
        const img = document.getElementById('photo-preview-img');
        const ph  = document.getElementById('photo-placeholder');
        if (img) { img.src = e.target.result; img.style.display = 'block'; }
        if (ph)  ph.style.display = 'none';
    };
    r.readAsDataURL(file);
}

function clearPhotoPreview() {
    const img = document.getElementById('photo-preview-img');
    const ph  = document.getElementById('photo-placeholder');
    const inp = document.getElementById('student_photo');
    if (img) { img.src=''; img.style.display='none'; }
    if (ph)  ph.style.display = '';
    if (inp) inp.value = '';
}

/* ═══════════════════════════
   STUDENT RECORDS
═══════════════════════════ */
function fetchStudents() {
    const tbody = document.getElementById('records-tbody');
    const badge = document.getElementById('records-count');
    if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="table-loading"><div class="loader-dots"><span></span><span></span><span></span></div> Loading...</td></tr>`;

    const s  = document.getElementById('records-search')?.value.trim() || '';
    const fc = document.getElementById('filter-course')?.value || '';
    const fy = document.getElementById('filter-year')?.value || '';
    const fi = document.getElementById('filter-irregular')?.value ?? '-1';
    const url = `index.php?action=get_students&s=${encodeURIComponent(s)}&course=${encodeURIComponent(fc)}&year=${encodeURIComponent(fy)}&irreg=${fi}`;

    fetch(url)
        .then(r => r.json())
        .then(data => { App.allStudents = data; renderRecordsTable(data, tbody, badge); })
        .catch(() => { if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><p>Failed to load.</p></td></tr>`; });
}

function renderRecordsTable(students, tbody, badge) {
    if (!tbody) return;
    if (badge) badge.textContent = `${students.length} student${students.length!==1?'s':''}`;
    if (!students.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><i class="ph ph-users" style="font-size:2rem;opacity:.3;"></i><p>No students found.</p></td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    students.forEach(s => {
        const days      = s.is_irregular == 1 ? (s.irreg_days||[]) : (s.sched_days||[]);
        const daysHtml  = days.map(d => `<span class="day-tag${d===App.todayDay?' today':''}${s.is_irregular==1?' irreg':''}">${d.substring(0,3)}</span>`).join('');
        const typeHtml  = s.is_irregular==1
            ? `<span class="badge badge-irregular"><i class="ph ph-shuffle"></i> Irregular</span>`
            : `<span class="badge badge-regular"><i class="ph ph-check"></i> Regular</span>`;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><div class="student-avatar" style="width:38px;height:38px;">${s.photo_path?`<img src="${esc(s.photo_path)}" alt="">`:initials(s.full_name)}</div></td>
            <td>
                <div class="student-name">${esc(s.full_name)}</div>
                <div class="student-meta">${esc(s.student_id_no||'')} · ${esc(s.parent_mobile||'')}</div>
            </td>
            <td>
                <div class="student-name">${esc(s.course)}</div>
                <div class="student-meta">${esc(s.year_level)} — Sec. ${esc(s.section||'—')}</div>
            </td>
            <td><div class="sched-days-cell">${daysHtml||'<span style="color:var(--text-muted);font-size:.78rem;">Not set</span>'}</div></td>
            <td>${typeHtml}</td>
            <td>${statusBadge(s.today_status)}</td>
            <td><div class="action-btns">
                <button class="action-btn edit" title="Edit" data-student='${JSON.stringify(s).replace(/'/g,"&#39;")}'>
                    <i class="ph ph-pencil-simple"></i>
                </button>
                <button class="action-btn delete" title="Delete" data-id="${s.id}" data-name="${esc(s.full_name)}">
                    <i class="ph ph-trash"></i>
                </button>
            </div></td>`;
        tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.action-btn.edit').forEach(btn =>
        btn.addEventListener('click', () => openEditModal(JSON.parse(btn.dataset.student))));
    tbody.querySelectorAll('.action-btn.delete').forEach(btn =>
        btn.addEventListener('click', () => openDeleteModal(btn.dataset.id, btn.dataset.name)));
}

function initRecordsFilters() {
    let debounce;
    document.getElementById('records-search')?.addEventListener('input', () => {
        clearTimeout(debounce); debounce = setTimeout(fetchStudents, 400);
    });
    ['filter-course','filter-year','filter-irregular'].forEach(id =>
        document.getElementById(id)?.addEventListener('change', fetchStudents));
}

/* ═══════════════════════════
   SCHEDULE MANAGER
═══════════════════════════ */
function fetchSchedules() {
    const el = document.getElementById('schedules-list-container');
    if (el) el.innerHTML = `<div class="loader-row"><div class="loader-dots"><span></span><span></span><span></span></div> Loading...</div>`;

    fetch('index.php?action=get_schedules')
        .then(r => r.json())
        .then(data => { App.allSchedules = data; renderSchedulesList(data, el); })
        .catch(() => { if (el) el.innerHTML = `<p style="padding:1rem;color:var(--text-muted)">Failed to load schedules.</p>`; });
}

function renderSchedulesList(schedules, container) {
    if (!container) return;
    if (!schedules.length) {
        container.innerHTML = `<p style="padding:1.25rem;color:var(--text-muted);font-size:.85rem;text-align:center;">No schedules yet. Use the form on the left to create one.</p>`;
        return;
    }
    container.innerHTML = '';
    schedules.forEach(s => {
        const days    = s.days || [];
        const daysHtml = days.map(d => `<span class="sched-day-tag${d===App.todayDay?' today-active':''}">${d.substring(0,3)}</span>`).join('');
        const card = document.createElement('div');
        card.className = 'sched-card';
        card.innerHTML = `
            <div class="sched-card-left">
                <div class="sched-course-label">
                    <span class="sched-course-badge">${esc(s.course)}</span>
                    ${esc(s.year_level)}
                </div>
                <div class="sched-days-row">${daysHtml||'<span style="font-size:.75rem;color:var(--text-muted)">No days set</span>'}</div>
            </div>
            <div class="sched-card-right">
                <div class="sched-times">
                    🟢 ${s.ontime_cutoff?.substring(0,5)||'--'}<br>
                    🟡 ${s.late_cutoff?.substring(0,5)||'--'}<br>
                    🌙 ${s.timeout_cutoff?.substring(0,5)||'15:00'}
                </div>
                <button class="sched-edit-btn" data-course="${esc(s.course)}" data-year="${esc(s.year_level)}"
                    data-days='${JSON.stringify(days)}' data-ontime="${s.ontime_cutoff||'07:30:00'}" data-late="${s.late_cutoff||'08:30:00'}" data-timeout="${s.timeout_cutoff||'15:00:00'}">
                    Edit
                </button>
            </div>`;
        container.appendChild(card);
    });

    // Edit button loads schedule into form
    container.querySelectorAll('.sched-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('sched-course').value = btn.dataset.course;
            document.getElementById('sched-year').value   = btn.dataset.year;
            document.getElementById('sched-ontime').value = btn.dataset.ontime?.substring(0,5);
            document.getElementById('sched-late').value   = btn.dataset.late?.substring(0,5);
            if (document.getElementById('sched-timeout') && btn.dataset.timeout)
                document.getElementById('sched-timeout').value = btn.dataset.timeout?.substring(0,5);
            // Check correct day pills
            const days = JSON.parse(btn.dataset.days||'[]');
            document.querySelectorAll('#sched-day-picker input[type=checkbox]').forEach(cb => {
                cb.checked = days.includes(cb.value);
            });
            document.getElementById('sched-course').scrollIntoView({ behavior:'smooth', block:'center' });
            showToast('Schedule Loaded', `Editing ${btn.dataset.course} ${btn.dataset.year}`, 'info');
        });
    });
}

function initScheduleForm() {
    const form    = document.getElementById('sched-form');
    const clearBtn = document.getElementById('sched-clear-btn');
    const reloadBtn = document.getElementById('reload-schedules-btn');
    const saveBtn  = document.getElementById('sched-save-btn');

    clearBtn?.addEventListener('click', () => {
        form?.reset();
        document.querySelectorAll('#sched-day-picker input').forEach(c => c.checked = false);
    });

    reloadBtn?.addEventListener('click', () => fetchSchedules());

    form?.addEventListener('submit', async e => {
        e.preventDefault();
        const course = document.getElementById('sched-course')?.value;
        const year   = document.getElementById('sched-year')?.value;
        const days   = [...document.querySelectorAll('#sched-day-picker input:checked')].map(c => c.value);
        const ontime = document.getElementById('sched-ontime')?.value + ':00';
        const late   = document.getElementById('sched-late')?.value + ':00';
        const timeout_cutoff = (document.getElementById('sched-timeout')?.value || '15:00') + ':00';

        if (!course || !year) { showToast('Required','Select course and year level.','warning'); return; }
        if (!days.length)     { showToast('Select Days','Pick at least one class day.','warning'); return; }
        if (ontime >= late)   { showToast('Invalid Times','On-time cutoff must be before late cutoff.','warning'); return; }

        setLoading(saveBtn, true, 'Saving...');
        try {
            const fd = new FormData();
            fd.append('action','save_schedule');
            fd.append('course', course); fd.append('year_level', year);
            fd.append('ontime_cutoff', ontime); fd.append('late_cutoff', late);
            fd.append('timeout_cutoff', timeout_cutoff);
            days.forEach(d => fd.append('days[]', d));

            const res  = await fetch('index.php', { method:'POST', body: fd });
            const data = await res.json();
            showToast(data.status==='success'?'Saved!':'Notice', data.message, data.status);
            if (data.status==='success'||data.status==='info') {
                fetchSchedules();
                fetchTodaySchedules();
                form.reset();
                document.querySelectorAll('#sched-day-picker input').forEach(c => c.checked = false);
            }
        } catch { showToast('Error','Could not save schedule.','error'); }
        finally  { setLoading(saveBtn, false, '<i class="ph ph-floppy-disk"></i> Save Schedule'); }
    });
}

/* ═══════════════════════════
   IRREGULAR STUDENTS LIST
═══════════════════════════ */
function fetchIrregularStudents() {
    fetch('index.php?action=get_students&irreg=1')
        .then(r => r.json())
        .then(data => renderIrregTable(data))
        .catch(() => {});
}

function renderIrregTable(students) {
    const tbody = document.getElementById('irreg-tbody');
    const badge = document.getElementById('irreg-count');
    if (badge) badge.textContent = students.length;
    if (!tbody) return;

    if (!students.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="table-empty"><p>No irregular students registered.</p></td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    students.forEach(s => {
        const days = s.irreg_days || [];
        const daysHtml = days.map(d => `<span class="day-tag irreg${d===App.todayDay?' today':''}">${d.substring(0,3)}</span>`).join('') || '—';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><div class="student-cell">
                <div class="student-avatar">${initials(s.full_name)}</div>
                <div><div class="student-name">${esc(s.full_name)}</div><div class="student-meta">${esc(s.student_id_no||'')}</div></div>
            </div></td>
            <td><div class="student-name">${esc(s.course)}</div><div class="student-meta">${esc(s.year_level)}</div></td>
            <td><div class="sched-days-cell">${daysHtml}</div></td>
            <td><span class="scan-time">${s.ontime_cutoff?.substring(0,5)||'N/A'}</span></td>
            <td><span class="scan-time">${s.late_cutoff?.substring(0,5)||'N/A'}</span></td>
            <td><button class="action-btn edit" data-student='${JSON.stringify(s).replace(/'/g,"&#39;")}'>
                <i class="ph ph-pencil-simple"></i>
            </button></td>`;
        tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.action-btn.edit').forEach(btn =>
        btn.addEventListener('click', () => openEditModal(JSON.parse(btn.dataset.student))));
}

/* ═══════════════════════════
   MODALS
═══════════════════════════ */
function initModals() {
    // Edit
    document.getElementById('modal-close')?.addEventListener('click',  () => closeModal('edit-modal'));
    document.getElementById('modal-cancel')?.addEventListener('click', () => closeModal('edit-modal'));
    document.getElementById('edit-modal')?.addEventListener('click', e => { if (e.target.id==='edit-modal') closeModal('edit-modal'); });

    document.getElementById('edit-is-irregular')?.addEventListener('change', function() {
        document.getElementById('edit-irregular-box').style.display = this.checked ? 'block' : 'none';
    });

    document.getElementById('edit-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const saveBtn = e.target.querySelector('[type="submit"]');
        setLoading(saveBtn, true, 'Saving...');
        try {
            const fd = new FormData();
            fd.append('action', 'update_student');
            fd.append('student_id',     document.getElementById('edit-id').value);
            fd.append('student_id_no',  document.getElementById('edit-idno').value);
            fd.append('full_name',      document.getElementById('edit-name').value);
            fd.append('course',         document.getElementById('edit-course').value);
            fd.append('year_level',     document.getElementById('edit-year').value);
            fd.append('section',        document.getElementById('edit-section').value);
            fd.append('parent_mobile',  document.getElementById('edit-mobile').value);
            const irrChk = document.getElementById('edit-is-irregular');
            fd.append('is_irregular', irrChk?.checked ? '1' : '0');
            if (irrChk?.checked) {
                document.querySelectorAll('#edit-day-picker input:checked').forEach(cb => fd.append('irreg_days[]', cb.value));
            }
            const res  = await fetch('index.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd });
            const data = await res.json();
            if (data.status === 'success' || data.status === 'info') {
                showToast('Profile Updated', data.message || 'Changes saved successfully.', 'success');
                closeModal('edit-modal');
                fetchStudents();
            } else {
                showToast('Error', data.message || 'Could not save changes.', 'error');
            }
        } catch { showToast('Network Error', 'Check connection.', 'error'); }
        finally  { setLoading(saveBtn, false, '<i class="ph ph-floppy-disk"></i> Save Changes'); }
    });

    // Delete
    document.getElementById('delete-modal-close')?.addEventListener('click', () => closeModal('delete-modal'));
    document.getElementById('delete-cancel')?.addEventListener('click',      () => closeModal('delete-modal'));
    document.getElementById('delete-modal')?.addEventListener('click', e => { if (e.target.id==='delete-modal') closeModal('delete-modal'); });

    document.getElementById('delete-confirm')?.addEventListener('click', async () => {
        if (!App.deleteTargetId) return;
        const btn = document.getElementById('delete-confirm');
        setLoading(btn, true, 'Deleting...');
        try {
            const fd = new FormData();
            fd.append('action','delete'); fd.append('student_id', App.deleteTargetId);
            await fetch('index.php', { method:'POST', body: fd });
            showToast('Deleted', `${App.deleteTargetName} removed.`, 'success');
            closeModal('delete-modal');
            fetchStudents();
        } catch { showToast('Error','Could not delete.','error'); }
        finally  { setLoading(btn, false, '<i class="ph ph-trash"></i> Yes, Delete'); }
    });

    // Invalid RFID
    document.getElementById('rfid-alert-close')?.addEventListener('click', () => {
        closeModal('invalid-rfid-modal');
        document.querySelector('.nav-item[data-section="registration"]')?.click();
    });
}

function openEditModal(s) {
    document.getElementById('edit-id').value      = s.id;
    document.getElementById('edit-rfid').value    = s.rfid_uid;
    document.getElementById('edit-idno').value    = s.student_id_no || '';
    document.getElementById('edit-name').value    = s.full_name;
    document.getElementById('edit-course').value  = s.course;
    document.getElementById('edit-year').value    = s.year_level;
    document.getElementById('edit-section').value = s.section || '';
    document.getElementById('edit-mobile').value  = s.parent_mobile || '';

    const irrChk = document.getElementById('edit-is-irregular');
    const irrBox = document.getElementById('edit-irregular-box');
    if (irrChk) irrChk.checked = s.is_irregular == 1;
    if (irrBox) irrBox.style.display = s.is_irregular == 1 ? 'block' : 'none';

    // Pre-check irregular days
    const days = s.irreg_days || [];
    document.querySelectorAll('#edit-day-picker input').forEach(cb => {
        cb.checked = days.includes(cb.value);
    });

    openModal('edit-modal');
}

function openDeleteModal(id, name) {
    App.deleteTargetId = id; App.deleteTargetName = name;
    document.getElementById('delete-student-name').textContent = name;
    openModal('delete-modal');
}

function openModal(id)  { document.getElementById(id)?.classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); document.body.style.overflow = ''; }

/* ═══════════════════════════
   REPORTS
═══════════════════════════ */
function initReports() {
    document.getElementById('generate-report-btn')?.addEventListener('click', fetchReport);
    document.getElementById('export-csv-btn')?.addEventListener('click', exportXLSX);
}

function fetchReport() {
    const from   = document.getElementById('report-from')?.value;
    const to     = document.getElementById('report-to')?.value;
    const course = document.getElementById('report-course')?.value;
    const year   = document.getElementById('report-year')?.value;
    const status = document.getElementById('report-status')?.value;
    const irreg  = document.getElementById('report-irreg')?.value;
    const tbody  = document.getElementById('report-tbody');
    const badge  = document.getElementById('report-count');
    const btn    = document.getElementById('generate-report-btn');

    if (!from||!to)               { showToast('Dates Required','','warning'); return; }
    if (new Date(from)>new Date(to)) { showToast('Invalid Range','Start must be before end.','warning'); return; }

    setLoading(btn, true, 'Generating...');
    if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="table-loading"><div class="loader-dots"><span></span><span></span><span></span></div> Fetching...</td></tr>`;

    const rparams = new URLSearchParams({
        action:    'get_report',
        date_from: from,
        date_to:   to,
        course:    course,
        year:      year,
        status:    status,
        irreg:     irreg || '',
    });
    fetch(`index.php?${rparams.toString()}`)
        .then(r => r.json())
        .then(data => {
            // Filtering is now done server-side
            // JS-side irreg filter as backup only
            let filtered = data;
            App.reportData = filtered;
            renderReportTable(filtered, tbody, badge);
            updateReportSummary(filtered);
        })
        .catch(() => { if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><p>Failed to load.</p></td></tr>`; })
        .finally(() => setLoading(btn, false, '<i class="ph ph-magnifying-glass"></i> Generate'));
}

function renderReportTable(records, tbody, badge) {
    if (!tbody) return;
    if (badge) badge.textContent = `${records.length} record${records.length!==1?'s':''}`;
    if (!records.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><p>No records for selected filters.</p></td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    records.forEach((r, i) => {
        const isAbsent = r.status === 'Absent';
        const dt   = r.scan_time ? new Date(r.scan_time) : null;
        const tout = r.time_out  ? fmtTime(new Date(r.time_out)) : '<span style="color:var(--text-muted);font-size:.78rem;">—</span>';
        const dateCell = dt
            ? dt.toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'})
            : `<span style="color:var(--text-muted);font-size:.78rem;">${document.getElementById('report-from')?.value||''}→${document.getElementById('report-to')?.value||''}</span>`;
        const timeCell = dt ? `<span class="scan-time">${fmtTime(dt)}</span>` : '<span style="color:var(--text-muted);font-size:.78rem;">—</span>';
        const tr = document.createElement('tr');
        if (isAbsent) tr.style.opacity = '0.7';
        tr.innerHTML = `
            <td style="color:var(--text-muted);font-size:.8rem;">${i+1}</td>
            <td><div class="student-name">${esc(r.full_name)}</div><div class="student-meta">${esc(r.student_id_no||'')}</div></td>
            <td><div class="student-name">${esc(r.course)}</div><div class="student-meta">${esc(r.year_level)} — Sec. ${esc(r.section||'—')}</div></td>
            <td style="font-size:.82rem;">${dateCell}</td>
            <td>${timeCell}</td>
            <td>${tout}</td>
            <td>${statusBadge(r.status)}</td>
            <td>${r.is_irregular==1?`<span class="badge badge-irregular"><i class="ph ph-shuffle"></i> Irregular</span>`:`<span class="badge badge-regular"><i class="ph ph-check"></i> Regular</span>`}</td>`;
        tbody.appendChild(tr);
    });
}

function updateReportSummary(records) {
    const row = document.getElementById('report-summary-row');
    if (row) row.style.display = records.length ? 'flex' : 'none';
    const g = id => document.getElementById(id);
    if (g('rpt-total'))      g('rpt-total').textContent      = records.length;
    if (g('rpt-ontime'))     g('rpt-ontime').textContent     = records.filter(r=>r.status==='On Time').length;
    if (g('rpt-late'))       g('rpt-late').textContent       = records.filter(r=>r.status==='Late').length;
    if (g('rpt-absent'))     g('rpt-absent').textContent     = records.filter(r=>r.status==='Absent').length;
    if (g('rpt-irreg-count'))g('rpt-irreg-count').textContent= records.filter(r=>r.is_irregular==1).length;
}

function exportXLSX() {
    if (!App.reportData?.length) { showToast('No Data','Generate a report first.','warning'); return; }

    const from   = document.getElementById('report-from')?.value  || '';
    const to     = document.getElementById('report-to')?.value    || '';
    const course = document.getElementById('report-course')?.value || 'All Courses';
    const year   = document.getElementById('report-year')?.value   || 'All Years';
    const status = document.getElementById('report-status')?.value || 'All';
    const fname  = `TrackED_Attendance_${from}_to_${to}.xlsx`;

    // ── Load SheetJS dynamically then build ──────────────
    const sheetJsUrl = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    function buildWorkbook() {
        const XLSX = window.XLSX;
        const wb   = XLSX.utils.book_new();

        // ════════════════════════════════════
        //  SHEET 1 — ATTENDANCE LOG
        // ════════════════════════════════════
        const onTime = App.reportData.filter(r => r.status === 'On Time').length;
        const late   = App.reportData.filter(r => r.status === 'Late').length;
        const absent = App.reportData.filter(r => r.status === 'Absent').length;
        const irreg  = App.reportData.filter(r => r.is_irregular == 1).length;

        // Title block rows
        const titleRows = [
            ['TrackED Attendance System — Attendance Report'],
            [`Date Range: ${from} to ${to}   |   Course: ${course}   |   Year Level: ${year}   |   Status Filter: ${status}`],
            [`Generated: ${new Date().toLocaleString('en-PH')}   |   Total Records: ${App.reportData.length}   |   On Time: ${onTime}   |   Late: ${late}   |   Absent: ${absent}   |   Irregular: ${irreg}`],
            [],  // blank separator
            ['#', 'Full Name', 'Student ID No.', 'Course', 'Year Level', 'Section', 'Student Type', 'Date', 'Time-IN', 'Time-OUT', 'Status'],
        ];

        const dataRows = App.reportData.map((r, i) => {
            const dt   = r.scan_time ? new Date(r.scan_time) : null;
            const tout = r.time_out  ? new Date(r.time_out)  : null;
            return [
                i + 1,
                r.full_name        || '',
                r.student_id_no    || '',
                r.course           || '',
                r.year_level       || '',
                r.section          || '—',
                r.is_irregular == 1 ? 'Irregular' : 'Regular',
                dt   ? dt.toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'}) : '—',
                dt   ? fmtTime(dt)   : '—',
                tout ? fmtTime(tout) : '—',
                r.status || 'Absent',
            ];
        });

        const ws1data = [...titleRows, ...dataRows];
        const ws1 = XLSX.utils.aoa_to_sheet(ws1data);

        // Column widths
        ws1['!cols'] = [
            {wch:5},   // #
            {wch:28},  // Full Name
            {wch:16},  // Student ID
            {wch:12},  // Course
            {wch:12},  // Year Level
            {wch:9},   // Section
            {wch:12},  // Type
            {wch:18},  // Date
            {wch:12},  // Time-IN
            {wch:12},  // Time-OUT
            {wch:10},  // Status
        ];

        // Freeze panes: lock title + header rows, keep columns A-B visible
        ws1['!freeze'] = { xSplit: 0, ySplit: 5 };

        // Merge title cells A1:K1, A2:K2, A3:K3
        ws1['!merges'] = [
            {s:{r:0,c:0}, e:{r:0,c:10}},
            {s:{r:1,c:0}, e:{r:1,c:10}},
            {s:{r:2,c:0}, e:{r:2,c:10}},
        ];

        // ── Cell styles via write-time meta (SheetJS CE supports !s via writeFile with cellStyles:true) ──
        // Title row style
        const titleStyle = { font:{bold:true, sz:14, color:{rgb:'FFFFFF'}}, fill:{fgColor:{rgb:'1E3A5F'}}, alignment:{horizontal:'center',vertical:'center'} };
        const subStyle   = { font:{sz:10, color:{rgb:'FFFFFF'}},             fill:{fgColor:{rgb:'2D5F8A'}}, alignment:{horizontal:'left',  vertical:'center'} };
        const infoStyle  = { font:{sz:9,  color:{rgb:'FFFFFF'}},             fill:{fgColor:{rgb:'3A7AB5'}}, alignment:{horizontal:'left',  vertical:'center'} };
        const hdrStyle   = { font:{bold:true, sz:11, color:{rgb:'FFFFFF'}},  fill:{fgColor:{rgb:'1E3A5F'}}, alignment:{horizontal:'center',vertical:'center'},border:{bottom:{style:'medium',color:{rgb:'FFFFFF'}}} };

        const statusStyle = {
            'On Time': { font:{bold:true, color:{rgb:'166534'}}, fill:{fgColor:{rgb:'DCFCE7'}}, alignment:{horizontal:'center'} },
            'Late':    { font:{bold:true, color:{rgb:'92400E'}}, fill:{fgColor:{rgb:'FEF3C7'}}, alignment:{horizontal:'center'} },
            'Absent':  { font:{bold:true, color:{rgb:'991B1B'}}, fill:{fgColor:{rgb:'FEE2E2'}}, alignment:{horizontal:'center'} },
        };
        const irregStyle   = { font:{italic:true, color:{rgb:'5B21B6'}}, fill:{fgColor:{rgb:'EDE9FE'}}, alignment:{horizontal:'center'} };
        const regularStyle = { font:{color:{rgb:'065F46'}}, fill:{fgColor:{rgb:'ECFDF5'}}, alignment:{horizontal:'center'} };
        const defaultCell  = { font:{sz:10}, alignment:{vertical:'center'}, border:{
            top:{style:'thin',color:{rgb:'E5E7EB'}}, bottom:{style:'thin',color:{rgb:'E5E7EB'}},
            left:{style:'thin',color:{rgb:'E5E7EB'}}, right:{style:'thin',color:{rgb:'E5E7EB'}}
        }};
        const altRowFill   = { fill:{fgColor:{rgb:'F8FAFC'}} };
        const numStyle     = { font:{sz:10}, alignment:{horizontal:'center', vertical:'center'}, border: defaultCell.border };

        // Apply styles to title/header rows
        const encCell = (r,c) => XLSX.utils.encode_cell({r,c});
        for (let c = 0; c <= 10; c++) {
            const c0 = encCell(0, c); if (ws1[c0]) ws1[c0].s = titleStyle;
            const c1 = encCell(1, c); if (ws1[c1]) ws1[c1].s = subStyle;
            const c2 = encCell(2, c); if (ws1[c2]) ws1[c2].s = infoStyle;
            const c4 = encCell(4, c); if (ws1[c4]) ws1[c4].s = hdrStyle;
        }

        // Apply styles to data rows (starting at row index 5)
        dataRows.forEach((row, i) => {
            const excelRow = 5 + i;
            const isAlt    = i % 2 === 1;
            const rowStatus = row[10]; // Status column
            for (let c = 0; c <= 10; c++) {
                const cellAddr = encCell(excelRow, c);
                if (!ws1[cellAddr]) continue;
                if (c === 10) {
                    ws1[cellAddr].s = { ...(statusStyle[rowStatus] || defaultCell), border: defaultCell.border };
                } else if (c === 6) {
                    ws1[cellAddr].s = row[6] === 'Irregular' ? { ...irregStyle, border: defaultCell.border } : { ...regularStyle, border: defaultCell.border };
                } else if (c === 0) {
                    ws1[cellAddr].s = { ...numStyle, ...(isAlt ? altRowFill : {}) };
                } else {
                    ws1[cellAddr].s = { ...defaultCell, ...(isAlt ? altRowFill : {}) };
                }
            }
        });

        // Row heights
        ws1['!rows'] = [
            {hpt:28},  // row 1 title
            {hpt:18},  // row 2 subtitle
            {hpt:16},  // row 3 info
            {hpt:6},   // row 4 blank
            {hpt:22},  // row 5 header
            ...dataRows.map(() => ({hpt:18})),
        ];

        XLSX.utils.book_append_sheet(wb, ws1, 'Attendance Log');

        // ════════════════════════════════════
        //  SHEET 2 — SUMMARY
        // ════════════════════════════════════
        const summaryData = [
            ['TrackED — Attendance Summary'],
            [],
            ['Report Period', `${from}  →  ${to}`],
            ['Course Filter', course],
            ['Year Level',    year],
            ['Status Filter', status],
            ['Generated On',  new Date().toLocaleString('en-PH')],
            [],
            ['BREAKDOWN', 'Count', '% of Total'],
            ['On Time',  onTime, App.reportData.length ? (onTime / App.reportData.length * 100).toFixed(1)+'%' : '0%'],
            ['Late',     late,   App.reportData.length ? (late   / App.reportData.length * 100).toFixed(1)+'%' : '0%'],
            ['Absent',   absent, App.reportData.length ? (absent / App.reportData.length * 100).toFixed(1)+'%' : '0%'],
            [],
            ['TYPE BREAKDOWN', 'Count'],
            ['Regular',   App.reportData.length - irreg],
            ['Irregular', irreg],
            [],
            ['TOTAL RECORDS', App.reportData.length],
        ];

        const ws2 = XLSX.utils.aoa_to_sheet(summaryData);
        ws2['!cols'] = [{wch:22}, {wch:14}, {wch:14}];

        // Style summary sheet headers
        const sumTitleStyle = { font:{bold:true,sz:14,color:{rgb:'FFFFFF'}}, fill:{fgColor:{rgb:'1E3A5F'}}, alignment:{horizontal:'center'} };
        const sumHdrStyle   = { font:{bold:true,sz:11,color:{rgb:'FFFFFF'}}, fill:{fgColor:{rgb:'2D5F8A'}}, alignment:{horizontal:'left'} };
        const sumLabelStyle = { font:{bold:true,sz:10}, fill:{fgColor:{rgb:'F1F5F9'}}, border: defaultCell.border };
        const sumValStyle   = { font:{sz:10},           fill:{fgColor:{rgb:'FFFFFF'}}, border: defaultCell.border, alignment:{horizontal:'center'} };

        if (ws2['A1']) { ws2['A1'].s = sumTitleStyle; ws2['!merges'] = [{s:{r:0,c:0},e:{r:0,c:2}}]; }
        [[8,'A9'],[13,'A14']].forEach(([r,addr]) => { if (ws2[addr]) ws2[addr].s = sumHdrStyle; });

        ['A3','A4','A5','A6','A7','B3','B4','B5','B6','B7',
         'A10','A11','A12','B10','B11','B12','C10','C11','C12',
         'A15','A16','B15','B16','A18','B18'].forEach(addr => {
            if (!ws2[addr]) return;
            ws2[addr].s = addr.startsWith('A') ? sumLabelStyle : sumValStyle;
        });

        // Status-colored rows in summary
        if (ws2['A10']) ws2['A10'].s = {...sumLabelStyle, font:{bold:true,color:{rgb:'166534'}}, fill:{fgColor:{rgb:'DCFCE7'}}};
        if (ws2['A11']) ws2['A11'].s = {...sumLabelStyle, font:{bold:true,color:{rgb:'92400E'}}, fill:{fgColor:{rgb:'FEF3C7'}}};
        if (ws2['A12']) ws2['A12'].s = {...sumLabelStyle, font:{bold:true,color:{rgb:'991B1B'}}, fill:{fgColor:{rgb:'FEE2E2'}}};

        if (ws2['A18']) ws2['A18'].s = {...sumHdrStyle, fill:{fgColor:{rgb:'1E3A5F'}}};
        if (ws2['B18']) ws2['B18'].s = {...sumValStyle, font:{bold:true,sz:12}, fill:{fgColor:{rgb:'EFF6FF'}}};

        XLSX.utils.book_append_sheet(wb, ws2, 'Summary');

        // ── Write & Download ─────────────────────────────
        XLSX.writeFile(wb, fname, { bookType:'xlsx', cellStyles: true });
        showToast('Exported!', `${App.reportData.length} records saved as Excel.`, 'success');
    }

    if (window.XLSX) {
        buildWorkbook();
    } else {
        showToast('Loading...', 'Preparing Excel export…', 'info');
        const s = document.createElement('script');
        s.src = sheetJsUrl;
        s.onload = buildWorkbook;
        s.onerror = () => showToast('Error', 'Could not load Excel library.', 'error');
        document.head.appendChild(s);
    }
}

/* ═══════════════════════════
   TOAST
═══════════════════════════ */
function showToast(title, message, type='info', duration=4000) {
    const icons = { success:'ph-check-circle', error:'ph-x-circle', warning:'ph-warning', info:'ph-info' };
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `
        <div class="toast-icon"><i class="ph-fill ${icons[type]||icons.info}"></i></div>
        <div class="toast-content"><div class="toast-title">${esc(title)}</div><div class="toast-msg">${esc(message)}</div></div>
        <button class="toast-close"><i class="ph ph-x"></i></button>`;
    document.getElementById('toast-container')?.appendChild(t);
    t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
    t.dataset.timer = setTimeout(() => dismiss(t), duration);
}
function dismiss(t) {
    clearTimeout(t.dataset.timer); t.classList.add('removing');
    t.addEventListener('animationend', () => t.remove());
}

/* ═══════════════════════════
   INVALID RFID
═══════════════════════════ */
function triggerInvalidRFID(uid) {
    document.getElementById('invalid-rfid-uid').textContent = uid;
    openModal('invalid-rfid-modal');
}

/* ═══════════════════════════
   HELPERS
═══════════════════════════ */
function statusBadge(status) {
    if (!status || status === 'Absent') return `<span class="badge badge-muted"><i class="ph ph-x-circle"></i> Absent</span>`;
    if (status==='On Time') return `<span class="badge badge-success"><i class="ph ph-check-circle"></i> On Time</span>`;
    if (status==='Late')    return `<span class="badge badge-warning"><i class="ph ph-clock-countdown"></i> Late</span>`;
    return `<span class="badge badge-muted">${esc(status)}</span>`;
}
function initials(name) {
    if (!name) return '??';
    const p = name.trim().split(/\s+/);
    return (p.length>=2 ? p[0][0]+p[p.length-1][0] : name.substring(0,2)).toUpperCase();
}
function fmtTime(d) {
    if (!(d instanceof Date)) d = new Date(d);
    const h=(d.getHours()%12||12).toString().padStart(2,'0'), m=d.getMinutes().toString().padStart(2,'0'), s=d.getSeconds().toString().padStart(2,'0'), ap=d.getHours()>=12?'PM':'AM';
    return `${h}:${m}:${s} ${ap}`;
}
function esc(s) {
    if (s==null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function setLoading(btn, on, label) {
    if (!btn) return; btn.disabled = on;
    btn.innerHTML = on ? `<div class="loader-dots" style="gap:3px"><span></span><span></span><span></span></div> ${label}` : label;
}

// Expose for external use (Orange Pi WebSocket hook)
window.TrackED = { triggerInvalidRFID, showToast, fetchLiveScans, fetchStudents };

