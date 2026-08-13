// Global State & API Configuration
const API_BASE = '/api';
const STORAGE_BASE = '/storage';
let authToken = localStorage.getItem('yaan_admin_token') || '';
let currentUser = JSON.parse(localStorage.getItem('yaan_admin_user') || 'null');
let currentOwners = [];
let currentTxnTypeFilter = '';
let currentTransactions = [];

function formatDateClean(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch (e) {
        return dateStr;
    }
}

function formatDateTimeClean(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
    } catch (e) {
        return dateStr;
    }
}

// Initialize Application
document.addEventListener('DOMContentLoaded', () => {
    if (authToken && currentUser) {
        showMainApp();
    } else {
        showLoginScreen();
    }
});

// Authentication Handling
async function handleLogin(event) {
    if (event) event.preventDefault();
    const emailElem = document.getElementById('login-email');
    const passElem = document.getElementById('login-password');
    const roleElem = document.getElementById('login-role');

    const email = emailElem ? emailElem.value.trim() : 'admin@yaan.com';
    const password = passElem ? passElem.value : 'admin123456';
    const role = (roleElem && roleElem.value) ? roleElem.value : 'admin';

    const errorBanner = document.getElementById('login-error');
    const errorText = document.getElementById('login-error-text');
    if (errorBanner) errorBanner.classList.add('hidden');

    const btnLogin = document.getElementById('btn-login');
    if (btnLogin) {
        btnLogin.disabled = true;
        btnLogin.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> authenticating...`;
    }

    try {
        const response = await fetch(`${API_BASE}/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ email, password, role })
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || 'Login failed.');
        }

        if (data.user && data.user.role !== 'admin') {
            throw new Error('Access denied. Admin privileges required.');
        }

        // Save token and user details
        authToken = data.token;
        currentUser = data.user;
        localStorage.setItem('yaan_admin_token', authToken);
        localStorage.setItem('yaan_admin_user', JSON.stringify(currentUser));

        showToast('Login successful! Loading dashboard...', 'success');
        showMainApp();
    } catch (err) {
        if (errorText) errorText.textContent = err.message || 'Login failed';
        if (errorBanner) errorBanner.classList.remove('hidden');
        showToast(err.message || 'Login failed', 'danger');
    } finally {
        if (btnLogin) {
            btnLogin.disabled = false;
            btnLogin.innerHTML = `<span>Sign In to Admin App</span> <i class="fa-solid fa-arrow-right"></i>`;
        }
    }
}

function handleLogout() {
    if (confirm('Are you sure you want to log out?')) {
        if (authToken) {
            fetch(`${API_BASE}/logout`, {
                method: 'POST',
                headers: getHeaders()
            }).catch(() => { });
        }
        authToken = '';
        currentUser = null;
        localStorage.removeItem('yaan_admin_token');
        localStorage.removeItem('yaan_admin_user');
        showLoginScreen();
        showToast('Logged out successfully', 'info');
    }
}

function showLoginScreen() {
    const loginScreen = document.getElementById('login-screen');
    const mainShell = document.getElementById('main-shell');
    if (loginScreen) {
        loginScreen.style.display = 'flex';
        loginScreen.classList.add('active');
    }
    if (mainShell) {
        mainShell.style.display = 'none';
        mainShell.classList.add('hidden');
    }
}

function showMainApp() {
    const loginScreen = document.getElementById('login-screen');
    const mainShell = document.getElementById('main-shell');
    if (loginScreen) {
        loginScreen.style.display = 'none';
        loginScreen.classList.remove('active');
    }
    if (mainShell) {
        mainShell.style.display = 'block';
        mainShell.classList.remove('hidden');
    }
    if (currentUser) {
        const nameDisplay = document.getElementById('admin-name-display');
        if (nameDisplay) nameDisplay.textContent = currentUser.name || 'Admin';
    }
    switchTab('dashboard');
    startBadgeAutoRefresh();
}

function getHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${authToken}`
    };
}

// Navigation & Tab Switcher
function switchTab(tabId, event) {
    if (event) event.preventDefault();

    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.bottom-nav-item').forEach(el => el.classList.remove('active'));

    const targetTab = document.getElementById(`tab-${tabId}`);
    if (targetTab) targetTab.classList.add('active');

    document.querySelectorAll(`[data-tab="${tabId}"]`).forEach(el => el.classList.add('active'));

    // Close mobile drawer if open
    const drawer = document.getElementById('app-drawer');
    const overlay = document.getElementById('drawer-overlay');
    drawer.classList.remove('open');
    overlay.classList.remove('active');

    // Trigger tab specific fetch
    if (tabId === 'dashboard') loadDashboardData();
    if (tabId === 'hotels') loadHotelsData();
    if (tabId === 'owners') loadOwnersData();
    if (tabId === 'bookings') loadBookingsData();
    if (tabId === 'transactions') loadTransactionsData();
    if (tabId === 'users') loadUsersData();
    if (tabId === 'reviews') loadReviewsData();
    if (tabId === 'banners') loadBannersData();
}

function toggleDrawer() {
    const drawer = document.getElementById('app-drawer');
    const overlay = document.getElementById('drawer-overlay');
    drawer.classList.toggle('open');
    overlay.classList.toggle('active');
}

// Helper Debounce function for searches
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Auto refresh timer for drawer badges
let badgeAutoRefreshInterval = null;

function startBadgeAutoRefresh() {
    fetchBadgeCounts();
    if (!badgeAutoRefreshInterval) {
        badgeAutoRefreshInterval = setInterval(fetchBadgeCounts, 10000);
    }
}

async function fetchBadgeCounts() {
    if (!authToken) return;
    try {
        const res = await fetch(`${API_BASE}/admin/dashboard`, { headers: getHeaders() });
        if (!res.ok) return;
        const data = await res.json();
        const m = data.metrics || {};

        const bHotels = document.getElementById('badge-pending-hotels');
        const bOwners = document.getElementById('badge-pending-owners');
        if (bHotels) {
            if (m.pending_hotels > 0) {
                bHotels.textContent = m.pending_hotels;
                bHotels.classList.remove('hidden');
            } else {
                bHotels.classList.add('hidden');
            }
        }

        if (bOwners) {
            if (m.pending_owners > 0) {
                bOwners.textContent = m.pending_owners;
                bOwners.classList.remove('hidden');
            } else {
                bOwners.classList.add('hidden');
            }
        }
    } catch (e) {
        console.error('Badge refresh error:', e);
    }
}

// Chart.js Instances
let chartIncomeGrowthInstance = null;
let chartPaymentDonutInstance = null;

async function loadDashboardData() {
    try {
        const res = await fetch(`${API_BASE}/admin/dashboard`, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const m = data.metrics || {};
        const g = data.goals || {};
        const c = data.charts || {};

        document.getElementById('stat-revenue').textContent = `₹${(m.total_revenue || 0).toLocaleString('en-IN')}`;
        document.getElementById('stat-bookings').textContent = m.confirmed_bookings ?? m.total_bookings ?? 0;
        if (document.getElementById('stat-goal-percentage')) {
            document.getElementById('stat-goal-percentage').textContent = `${g.goal_percentage || 0}%`;
        }
        if (document.getElementById('stat-conversion-rate')) {
            document.getElementById('stat-conversion-rate').textContent = `${m.conversion_rate || 0}%`;
        }

        document.getElementById('stat-users').textContent = m.users_count || 0;
        document.getElementById('stat-owners').textContent = m.owners_count || 0;
        document.getElementById('stat-approved-hotels').textContent = m.approved_hotels || 0;
        if (document.getElementById('stat-cancelled-bookings')) {
            document.getElementById('stat-cancelled-bookings').textContent = m.cancelled_bookings || 0;
        }

        // Render Target Goal Widget
        if (document.getElementById('goal-current-revenue')) {
            document.getElementById('goal-current-revenue').textContent = `₹${(g.current_month_revenue || 0).toLocaleString('en-IN')}`;
            document.getElementById('goal-target-amount').textContent = `₹${(g.target_goal || 500000).toLocaleString('en-IN')}`;
            document.getElementById('goal-progress-text').textContent = `${g.goal_percentage || 0}%`;
            document.getElementById('goal-remaining-amount').textContent = `₹${(g.remaining_goal || 0).toLocaleString('en-IN')}`;
            const progressBar = document.getElementById('goal-progress-bar');
            if (progressBar) {
                progressBar.style.width = `${Math.min(100, g.goal_percentage || 0)}%`;
            }
        }

        // Render Booking Funnel Metrics
        if (document.getElementById('funnel-total')) {
            document.getElementById('funnel-total').textContent = m.all_bookings || 0;
            document.getElementById('funnel-confirmed').textContent = m.confirmed_bookings || 0;
            document.getElementById('funnel-pending').textContent = m.pending_bookings || 0;
            document.getElementById('funnel-cancelled').textContent = m.cancelled_bookings || 0;
        }

        // Render Charts & Top Hotels
        renderIncomeGrowthChart(c);
        renderPaymentDonutChart(c.payment_sources);
        renderTopHotels(data.top_hotels);

        // Badges in drawer
        const bHotels = document.getElementById('badge-pending-hotels');
        const bOwners = document.getElementById('badge-pending-owners');
        if (bHotels) {
            if (m.pending_hotels > 0) {
                bHotels.textContent = m.pending_hotels;
                bHotels.classList.remove('hidden');
            } else {
                bHotels.classList.add('hidden');
            }
        }

        if (bOwners) {
            if (m.pending_owners > 0) {
                bOwners.textContent = m.pending_owners;
                bOwners.classList.remove('hidden');
            } else {
                bOwners.classList.add('hidden');
            }
        }

        renderRecentBookings(data.recent_bookings);
        renderRecentHotels(data.recent_hotels);
    } catch (err) {
        console.error('Failed to load dashboard:', err);
    }
}

function renderIncomeGrowthChart(chartsData) {
    const canvas = document.getElementById('chart-income-growth');
    if (!canvas || typeof Chart === 'undefined') return;

    const ctx = canvas.getContext('2d');
    if (chartIncomeGrowthInstance) {
        chartIncomeGrowthInstance.destroy();
    }

    const labels = (chartsData && chartsData.labels) ? chartsData.labels : ['Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'];
    const incomeData = (chartsData && chartsData.income_series) ? chartsData.income_series : [0, 0, 0, 0, 0, 0];

    const gradient = ctx.createLinearGradient(0, 0, 0, 240);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.4)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    chartIncomeGrowthInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Monthly Income (₹)',
                data: incomeData,
                borderColor: '#6366f1',
                borderWidth: 3,
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#38bdf8',
                pointBorderColor: '#fff',
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ' Income: ₹' + Number(context.raw).toLocaleString('en-IN');
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#94a3b8' }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: {
                        color: '#94a3b8',
                        callback: function(value) {
                            return '₹' + (value >= 1000 ? (value / 1000).toFixed(0) + 'k' : value);
                        }
                    }
                }
            }
        }
    });
}

function renderPaymentDonutChart(sources) {
    const canvas = document.getElementById('chart-payment-donut');
    if (!canvas || typeof Chart === 'undefined') return;

    const ctx = canvas.getContext('2d');
    if (chartPaymentDonutInstance) {
        chartPaymentDonutInstance.destroy();
    }

    const online = sources ? (sources.online_razorpay || 0) : 0;
    const offline = sources ? (sources.pay_at_hotel || 0) : 0;

    chartPaymentDonutInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Razorpay Online', 'Pay-at-Hotel'],
            datasets: [{
                data: [online, offline],
                backgroundColor: ['#6366f1', '#38bdf8'],
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#94a3b8', boxWidth: 12, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.label + ': ₹' + Number(context.raw).toLocaleString('en-IN');
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });
}

function renderTopHotels(topHotels) {
    const container = document.getElementById('top-hotels-container');
    if (!container) return;
    if (!topHotels || topHotels.length === 0) {
        container.innerHTML = `<div class="empty-state">No hotel performance data yet</div>`;
        return;
    }

    container.innerHTML = topHotels.map((h, index) => `
        <div style="background:var(--bg-dark); padding:8px 12px; border-radius:6px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; font-size:12px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:800; color:var(--primary); font-size:13px;">#${index + 1}</span>
                <div>
                    <strong style="color:var(--text-primary); display:block;">${h.name}</strong>
                    <span style="font-size:11px; color:var(--text-muted);">${h.city || 'Location'} • <i class="fa-solid fa-star" style="color:#facc15;"></i> ${h.rating || '4.5'}</span>
                </div>
            </div>
            <div style="text-align:right;">
                <strong style="color:var(--success); font-size:13px;">${h.bookings_count || 0} Bookings</strong>
            </div>
        </div>
    `).join('');
}

function renderRecentBookings(bookings) {
    const container = document.getElementById('recent-bookings-list');
    if (!bookings || bookings.length === 0) {
        container.innerHTML = `<div class="empty-state">No recent bookings</div>`;
        return;
    }

    container.innerHTML = bookings.map(b => `
        <div class="sub-card">
            <i class="fa-solid fa-receipt"></i>
            <div style="flex:1">
                <strong>${b.hotel ? b.hotel.name : 'Hotel'}</strong>
                <span>${b.user ? b.user.name : 'Guest'} • ₹${b.total_amount}</span>
            </div>
            <span class="badge ${b.status}">${b.status}</span>
        </div>
    `).join('');
}

function renderRecentHotels(hotels) {
    const container = document.getElementById('recent-hotels-list');
    if (!hotels || hotels.length === 0) {
        container.innerHTML = `<div class="empty-state">No recent hotels</div>`;
        return;
    }

    container.innerHTML = hotels.map(h => `
        <div class="sub-card">
            <i class="fa-solid fa-hotel"></i>
            <div style="flex:1">
                <strong>${h.name}</strong>
                <span>${h.city} • ₹${h.price_per_night}/night</span>
            </div>
            <span class="badge ${h.status}">${h.status}</span>
        </div>
    `).join('');
}

// ----------------------------------------------------
// 2. HOTELS MANAGEMENT
// ----------------------------------------------------
let currentHotelStatusFilter = 'all';

function formatDateClean(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr.split('T')[0] || dateStr;
        return d.toLocaleDateString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    } catch(e) {
        return dateStr.split('T')[0] || dateStr;
    }
}

function formatDateTimeClean(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr.split('T')[0] || dateStr;
        return d.toLocaleDateString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
    } catch(e) {
        return dateStr.split('T')[0] || dateStr;
    }
}

function filterHotelsByStatus(status, btnElem) {
    currentHotelStatusFilter = status;
    document.querySelectorAll('#tab-hotels .subtab-btn').forEach(b => b.classList.remove('active'));
    if (btnElem) btnElem.classList.add('active');
    loadHotelsData();
}

let loadedCitiesList = false;
async function populateHotelCitiesDropdown(hotels) {
    const citySelect = document.getElementById('filter-hotel-city');
    if (!citySelect || loadedCitiesList) return;

    try {
        const res = await fetch(`${API_BASE}/admin/hotels/locations`, { headers: getHeaders() });
        if (res.ok) {
            const data = await res.json();
            const cities = data.cities || [];
            if (cities.length > 0) {
                const currentVal = citySelect.value;
                citySelect.innerHTML = `<option value="">All Cities (${cities.length})</option>` +
                    cities.map(c => `<option value="${c}" ${currentVal === c ? 'selected' : ''}>${c}</option>`).join('');
                loadedCitiesList = true;
            }
        }
    } catch (e) { }
}

function getHotelImageUrl(imgObj) {
    if (!imgObj) return null;
    let path = typeof imgObj === 'string' ? imgObj : (imgObj.url || imgObj.image_path);
    if (!path) return null;
    if (path.startsWith('data:') || path.startsWith('http://') || path.startsWith('https://')) return path;
    const clean = path.replace(/^\/?storage\//, '').replace(/^\//, '');
    return `${STORAGE_BASE}/${clean}`;
}

function getHotelPhotosOnly(hotel) {
    const images = [];

    // Check owner registration / profile photo FIRST so that it is guaranteed to show
    const profile = (hotel.owner && (hotel.owner.owner_profile || hotel.owner.ownerProfile)) 
        ? (hotel.owner.owner_profile || hotel.owner.ownerProfile) 
        : null;

    if (profile) {
        const registrationPhoto = profile.business_proof || profile.aadhaar_front || profile.gst_image || profile.pan_card;
        const proofUrl = getHotelImageUrl(registrationPhoto);
        if (proofUrl) {
            images.push({ id: null, url: proofUrl, label: 'Registration Photo' });
        }
    }

    if (hotel.images && hotel.images.length > 0) {
        hotel.images.forEach(img => {
            const url = getHotelImageUrl(img);
            if (url && !images.some(i => i.url === url)) {
                images.push({ id: img.id, url: url, label: 'Hotel Photo' });
            }
        });
    }

    if (hotel.primary_image) {
        const url = getHotelImageUrl(hotel.primary_image);
        if (url && !images.some(i => i.url === url)) {
            images.unshift({ id: hotel.primary_image.id, url: url, label: 'Primary Photo' });
        }
    }

    // Sort valid inline base64 data URIs first (100% working images)
    images.sort((a, b) => {
        const aIsBase64 = a.url && a.url.startsWith('data:');
        const bIsBase64 = b.url && b.url.startsWith('data:');
        if (aIsBase64 && !bIsBase64) return -1;
        if (!aIsBase64 && bIsBase64) return 1;

        const aIsReg = a.label === 'Registration Photo';
        const bIsReg = b.label === 'Registration Photo';
        if (aIsReg && !bIsReg) return -1;
        if (!aIsReg && bIsReg) return 1;

        return 0;
    });

    return images;
}

function handleCardImageFallback(imgElem, hotelId) {
    const card = imgElem.closest('.data-card');
    const photosJson = imgElem.getAttribute('data-photos');
    let photos = [];
    try {
        photos = photosJson ? JSON.parse(photosJson) : [];
    } catch(e) {}

    let currentIndex = parseInt(imgElem.getAttribute('data-photo-index') || '0');
    currentIndex++;

    if (photos && currentIndex < photos.length && photos[currentIndex] && photos[currentIndex].url) {
        imgElem.setAttribute('data-photo-index', currentIndex);
        imgElem.src = photos[currentIndex].url;
        const labelBadge = card ? card.querySelector('.photo-label-badge') : null;
        if (labelBadge) labelBadge.textContent = photos[currentIndex].label || 'Hotel Photo';
    } else {
        imgElem.style.display = 'none';
        const placeholder = document.getElementById(`card-img-placeholder-${hotelId}`);
        if (placeholder) placeholder.style.setProperty('display', 'flex', 'important');
    }
}

async function loadHotelsData() {
    const container = document.getElementById('hotels-container');
    const searchInput = document.getElementById('search-hotels');
    const citySelect = document.getElementById('filter-hotel-city');
    const stateSelect = document.getElementById('filter-hotel-state');

    const search = searchInput ? searchInput.value : '';
    const city = citySelect ? citySelect.value : '';
    const state = stateSelect ? stateSelect.value : '';

    container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Searching & loading hotel listings...</div>`;

    try {
        let url = `${API_BASE}/admin/hotels?status=${currentHotelStatusFilter}&search=${encodeURIComponent(search)}&city=${encodeURIComponent(city)}&state=${encodeURIComponent(state)}`;
        const res = await fetch(url, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const hotels = data.data || [];

        populateHotelCitiesDropdown(hotels);

        if (hotels.length === 0) {
            container.innerHTML = `
                <div class="empty-state" style="padding:40px 20px; text-align:center; grid-column:1/-1;">
                    <i class="fa-solid fa-hotel-slash" style="font-size:36px; color:var(--text-muted); margin-bottom:12px;"></i>
                    <h3 style="margin:0 0 6px 0; font-size:16px;">No Hotels Found</h3>
                    <p style="color:var(--text-muted); font-size:13px; margin:0;">No hotel listings match your current search ("${search || 'all'}"), city ("${city || 'all'}"), state ("${state || 'all'}"), or status filter.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = hotels.map(h => {
            const isApproved = h.status === 'approved' || h.status === 'active';
            const isPending = h.status === 'pending';

            const hotelPhotos = getHotelPhotosOnly(h);
            const primaryImgObj = hotelPhotos.length > 0 ? hotelPhotos[0] : null;

            let statusBadgeClass = isApproved ? 'confirmed' : (isPending ? 'pending' : 'failed');
            let statusText = isApproved ? 'APPROVED' : (isPending ? 'PENDING APPROVAL' : 'REJECTED / SUSPENDED');

            return `
                <div class="data-card" style="display:flex; flex-direction:column; justify-content:space-between; position:relative; overflow:hidden;">
                    <div>
                        <div style="position:relative; height:160px; border-radius:8px; overflow:hidden; margin-bottom:12px; background:#0f172a;" id="card-img-container-${h.id}">
                            ${primaryImgObj ? `
                                <img src="${primaryImgObj.url}" alt="${h.name}" 
                                     data-photos='${JSON.stringify(hotelPhotos).replace(/'/g, "&apos;")}' 
                                     data-photo-index="0" 
                                     style="width:100%; height:100%; object-fit:cover;" 
                                     onerror="handleCardImageFallback(this, ${h.id})">
                                <div id="card-img-placeholder-${h.id}" style="display:none; flex-direction:column; align-items:center; justify-content:center; height:100%; color:var(--text-muted); font-size:12px;">
                                    <i class="fa-solid fa-hotel" style="font-size:24px; margin-bottom:6px; color:#475569;"></i>
                                    <span>No Hotel Photo Uploaded</span>
                                </div>
                                <span class="photo-label-badge" style="position:absolute; bottom:6px; right:8px; background:rgba(0,0,0,0.8); color:#38bdf8; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700;">
                                    ${primaryImgObj.label}
                                </span>
                            ` : `
                                <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:var(--text-muted); font-size:12px;">
                                    <i class="fa-solid fa-hotel" style="font-size:24px; margin-bottom:6px; color:#475569;"></i>
                                    <span>No Hotel Photo Uploaded</span>
                                </div>
                            `}
                            <span class="badge ${statusBadgeClass}" style="position:absolute; top:8px; right:8px; font-size:10px; font-weight:700; ${!isApproved && !isPending ? 'background:#e11d48; color:#fff;' : ''}">
                                ${statusText}
                            </span>
                            <div style="position:absolute; bottom:6px; left:8px; background:rgba(0,0,0,0.75); color:#f59e0b; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">
                                <i class="fa-solid fa-star"></i> ${h.rating || '4.5'} (${h.review_count || 0})
                            </div>
                        </div>

                        <h4 style="margin:0 0 4px 0; font-size:16px; color:var(--text-primary); font-weight:700;">${h.name}</h4>
                        <div style="font-size:12px; color:#38bdf8; font-weight:600; margin-bottom:6px;">
                            <i class="fa-solid fa-location-dot"></i> ${h.city || 'N/A'} • <span style="color:var(--text-muted); font-weight:normal;">${h.address || ''}</span>
                        </div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-bottom:12px;">
                            <i class="fa-solid fa-user-tie"></i> Owner: <strong>${h.owner ? h.owner.name : 'N/A'}</strong> (${h.owner ? h.owner.phone : 'N/A'})
                        </div>
                    </div>

                    <div style="border-top:1px solid var(--border); padding-top:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <div>
                            <span style="font-size:15px; font-weight:700; color:var(--success);">₹${h.price_per_night}</span>
                            <small style="font-size:10px; color:var(--text-muted);">/ night</small>
                            <div style="font-size:10px; color:var(--text-muted);">${h.available_rooms || 0} / ${h.total_rooms || 0} Rooms Available</div>
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button class="btn-sm" style="background:#4f46e5; color:#fff;" onclick="openHotelDetailsModal(${h.id})">
                                <i class="fa-solid fa-eye"></i> Details
                            </button>
                            ${isPending ? `
                                <button class="btn-sm" style="background:#10b981; color:#fff;" onclick="updateHotelStatus(${h.id}, 'approved')">
                                    <i class="fa-solid fa-check"></i> Approve
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    } catch (err) {
        container.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading hotels'}</div>`;
    }
}

async function updateHotelStatus(id, newStatus) {
    if (!newStatus) return;

    try {
        const res = await fetch(`${API_BASE}/admin/hotels/${id}/status`, {
            method: 'PUT',
            headers: getHeaders(),
            body: JSON.stringify({ status: newStatus })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to update hotel status');

        showToast(data.message || `Hotel status updated to ${newStatus}`, 'success');
        loadHotelsData();
        fetchBadgeCounts();
    } catch (err) {
        showToast(err.message, 'danger');
        loadHotelsData();
    }
}

async function openHotelDetailsModal(id) {
    const modal = document.getElementById('hotel-modal');
    const body = document.getElementById('hotel-modal-body');
    if (!modal || !body) return;

    body.innerHTML = `<div style="padding:20px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Loading Hotel Details & Gallery...</div>`;
    modal.classList.remove('hidden');

    try {
        const res = await fetch(`${API_BASE}/admin/hotels/${id}`, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const h = data.hotel;
        const analytics = data.analytics || {};
        const visitingCustomers = data.visiting_customers || [];
        const isApproved = h.status === 'approved' || h.status === 'active';
        const isPending = h.status === 'pending';

        const hotelPhotos = getHotelPhotosOnly(h);

        body.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                <div>
                    <h3 style="margin:0; font-size:18px; color:var(--text-primary);">
                        ${h.name}
                        <span class="badge ${isApproved ? 'confirmed' : (isPending ? 'pending' : 'failed')}" style="margin-left:8px;">
                            ${isApproved ? 'APPROVED' : (isPending ? 'PENDING APPROVAL' : 'REJECTED / SUSPENDED')}
                        </span>
                    </h3>
                    <div style="font-size:12px; color:#38bdf8; font-weight:600; margin-top:2px;">
                        <i class="fa-solid fa-location-dot"></i> ${h.city || 'N/A'} • ${h.address || 'N/A'}
                    </div>
                </div>
                <div style="display:flex; gap:6px;">
                    <button class="btn-sm" style="background:#10b981; color:#fff;" onclick="updateHotelStatus(${h.id}, 'approved'); closeHotelModal();">
                        <i class="fa-solid fa-check-circle"></i> Approve Hotel
                    </button>
                    <button class="btn-sm" style="background:#f59e0b; color:#fff;" onclick="updateHotelStatus(${h.id}, 'suspended'); closeHotelModal();">
                        <i class="fa-solid fa-pause"></i> Suspend
                    </button>
                    <button class="btn-sm" style="background:#e11d48; color:#fff;" onclick="updateHotelStatus(${h.id}, 'rejected'); closeHotelModal();">
                        <i class="fa-solid fa-ban"></i> Reject
                    </button>
                </div>
            </div>

            <!-- Hotel Property Photos Gallery -->
            <div style="margin-bottom:14px; background:var(--bg-dark); padding:14px; border-radius:8px; border:1px solid var(--border);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h5 style="margin:0; font-size:12px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">
                        <i class="fa-solid fa-camera"></i> Hotel Property Photos (${hotelPhotos.length})
                    </h5>
                    <label class="btn-sm" style="background:#0284c7; color:#fff; cursor:pointer; padding:4px 10px; font-size:11px; border-radius:4px; margin:0; display:inline-flex; align-items:center; gap:4px;">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Upload Photo
                        <input type="file" id="upload-hotel-photo-input" accept="image/*" style="display:none;" onchange="uploadAdminHotelPhoto(${h.id}, this)">
                    </label>
                </div>
                ${hotelPhotos.length > 0 ? `
                    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:10px; max-height:220px; overflow-y:auto;">
                        ${hotelPhotos.map(img => `
                            <div class="photo-card-item" style="position:relative; height:130px; border-radius:8px; overflow:hidden; background:#0f172a; border:1px solid var(--border);">
                                <a href="${img.url}" target="_blank">
                                    <img src="${img.url}" alt="${img.label}" style="width:100%; height:100%; object-fit:cover;" onerror="this.onerror=null; this.closest('.photo-card-item')?.style.setProperty('display', 'none', 'important');">
                                </a>
                                <span style="position:absolute; bottom:6px; left:6px; background:rgba(0,0,0,0.85); color:#38bdf8; font-size:10px; padding:2px 6px; border-radius:4px; font-weight:700;">
                                    ${img.label}
                                </span>
                                ${img.id ? `
                                    <button onclick="deleteAdminHotelPhoto(${h.id}, ${img.id})" style="position:absolute; top:6px; right:6px; background:rgba(225,29,72,0.9); color:#fff; border:none; width:22px; height:22px; border-radius:50%; cursor:pointer; font-size:10px; display:flex; align-items:center; justify-content:center;" title="Delete Image">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                ` : ''}
                            </div>
                        `).join('')}
                    </div>
                ` : `
                    <div style="padding:16px; border:1px dashed var(--border); border-radius:6px; text-align:center; color:var(--text-muted); font-size:12px;">
                        <i class="fa-solid fa-hotel" style="font-size:24px; color:#475569; margin-bottom:6px; display:block;"></i>
                        No hotel property photos uploaded yet by owner. Click <strong>Upload Photo</strong> above to add hotel images.
                    </div>
                `}
            </div>

            <!-- Hotel Financial Performance & Revenue Grid -->
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; background:var(--bg-dark); padding:14px; border-radius:8px; border:1px solid var(--border); margin-bottom:14px;">
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Hotel Revenue</span>
                    <h3 style="margin:4px 0 0 0; color:var(--success); font-size:18px;">₹${(analytics.total_revenue || 0).toLocaleString('en-IN')}</h3>
                </div>
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total Bookings</span>
                    <h3 style="margin:4px 0 0 0; color:#38bdf8; font-size:18px;">${analytics.total_bookings || 0}</h3>
                </div>
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Confirmed Check-ins</span>
                    <h3 style="margin:4px 0 0 0; color:#10b981; font-size:18px;">${analytics.confirmed_bookings || 0}</h3>
                </div>
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Cancelled / Refunded</span>
                    <h3 style="margin:4px 0 0 0; color:#f43f5e; font-size:18px;">${analytics.cancelled_bookings || 0}</h3>
                </div>
            </div>

            <!-- Room Capacity & Available Slot Management Control -->
            <div style="background:var(--bg-dark); padding:14px; border-radius:8px; border:1px solid var(--border); margin-bottom:14px;">
                <h5 style="margin:0 0 10px 0; font-size:13px; color:#38bdf8; font-weight:700;">
                    <i class="fa-solid fa-bed"></i> Room Inventory & Available Slots Control
                </h5>
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr auto; gap:10px; align-items:end;">
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); font-weight:700; display:block; margin-bottom:4px;">Total Rooms</label>
                        <input type="number" id="edit-hotel-total-rooms" value="${h.total_rooms || 10}" min="1" style="width:100%; background:#0f172a; border:1px solid var(--border); color:#fff; padding:6px 10px; border-radius:6px; font-weight:700; font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); font-weight:700; display:block; margin-bottom:4px;">Available Slots</label>
                        <input type="number" id="edit-hotel-avail-rooms" value="${h.available_rooms || 10}" min="0" style="width:100%; background:#0f172a; border:1px solid var(--border); color:#fff; padding:6px 10px; border-radius:6px; font-weight:700; font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); font-weight:700; display:block; margin-bottom:4px;">Price / Night (₹)</label>
                        <input type="number" id="edit-hotel-price" value="${h.price_per_night || 1500}" min="1" style="width:100%; background:#0f172a; border:1px solid var(--border); color:#fff; padding:6px 10px; border-radius:6px; font-weight:700; font-size:13px;">
                    </div>
                    <div>
                        <button class="btn-sm" style="background:#6366f1; color:#fff; padding:8px 14px; border-radius:6px; font-weight:700;" onclick="saveHotelSlotSettings(${h.id})">
                            <i class="fa-solid fa-floppy-disk"></i> Save Slots
                        </button>
                    </div>
                </div>
            </div>

            <!-- Specs Grid -->
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); margin-bottom:14px; font-size:12px;">
                <div><strong>Price Per Night:</strong> <br><span style="color:var(--success); font-weight:700; font-size:14px;">₹${h.price_per_night}</span></div>
                <div><strong>Room Capacity:</strong> <br><span style="color:#38bdf8; font-weight:700;">${h.available_rooms || 0} / ${h.total_rooms || 0} Available Slots</span></div>
                <div><strong>Rating & Reviews:</strong> <br><span style="color:#f59e0b; font-weight:700;"><i class="fa-solid fa-star"></i> ${h.rating || '4.5'} (${h.review_count || 0})</span></div>
            </div>

            <!-- Owner Info & Description -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px; font-size:12px;">
                <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border);">
                    <h5 style="margin:0 0 6px 0; font-size:13px; color:var(--info);"><i class="fa-solid fa-user-tie"></i> Hotel Owner Info</h5>
                    <div><strong>Name:</strong> ${h.owner ? h.owner.name : 'N/A'}</div>
                    <div><strong>Email:</strong> ${h.owner ? h.owner.email : 'N/A'}</div>
                    <div><strong>Phone:</strong> ${h.owner ? h.owner.phone : 'N/A'}</div>
                </div>
                <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border);">
                    <h5 style="margin:0 0 6px 0; font-size:13px; color:var(--primary);"><i class="fa-solid fa-concierge-bell"></i> Description</h5>
                    <div style="color:var(--text-secondary); line-height:1.4;">${h.description || 'No detailed description provided by owner.'}</div>
                </div>
            </div>

            <!-- Visiting Customers List -->
            <h4 style="margin:0 0 8px 0; font-size:14px; color:#a855f7;"><i class="fa-solid fa-users"></i> Visiting Customers & Check-in History (${visitingCustomers.length})</h4>
            ${visitingCustomers.length === 0 ? `
                <div style="padding:10px; background:var(--bg-dark); border-radius:6px; font-size:12px; color:var(--text-muted); margin-bottom:14px;">No customer check-ins recorded at this hotel yet.</div>
            ` : `
                <div style="max-height:180px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; margin-bottom:14px;">
                    ${visitingCustomers.map(b => `
                        <div style="background:var(--bg-dark); padding:10px 12px; border-radius:6px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; font-size:12px; gap:10px;">
                            <div>
                                <div style="font-size:13px; font-weight:700; color:var(--text-primary); margin-bottom:3px;">
                                    <i class="fa-solid fa-user" style="color:var(--primary);"></i> ${b.user ? b.user.name : (b.logistics_name || 'Guest Driver')} 
                                    <span style="color:#38bdf8; font-weight:600; margin-left:6px; font-size:12px;">
                                        <i class="fa-solid fa-phone"></i> ${b.user ? (b.user.phone || b.logistics_number || 'N/A') : (b.logistics_number || 'N/A')}
                                    </span>
                                </div>
                                <div style="font-size:11px; color:var(--text-secondary); display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                                    <span><i class="fa-solid fa-calendar-day" style="color:var(--info);"></i> Check-in: <strong>${formatDateClean(b.check_in || b.booking_date)}</strong></span>
                                    <span><i class="fa-solid fa-calendar-check" style="color:var(--success);"></i> Check-out: <strong>${formatDateClean(b.check_out)}</strong></span>
                                    ${b.created_at ? `<span><i class="fa-solid fa-clock" style="color:var(--warning);"></i> Booked: <strong>${formatDateTimeClean(b.created_at)}</strong></span>` : ''}
                                </div>
                            </div>
                            <div style="text-align:right; flex-shrink:0;">
                                <strong style="color:var(--success); font-size:14px;">₹${(b.total_payable || b.total_amount || 0).toLocaleString('en-IN')}</strong><br>
                                <span class="badge ${b.status === 'confirmed' ? 'confirmed' : (b.payment_status === 'refunded' ? 'failed' : 'pending')}" style="font-size:10px; margin-top:2px;">
                                    ${b.payment_status === 'refunded' ? 'REFUNDED' : b.status.toUpperCase()}
                                </span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `}

            <!-- Amenities -->
            ${(h.amenities || []).length > 0 ? `
                <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); font-size:12px; margin-bottom:14px;">
                    <h5 style="margin:0 0 8px 0; font-size:13px; color:#a855f7;"><i class="fa-solid fa-wifi"></i> Hotel Amenities</h5>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        ${h.amenities.map(a => `<span style="background:rgba(168,85,247,0.15); color:#c084fc; padding:4px 8px; border-radius:4px; border:1px solid rgba(168,85,247,0.3); font-weight:600;"><i class="fa-solid fa-check"></i> ${a.name}</span>`).join('')}
                    </div>
                </div>
            ` : ''}

            <!-- Customer Reviews for this Hotel -->
            <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); font-size:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h5 style="margin:0; font-size:13px; color:#f59e0b;"><i class="fa-solid fa-star"></i> Customer Reviews (${(h.reviews || []).length})</h5>
                    <span style="color:#f59e0b; font-weight:700;"><i class="fa-solid fa-star"></i> ${h.rating || '0.0'} / 5.0 (${h.review_count || (h.reviews || []).length} reviews)</span>
                </div>
                ${(!h.reviews || h.reviews.length === 0) ? `
                    <div style="font-size:12px; color:var(--text-muted);">No reviews posted for this hotel yet.</div>
                ` : `
                    <div style="max-height:220px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">
                        ${h.reviews.map(r => `
                            <div style="background:var(--bg-card); padding:10px 12px; border-radius:6px; border:1px solid var(--border);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <strong><i class="fa-solid fa-user" style="color:var(--primary);"></i> ${r.user ? r.user.name : (r.user_name || 'Customer')}</strong>
                                    <span class="badge approved" style="font-size:11px;"><i class="fa-solid fa-star"></i> ${r.rating} / 5</span>
                                </div>
                                <p style="margin:4px 0; color:var(--text-secondary); font-size:12px; line-height:1.4;">"${r.comment || 'No text comment provided.'}"</p>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; font-size:11px; color:var(--text-muted);">
                                    <span>${formatDateTimeClean(r.created_at)}</span>
                                    <button class="btn-sm btn-danger" style="padding:2px 8px; font-size:10px;" onclick="deleteReview(${r.id}); openHotelDetailsModal(${h.id});">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `}
            </div>
        `;
    } catch (err) {
        body.innerHTML = `<div style="padding:20px; text-align:center; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Failed to load hotel details'}</div>`;
    }
}

function closeHotelModal() {
    const modal = document.getElementById('hotel-modal');
    if (modal) modal.classList.add('hidden');
}

async function saveHotelSlotSettings(id) {
    const totalRooms = document.getElementById('edit-hotel-total-rooms').value;
    const availRooms = document.getElementById('edit-hotel-avail-rooms').value;
    const price = document.getElementById('edit-hotel-price').value;

    try {
        const res = await fetch(`${API_BASE}/admin/hotels/${id}/status`, {
            method: 'PUT',
            headers: getHeaders(),
            body: JSON.stringify({
                total_rooms: parseInt(totalRooms),
                available_rooms: parseInt(availRooms),
                price_per_night: parseFloat(price)
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to update slot inventory');

        showToast(data.message || 'Hotel slots and inventory updated successfully!', 'success');
        openHotelDetailsModal(id);
        loadHotelsData();
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

async function uploadAdminHotelPhoto(hotelId, input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('image', file);

    try {
        const res = await fetch(`${API_BASE}/admin/hotels/${hotelId}/images`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` },
            body: formData
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to upload photo');
        showToast('Hotel photo uploaded successfully!', 'success');
        openHotelDetailsModal(hotelId);
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

async function deleteAdminHotelPhoto(hotelId, imageId) {
    if (!confirm('Are you sure you want to delete this hotel photo?')) return;
    try {
        const res = await fetch(`${API_BASE}/admin/hotels/${hotelId}/images/${imageId}`, {
            method: 'DELETE',
            headers: getHeaders()
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to delete photo');
        showToast('Photo deleted successfully!', 'success');
        openHotelDetailsModal(hotelId);
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

        // ----------------------------------------------------
        // 3. OWNER KYC & PERFORMANCE MANAGEMENT
        // ----------------------------------------------------
        let currentOwnerStatusFilter = 'false'; // Default view: Pending KYC for new registrations

        function filterOwnersByStatus(verifiedState, btnElem) {
            currentOwnerStatusFilter = verifiedState;
            document.querySelectorAll('#tab-owners .subtab-btn').forEach(b => b.classList.remove('active'));
            if (btnElem) btnElem.classList.add('active');
            loadOwnersData();
        }

        let loadedOwnerCitiesList = false;
        async function populateOwnerCitiesDropdown() {
            const citySelect = document.getElementById('filter-owner-city');
            if (!citySelect || loadedOwnerCitiesList) return;

            try {
                const res = await fetch(`${API_BASE}/admin/hotels/locations`, { headers: getHeaders() });
                if (res.ok) {
                    const data = await res.json();
                    const cities = data.cities || [];
                    if (cities.length > 0) {
                        const currentVal = citySelect.value;
                        citySelect.innerHTML = `<option value="">All Cities (${cities.length})</option>` +
                            cities.map(c => `<option value="${c}" ${currentVal === c ? 'selected' : ''}>${c}</option>`).join('');
                        loadedOwnerCitiesList = true;
                    }
                }
            } catch (e) { }
        }

        async function loadOwnersData() {
            const container = document.getElementById('owners-container');
            const searchInput = document.getElementById('search-owners');
            const citySelect = document.getElementById('filter-owner-city');

            const search = searchInput ? searchInput.value : '';
            const city = citySelect ? citySelect.value : '';

            container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Loading hotel owner profiles...</div>`;

            try {
                let url = `${API_BASE}/admin/owners?verified=${currentOwnerStatusFilter}&search=${encodeURIComponent(search)}&city=${encodeURIComponent(city)}`;
                const res = await fetch(url, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                currentOwners = data.data || [];

                populateOwnerCitiesDropdown();

                if (currentOwners.length === 0) {
                    container.innerHTML = `
                <div class="empty-state" style="padding:40px 20px; text-align:center; grid-column:1/-1;">
                    <i class="fa-solid fa-user-slash" style="font-size:36px; color:var(--text-muted); margin-bottom:12px;"></i>
                    <h3 style="margin:0 0 6px 0; font-size:16px;">No Hotel Owners Found</h3>
                    <p style="color:var(--text-muted); font-size:13px; margin:0;">No hotel owner accounts match your current search ("${search || 'all'}"), city ("${city || 'all'}"), or verification filter.</p>
                </div>
            `;
                    return;
                }

                container.innerHTML = currentOwners.map(o => {
                    const profile = o.owner_profile || o.ownerProfile || {};
                    const isVerified = (o.is_verified === true || o.is_verified === 1) && (profile.is_profile_complete === true || profile.is_profile_complete === 1);
                    const joinedDate = o.created_at ? new Date(o.created_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : 'N/A';

                    return `
            <div class="data-card" style="display:flex; flex-direction:column; justify-content:space-between; position:relative;">
                <div>
                    <div class="data-card-header" style="margin-bottom:10px;">
                        <div class="data-card-title">
                            <h4 style="font-size:16px; color:var(--text-primary); margin:0;">
                                <i class="fa-solid fa-user-tie" style="color:var(--primary);"></i> ${o.name}
                            </h4>
                            <div class="data-card-sub" style="font-size:11px; margin-top:2px;">Joined: ${joinedDate}</div>
                        </div>
                        <span class="badge ${isVerified ? 'confirmed' : 'pending'}" style="font-size:11px; ${!isVerified ? 'background:#f59e0b; color:#000;' : ''}">
                            <i class="fa-solid ${isVerified ? 'fa-circle-check' : 'fa-clock'}"></i> ${isVerified ? 'Verified Owner' : 'Pending KYC'}
                        </span>
                    </div>

                    <div style="font-size:12px; color:var(--text-secondary); display:flex; flex-direction:column; gap:6px; background:var(--bg-dark); padding:12px; border-radius:6px; border:1px solid var(--border); margin-bottom:12px;">
                        <div><i class="fa-solid fa-hotel" style="color:var(--success); font-size:12px; margin-right:4px;"></i> <strong>Hotel Property:</strong> <span style="color:var(--text-primary); font-weight:700;">${profile.hotel_name || 'N/A'}</span></div>
                        <div><i class="fa-solid fa-location-dot" style="color:#38bdf8; font-size:12px; margin-right:4px;"></i> <strong>Location:</strong> ${profile.city || 'N/A'}${profile.state ? ', ' + profile.state : ''}</div>
                        <div><i class="fa-solid fa-building-user" style="color:#a855f7; font-size:12px; margin-right:4px;"></i> <strong>Listings:</strong> ${o.hotels_count || 0} Registered Hotels</div>
                    </div>
                </div>

                <div style="display:flex; gap:6px; flex-wrap:wrap; border-top:1px solid var(--border); padding-top:10px; margin-top:auto;">
                    <button class="btn-sm" style="background:#4f46e5; color:white; font-weight:600;" onclick="openKycModal(${o.id})">
                        <i class="fa-solid fa-chart-line"></i> Inspect & Analytics
                    </button>
                    ${!isVerified ?
                            `<button class="btn-sm btn-success" onclick="verifyOwner(${o.id}, true)"><i class="fa-solid fa-user-check"></i> Approve KYC</button>` :
                            `<button class="btn-sm btn-warning" onclick="verifyOwner(${o.id}, false)"><i class="fa-solid fa-user-xmark"></i> Revoke</button>`
                        }
                    <button class="btn-sm" style="background:#e11d48; color:white;" onclick="resetOwnerKyc(${o.id})" title="Remove & Reset Owner KYC Documents"><i class="fa-solid fa-trash-can"></i> Reset</button>
                </div>
            </div>
            `;
                }).join('');
            } catch (err) {
                container.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading owners'}</div>`;
            }
        }

        async function openKycModal(id) {
            const modal = document.getElementById('kyc-modal');
            const body = document.getElementById('kyc-modal-body');
            if (!modal || !body) return;

            body.innerHTML = `<div style="padding:30px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Fetching Owner Profile, Financial Performance & Customer Check-in History...</div>`;
            modal.classList.remove('hidden');

            try {
                const res = await fetch(`${API_BASE}/admin/owners/${id}`, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();

                const owner = data.owner;
                const profile = owner.owner_profile || owner.ownerProfile || {};
                const isVerified = (owner.is_verified === true || owner.is_verified === 1) && (profile.is_profile_complete === true || profile.is_profile_complete === 1);
                const analytics = data.analytics || {};
                const hotels = data.hotels || [];
                const visitingCustomers = data.visiting_customers || [];

                const getImgUrl = (path) => path ? (path.startsWith('http') ? path : `${STORAGE_BASE}/${path}`) : null;
                const aadhaarFront = getImgUrl(profile.aadhaar_front);
                const aadhaarBack = getImgUrl(profile.aadhaar_back);
                const panCard = getImgUrl(profile.pan_card);
                const fssaiLic = getImgUrl(profile.fssai_license);
                const gstImg = getImgUrl(profile.gst_image);

                body.innerHTML = `
            <!-- Header Status & Action Bar -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                <div>
                    <h3 style="margin:0; font-size:20px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-user-tie" style="color:var(--primary);"></i> ${owner.name}
                        <span class="badge ${isVerified ? 'confirmed' : 'pending'}" style="font-size:11px; padding:4px 10px; border-radius:6px; font-weight:700; ${!isVerified ? 'background:#f59e0b; color:#000;' : ''}">
                            ${isVerified ? 'Verified Owner' : 'Pending KYC Verification'}
                        </span>
                    </h3>
                </div>
                <div style="display:flex; gap:8px;">
                    ${!isVerified ?
                        `<button class="btn-sm btn-success" style="padding:8px 14px; font-weight:700;" onclick="verifyOwner(${owner.id}, true); closeKycModal();"><i class="fa-solid fa-user-check"></i> Approve KYC</button>` :
                        `<button class="btn-sm btn-warning" style="padding:8px 14px; font-weight:700;" onclick="verifyOwner(${owner.id}, false); closeKycModal();"><i class="fa-solid fa-user-xmark"></i> Revoke KYC</button>`
                    }
                    <button class="btn-sm" style="background:#e11d48; color:white; padding:8px 14px; font-weight:700;" onclick="resetOwnerKyc(${owner.id}); closeKycModal();"><i class="fa-solid fa-trash-can"></i> Reset KYC</button>
                </div>
            </div>

            <!-- Owner Detailed Contact & Business Info Bar -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; background:var(--bg-dark); padding:12px 14px; border-radius:8px; border:1px solid var(--border); margin-bottom:16px; font-size:12px;">
                <div>
                    <span style="color:var(--text-muted); font-size:11px; display:block; font-weight:600;">Email Address</span>
                    <strong style="color:#38bdf8; font-size:13px;"><i class="fa-solid fa-envelope"></i> ${owner.email || 'N/A'}</strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:11px; display:block; font-weight:600;">Phone Number</span>
                    <strong style="color:var(--text-primary); font-size:13px;"><i class="fa-solid fa-phone"></i> ${owner.phone || 'N/A'}</strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:11px; display:block; font-weight:600;">Primary Hotel</span>
                    <strong style="color:var(--success); font-size:13px;"><i class="fa-solid fa-hotel"></i> ${profile.hotel_name || (hotels[0] ? hotels[0].name : 'N/A')}</strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:11px; display:block; font-weight:600;">GSTIN / Tax ID</span>
                    <strong style="color:#c084fc; font-size:13px;"><i class="fa-solid fa-file-invoice"></i> ${profile.gst_number || 'Not Provided'}</strong>
                </div>
            </div>

            <!-- Hotel Financial Performance & Analytics Grid -->
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; background:var(--bg-dark); padding:14px; border-radius:8px; border:1px solid var(--border); margin-bottom:16px;">
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total Revenue</span>
                    <h3 style="margin:4px 0 0 0; color:var(--success); font-size:18px;">₹${(analytics.total_revenue || 0).toLocaleString('en-IN')}</h3>
                </div>
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total Bookings</span>
                    <h3 style="margin:4px 0 0 0; color:#38bdf8; font-size:18px;">${analytics.total_bookings || 0}</h3>
                </div>
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Confirmed Check-ins</span>
                    <h3 style="margin:4px 0 0 0; color:#10b981; font-size:18px;">${analytics.confirmed_bookings || 0}</h3>
                </div>
                <div style="text-align:center;">
                    <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Cancelled / Refunded</span>
                    <h3 style="margin:4px 0 0 0; color:#f43f5e; font-size:18px;">${analytics.cancelled_bookings || 0}</h3>
                </div>
            </div>

            <!-- Listed Hotels Grid -->
            <h4 style="margin:0 0 8px 0; font-size:14px; color:var(--info);"><i class="fa-solid fa-building"></i> Listed Hotels (${hotels.length})</h4>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:16px;">
                ${hotels.length === 0 ? `<div style="color:var(--text-muted); font-size:12px;">No hotel listings created by this owner yet.</div>` : hotels.map(h => `
                    <div style="background:var(--bg-dark); padding:10px; border-radius:6px; border:1px solid var(--border); font-size:12px;">
                        <strong style="color:var(--text-primary); font-size:13px;">${h.name}</strong> • ${h.city || ''}<br>
                        <span style="color:var(--success); font-weight:700;">₹${h.price_per_night} / night</span> • ${h.total_rooms || 0} Rooms<br>
                        <span class="badge ${h.status === 'approved' ? 'confirmed' : 'pending'}" style="font-size:10px; margin-top:4px;">${h.status.toUpperCase()}</span>
                    </div>
                `).join('')}
            </div>

            <!-- Visiting Customers List -->
            <h4 style="margin:0 0 8px 0; font-size:14px; color:#a855f7;"><i class="fa-solid fa-users"></i> Visiting Customers & Check-ins (${visitingCustomers.length})</h4>
            ${visitingCustomers.length === 0 ? `
                <div style="padding:10px; background:var(--bg-dark); border-radius:6px; font-size:12px; color:var(--text-muted); margin-bottom:16px;">No customer visits recorded yet for this owner's hotels.</div>
            ` : `
                <div style="max-height:200px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; margin-bottom:16px;">
                    ${visitingCustomers.map(b => `
                        <div style="background:var(--bg-dark); padding:10px 12px; border-radius:6px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; font-size:12px; gap:10px;">
                            <div>
                                <div style="font-size:13px; font-weight:700; color:var(--text-primary); margin-bottom:3px;">
                                    <i class="fa-solid fa-user" style="color:var(--primary);"></i> ${b.user ? b.user.name : (b.logistics_name || 'Guest Driver')} 
                                    <span style="color:#38bdf8; font-weight:600; margin-left:6px; font-size:12px;">
                                        <i class="fa-solid fa-phone"></i> ${b.user ? (b.user.phone || b.logistics_number || 'N/A') : (b.logistics_number || 'N/A')}
                                    </span>
                                    <span style="color:var(--text-muted); font-size:11px; margin-left:6px;">(${b.hotel ? b.hotel.name : 'N/A'})</span>
                                </div>
                                <div style="font-size:11px; color:var(--text-secondary); display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                                    <span><i class="fa-solid fa-calendar-day" style="color:var(--info);"></i> Check-in: <strong>${formatDateClean(b.check_in || b.booking_date)}</strong></span>
                                    <span><i class="fa-solid fa-calendar-check" style="color:var(--success);"></i> Check-out: <strong>${formatDateClean(b.check_out)}</strong></span>
                                    ${b.created_at ? `<span><i class="fa-solid fa-clock" style="color:var(--warning);"></i> Booked: <strong>${formatDateTimeClean(b.created_at)}</strong></span>` : ''}
                                </div>
                            </div>
                            <div style="text-align:right; flex-shrink:0;">
                                <strong style="color:var(--success); font-size:14px;">₹${(b.total_payable || b.total_amount || 0).toLocaleString('en-IN')}</strong><br>
                                <span class="badge ${b.status === 'confirmed' ? 'confirmed' : (b.payment_status === 'refunded' ? 'failed' : 'pending')}" style="font-size:10px; margin-top:2px;">
                                    ${b.payment_status === 'refunded' ? 'REFUNDED' : b.status.toUpperCase()}
                                </span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `}

            <!-- Business & Bank Legal Details -->
            <h4 style="margin:0 0 8px 0; font-size:14px; color:var(--primary);"><i class="fa-solid fa-file-contract"></i> Legal & Bank Account Details</h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); font-size:12px; margin-bottom:16px;">
                <div><strong>GST Number:</strong> ${profile.gst_number || 'N/A'}</div>
                <div><strong>FSSAI Number:</strong> ${profile.fssai_number || 'N/A'}</div>
                <div><strong>Bank Name:</strong> ${profile.bank_name || 'N/A'}</div>
                <div><strong>Account Number:</strong> ${profile.account_number || 'N/A'}</div>
                <div><strong>IFSC Code:</strong> ${profile.ifsc_code || 'N/A'}</div>
            </div>

            <!-- Documents Preview -->
            <h4 style="margin:0 0 8px 0; font-size:14px; color:var(--text-primary);"><i class="fa-solid fa-file-image"></i> KYC Uploaded Documents</h4>
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px;">
                ${[
                        { label: 'Aadhaar Front', url: aadhaarFront },
                        { label: 'Aadhaar Back', url: aadhaarBack },
                        { label: 'PAN Card', url: panCard },
                        { label: 'FSSAI License', url: fssaiLic },
                        { label: 'GST Certificate', url: gstImg }
                    ].map(doc => `
                    <div style="background:var(--bg-dark); padding:8px; border-radius:6px; border:1px solid var(--border); text-align:center; font-size:11px;">
                        <strong style="display:block; margin-bottom:4px;">${doc.label}</strong>
                        ${doc.url ? `
                            <a href="${doc.url}" target="_blank">
                                <img src="${doc.url}" style="width:100%; height:90px; object-fit:cover; border-radius:4px;" onerror="this.src='https://via.placeholder.com/200x120?text=View+Document'">
                            </a>
                        ` : `<div style="height:90px; display:flex; align-items:center; justify-content:center; color:var(--text-muted); background:rgba(255,255,255,0.03); border-radius:4px;">Not Uploaded</div>`}
                    </div>
                `).join('')}
            </div>
        `;
            } catch (err) {
                body.innerHTML = `<div style="padding:20px; text-align:center; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Failed to load owner KYC data'}</div>`;
            }
        }


        function closeKycModal() {
            document.getElementById('kyc-modal').classList.add('hidden');
        }

        async function verifyOwner(id, isVerified) {
            try {
                const res = await fetch(`${API_BASE}/admin/owners/${id}/verify`, {
                    method: 'PUT',
                    headers: getHeaders(),
                    body: JSON.stringify({ is_verified: isVerified })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Failed to update owner verification');

                showToast(data.message || 'Owner verification updated', 'success');
                loadOwnersData();
                fetchBadgeCounts();
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        async function resetOwnerKyc(id) {
            const owner = currentOwners.find(o => o.id === id);
            const ownerName = owner ? owner.name : `Owner #${id}`;

            if (!confirm(`Are you sure you want to REMOVE & RESET all uploaded KYC documents for ${ownerName}?\n\nThis will clear their verified status and document uploads so they can submit fresh KYC details.`)) {
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/admin/owners/${id}/reset-kyc`, {
                    method: 'POST',
                    headers: getHeaders(),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Failed to reset owner KYC');

                showToast(data.message || 'Owner KYC data removed and reset successfully', 'success');
                loadOwnersData();
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        // ----------------------------------------------------
        // 4. BOOKINGS LEDGER
        // ----------------------------------------------------
        async function loadBookingsData() {
            const tbody = document.getElementById('bookings-table-body');
            const status = document.getElementById('filter-booking-status').value;
            const search = document.getElementById('search-bookings').value;

            tbody.innerHTML = `<tr><td colspan="9" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Loading bookings...</td></tr>`;

            try {
                let url = `${API_BASE}/admin/bookings?status=${status}&search=${encodeURIComponent(search)}`;
                const res = await fetch(url, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                const bookings = data.data || [];

                if (bookings.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="9" class="text-center">No bookings found</td></tr>`;
                    return;
                }

                tbody.innerHTML = bookings.map(b => {
                    const isRefunded = b.payment_status === 'refunded' || b.payment_status === 'refund_initiated' || (b.cancellation_reason && b.cancellation_reason.toLowerCase().includes('refund'));
                    const payBadgeClass = isRefunded ? 'failed' : (b.payment_status === 'paid' ? 'confirmed' : 'pending');
                    const payText = isRefunded ? (b.payment_status === 'refunded' ? 'Refunded' : 'Refund Initiated') : (b.payment_status || 'Pending');

                    return `
                <tr>
                    <td><strong style="color:#38bdf8; font-size:13px;">#${b.id}</strong></td>
                    <td>
                        <div>
                            <strong style="color:var(--text-primary); font-size:13px;"><i class="fa-solid fa-user" style="color:var(--primary); font-size:11px;"></i> ${b.user ? b.user.name : (b.logistics_name || 'Guest Driver')}</strong><br>
                            <small style="color:#38bdf8; font-weight:600;"><i class="fa-solid fa-phone" style="font-size:10px;"></i> ${b.user ? (b.user.phone || b.logistics_number || 'N/A') : (b.logistics_number || 'N/A')}</small>
                        </div>
                    </td>
                    <td>
                        <div>
                            <strong style="color:var(--text-primary); font-size:13px;"><i class="fa-solid fa-hotel" style="color:var(--success); font-size:11px;"></i> ${b.hotel ? b.hotel.name : 'N/A'}</strong><br>
                            <small style="color:var(--text-muted); font-weight:600;"><i class="fa-solid fa-location-dot" style="color:#38bdf8; font-size:10px;"></i> ${b.hotel ? (b.hotel.city || 'N/A') : 'N/A'}</small>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px; line-height:1.4;">
                            <span style="color:var(--info); font-weight:600;"><i class="fa-solid fa-calendar-day" style="font-size:10px;"></i> In:</span> ${formatDateClean(b.check_in || b.booking_date)}<br>
                            <span style="color:var(--success); font-weight:600;"><i class="fa-solid fa-calendar-check" style="font-size:10px;"></i> Out:</span> ${formatDateClean(b.check_out)}
                        </div>
                    </td>
                    <td>
                        <div style="font-size:11px; color:var(--text-secondary); white-space:nowrap;">
                            <i class="fa-solid fa-clock" style="color:var(--warning); font-size:10px;"></i> ${formatDateTimeClean(b.created_at || b.booking_date)}
                        </div>
                    </td>
                    <td><strong style="color:var(--success); font-size:14px;">₹${(b.total_payable || b.total_amount || 0).toLocaleString('en-IN')}</strong></td>
                    <td><span class="badge ${payBadgeClass}" style="${isRefunded ? 'background:#e11d48; color:#ffffff;' : ''}; text-transform:uppercase; font-size:10px; font-weight:700;">${payText.toUpperCase()}</span></td>
                    <td><span class="badge ${b.status === 'confirmed' ? 'confirmed' : (b.status === 'cancelled' ? 'failed' : 'pending')}" style="text-transform:uppercase; font-size:10px; font-weight:700;">${(b.status || 'PENDING').toUpperCase()}</span></td>
                    <td>
                        <select onchange="updateBookingStatus(${b.id}, this.value)" style="padding:6px 10px; font-size:12px; border-radius:6px; background:#0f172a; border:1px solid var(--border); color:#fff; cursor:pointer;">
                            <option value="">Update Status...</option>
                            <option value="confirmed">Confirm Check-in</option>
                            <option value="completed">Complete Stay</option>
                            <option value="cancelled">Cancel Booking</option>
                            <option value="refund">Issue Refund (Razorpay)</option>
                        </select>
                    </td>
                </tr>
            `;
                }).join('');
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading bookings'}</td></tr>`;
            }
        }

        async function updateBookingStatus(id, status) {
            if (!status) return;

            if (status === 'refund') {
                let reason = prompt(`[Razorpay Refund Request]\nEnter reason for refunding Booking #${id} (min 5 characters):`, 'Customer requested refund');
                if (reason === null) {
                    loadBookingsData();
                    return;
                }
                reason = reason.trim();
                if (reason.length < 5) {
                    showToast('Refund cancelled: A detailed reason (minimum 5 characters) is required.', 'danger');
                    loadBookingsData();
                    return;
                }

                const approved = confirm(`🔔 CONFIRM RAZORPAY REFUND:\n\nBooking ID: #${id}\nReason: ${reason}\n\nDo you explicitly APPROVE issuing this refund to the customer via Razorpay?`);
                if (!approved) {
                    showToast('Refund request cancelled by Admin.', 'warning');
                    loadBookingsData();
                    return;
                }

                showToast('Initiating refund with Razorpay API...', 'info');
                try {
                    const res = await fetch(`${API_BASE}/admin/transactions/${id}/refund`, {
                        method: 'POST',
                        headers: getHeaders(),
                        body: JSON.stringify({ reason })
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error || data.message || 'Refund failed');

                    showToast(data.message || 'Refund processed successfully!', 'success');
                    loadBookingsData();
                } catch (err) {
                    showToast(err.message, 'danger');
                    loadBookingsData();
                }
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/admin/bookings/${id}/status`, {
                    method: 'PUT',
                    headers: getHeaders(),
                    body: JSON.stringify({ status })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Failed to update booking');

                showToast(data.message || 'Booking status updated', 'success');
                loadBookingsData();
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        // ----------------------------------------------------
        // 5. USER / CUSTOMER MANAGEMENT
        // ----------------------------------------------------
        async function loadUsersData() {
            const tbody = document.getElementById('users-table-body');
            const searchInput = document.getElementById('search-users');
            const search = searchInput ? searchInput.value : '';

            tbody.innerHTML = `<tr><td colspan="6" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Loading customer accounts...</td></tr>`;

            try {
                let url = `${API_BASE}/admin/users?search=${encodeURIComponent(search)}`;
                const res = await fetch(url, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                const users = data.data || [];

                if (users.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center">No customer accounts found matching your search.</td></tr>`;
                    return;
                }

                tbody.innerHTML = users.map(u => {
                    const isActive = u.is_verified === true || u.is_verified === 1 || u.is_verified === '1' || u.is_verified === 'true';
                    const joinedDate = u.created_at ? new Date(u.created_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : 'N/A';

                    return `
                <tr>
                    <td><strong>#${u.id}</strong></td>
                    <td>
                        <div style="font-weight:700; color:var(--text-primary); font-size:14px;">
                            <i class="fa-solid fa-circle-user" style="color:var(--primary); margin-right:4px;"></i> ${u.name || 'Customer'}
                        </div>
                        <small class="text-muted" style="font-size:11px;">Joined: ${joinedDate}</small>
                    </td>
                    <td>
                        <div style="font-size:12px; font-weight:600; color:#38bdf8;">
                            <i class="fa-solid fa-envelope" style="font-size:11px;"></i> ${u.email || 'N/A'}
                        </div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;">
                            <i class="fa-solid fa-phone" style="font-size:11px;"></i> ${u.phone || 'N/A'}
                        </div>
                    </td>
                    <td>
                        <span class="badge ${u.bookings_count > 0 ? 'confirmed' : 'pending'}" style="font-size:11px;">
                            <i class="fa-solid fa-receipt"></i> ${u.bookings_count || 0} Bookings
                        </span>
                    </td>
                    <td>
                        <span class="badge ${isActive ? 'confirmed' : 'failed'}" style="font-size:11px; ${!isActive ? 'background:#e11d48; color:#ffffff;' : ''}">
                            <i class="fa-solid ${isActive ? 'fa-circle-check' : 'fa-ban'}"></i>
                            ${isActive ? 'Active' : 'Disabled'}
                        </span>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button class="btn-sm" style="background:${isActive ? '#f59e0b' : '#10b981'}; color:#ffffff; font-weight:600;" onclick="toggleUserStatus(${u.id}, ${!isActive}, '${(u.name || '').replace(/'/g, "\\'")}')">
                                <i class="fa-solid ${isActive ? 'fa-ban' : 'fa-circle-check'}"></i>
                                ${isActive ? 'Disable' : 'Activate'}
                            </button>
                            <button class="btn-sm" style="background:#4f46e5; color:#ffffff;" onclick="openUserDetailsModal(${u.id})">
                                <i class="fa-solid fa-eye"></i> Details
                            </button>
                        </div>
                    </td>
                </tr>
            `;
                }).join('');
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading users'}</td></tr>`;
            }
        }

        async function toggleUserStatus(id, newVerifiedState, name = 'Customer') {
            const actionText = newVerifiedState ? 'Activate' : 'Disable';
            const confirmed = confirm(`Are you sure you want to ${actionText} Customer #${id} (${name})?\n\n${!newVerifiedState ? 'Disabling will prevent this customer from logging into the mobile app.' : 'Activating will restore customer app access.'}`);
            if (!confirmed) return;

            try {
                const res = await fetch(`${API_BASE}/admin/users/${id}/status`, {
                    method: 'PUT',
                    headers: getHeaders(),
                    body: JSON.stringify({ is_verified: newVerifiedState })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Failed to update user status');

                showToast(data.message || `Customer account ${actionText}d successfully`, 'success');
                loadUsersData();
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        async function openUserDetailsModal(id) {
            const modal = document.getElementById('user-modal');
            const body = document.getElementById('user-modal-body');
            if (!modal || !body) return;

            body.innerHTML = `<div style="padding:20px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Loading Customer Profile...</div>`;
            modal.classList.remove('hidden');

            try {
                const res = await fetch(`${API_BASE}/admin/users/${id}`, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                const u = data.user;
                const isActive = u.is_verified === true || u.is_verified === 1 || u.is_verified === '1' || u.is_verified === 'true';

                body.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <div>
                    <h3 style="margin:0; font-size:18px; color:var(--text-primary);">
                        <i class="fa-solid fa-user-circle" style="color:var(--primary);"></i> ${u.name}
                        <span class="badge ${isActive ? 'confirmed' : 'failed'}" style="margin-left:8px; ${!isActive ? 'background:#e11d48; color:#fff;' : ''}">
                            ${isActive ? 'Active Customer' : 'Account Disabled'}
                        </span>
                    </h3>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Customer Account ID: #${u.id}</div>
                </div>
                <button class="btn-sm" style="background:${isActive ? '#f59e0b' : '#10b981'}; color:#fff;" onclick="toggleUserStatus(${u.id}, ${!isActive}, '${(u.name || '').replace(/'/g, "\\'")}'); closeUserModal();">
                    <i class="fa-solid ${isActive ? 'fa-ban' : 'fa-circle-check'}"></i> ${isActive ? 'Disable Account' : 'Activate Account'}
                </button>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:var(--bg-dark); padding:14px; border-radius:8px; border:1px solid var(--border); margin-bottom:16px;">
                <div><strong>Email Address:</strong> <br><span style="color:#38bdf8; font-weight:600;"><i class="fa-solid fa-envelope"></i> ${u.email || 'N/A'}</span></div>
                <div><strong>Phone Number:</strong> <br><span style="color:var(--text-primary); font-weight:600;"><i class="fa-solid fa-phone"></i> ${u.phone || 'N/A'}</span></div>
                <div><strong>Total Bookings:</strong> <br><span style="color:var(--success); font-weight:700;">${u.bookings_count || 0} Bookings Made</span></div>
                <div><strong>Joined Date:</strong> <br><span>${u.created_at ? new Date(u.created_at).toLocaleString('en-IN') : 'N/A'}</span></div>
            </div>

            <h4 style="margin:0 0 10px 0; font-size:14px; color:var(--info);"><i class="fa-solid fa-clock-rotate-left"></i> Recent Booking History (${(u.bookings || []).length})</h4>
            ${(u.bookings || []).length === 0 ? `
                <div style="padding:12px; background:var(--bg-dark); border-radius:6px; font-size:12px; color:var(--text-muted);">No booking history found for this customer.</div>
            ` : `
                <div style="max-height:220px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">
                    ${(u.bookings || []).map(b => `
                        <div style="background:var(--bg-dark); padding:10px 12px; border-radius:6px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; font-size:12px;">
                            <div>
                                <strong>Booking #${b.id}</strong> • ${b.hotel ? b.hotel.name : 'Hotel'} (${b.hotel ? b.hotel.city : ''})<br>
                                <small class="text-muted">Check-in: ${b.check_in || 'N/A'} to ${b.check_out || 'N/A'}</small>
                            </div>
                            <div style="text-align:right;">
                                <strong style="color:var(--text-primary);">₹${b.total_payable || b.total_amount}</strong><br>
                                <span class="badge ${b.status === 'confirmed' ? 'confirmed' : (b.payment_status === 'refunded' ? 'failed' : 'pending')}" style="font-size:10px;">
                                    ${b.payment_status === 'refunded' ? 'REFUNDED' : b.status.toUpperCase()}
                                </span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `}
        `;
            } catch (err) {
                body.innerHTML = `<div style="padding:20px; text-align:center; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Failed to load customer details'}</div>`;
            }
        }

        function closeUserModal() {
            const modal = document.getElementById('user-modal');
            if (modal) modal.classList.add('hidden');
        }

        // ----------------------------------------------------
        // 6. REVIEWS MODERATION
        // ----------------------------------------------------
        async function loadReviewsData() {
            const container = document.getElementById('reviews-container');
            if (!container) return;
            container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Loading reviews...</div>`;

            try {
                const res = await fetch(`${API_BASE}/admin/reviews`, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                const reviews = data.data || [];

                if (reviews.length === 0) {
                    container.innerHTML = `<div class="empty-state">No customer reviews yet</div>`;
                    return;
                }

                container.innerHTML = reviews.map(r => `
            <div class="data-card">
                <div class="data-card-header">
                    <div class="data-card-title">
                        <h4><i class="fa-solid fa-hotel" style="color:var(--primary);"></i> ${r.hotel ? r.hotel.name : 'Hotel'} ${r.hotel && r.hotel.city ? `<small style="font-weight:normal; color:var(--text-muted);">(${r.hotel.city})</small>` : ''}</h4>
                        <div class="data-card-sub"><i class="fa-solid fa-user" style="color:var(--info);"></i> by ${r.user ? r.user.name : (r.user_name || 'Anonymous')} ${r.user && r.user.email ? `(${r.user.email})` : ''}</div>
                    </div>
                    <span class="badge approved"><i class="fa-solid fa-star"></i> ${r.rating || 5} / 5</span>
                </div>
                <p style="font-size:13px; color: var(--text-secondary); line-height: 1.4; margin: 10px 0;">
                    "${r.comment || r.review || 'No text comment provided.'}"
                </p>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top: auto; pt-2;">
                    <span style="font-size:11px; color:var(--text-muted);"><i class="fa-solid fa-clock"></i> ${formatDateTimeClean(r.created_at)}</span>
                    <button class="btn-sm btn-danger" onclick="deleteReview(${r.id})">
                        <i class="fa-solid fa-trash"></i> Moderate & Delete
                    </button>
                </div>
            </div>
        `).join('');
            } catch (err) {
                container.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading reviews'}</div>`;
            }
        }

        async function deleteReview(id) {
            if (!confirm('Are you sure you want to delete this review?')) return;

            try {
                const res = await fetch(`${API_BASE}/admin/reviews/${id}`, {
                    method: 'DELETE',
                    headers: getHeaders()
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Failed to delete review');

                showToast(data.message || 'Review deleted successfully', 'success');
                loadReviewsData();
                loadHotelsData();
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        // ----------------------------------------------------
        // 7. TRANSACTIONS & RAZORPAY LEDGER MANAGEMENT
        // ----------------------------------------------------
        function filterTxnType(type) {
            currentTxnTypeFilter = type;
            document.querySelectorAll('.subtab-btn').forEach(btn => {
                if (btn.getAttribute('data-txntype') === type) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            loadTransactionsData();
        }

        async function loadTransactionsData() {
            const tbody = document.getElementById('transactions-table-body');
            const methodSelect = document.getElementById('filter-txn-method');
            const method = methodSelect ? methodSelect.value : '';
            const searchInput = document.getElementById('search-transactions');
            const search = searchInput ? searchInput.value : '';

            tbody.innerHTML = `<tr><td colspan="9" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Loading transactions...</td></tr>`;

            try {
                let url = `${API_BASE}/admin/transactions?type=${currentTxnTypeFilter}&payment_method=${encodeURIComponent(method)}&search=${encodeURIComponent(search)}`;
                const res = await fetch(url, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const resData = await res.json();

                currentTransactions = (resData.transactions && resData.transactions.data) ? resData.transactions.data : [];
                const m = resData.metrics || {};

                // Update Overview Widgets
                if (document.getElementById('stat-txn-revenue')) {
                    document.getElementById('stat-txn-revenue').textContent = `₹${(m.confirmed_amount || 0).toLocaleString('en-IN')}`;
                }
                if (document.getElementById('stat-txn-confirmed-count')) {
                    document.getElementById('stat-txn-confirmed-count').textContent = m.confirmed_count || 0;
                }
                if (document.getElementById('stat-txn-temp-count')) {
                    document.getElementById('stat-txn-temp-count').textContent = m.temporary_count || 0;
                }
                if (document.getElementById('stat-txn-refunded-count')) {
                    document.getElementById('stat-txn-refunded-count').textContent = m.refunded_count || 0;
                }
                if (document.getElementById('stat-txn-cancelled-count')) {
                    document.getElementById('stat-txn-cancelled-count').textContent = m.cancelled_count || 0;
                }

                if (currentTransactions.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="9" class="text-center">No transaction records found</td></tr>`;
                    return;
                }

                tbody.innerHTML = currentTransactions.map(t => {
                    const isConfirmed = t.is_confirmed || t.payment_status === 'paid' || t.status === 'confirmed' || t.status === 'completed';
                    const isRefunded = t.payment_status === 'refunded' || (t.cancellation_reason && t.cancellation_reason.toLowerCase().includes('refund'));
                    const isCancelled = t.status === 'cancelled' || isRefunded || t.payment_status === 'failed';
                    const isTemp = !isConfirmed && !isCancelled;
                    const displayTxnId = t.display_transaction_id || t.transaction_id || t.temp_transaction_id || `TMP-${t.razorpay_order_id || t.id}`;
                    const regionTime = t.region_time_formatted || (t.created_at ? new Date(t.created_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST' : 'N/A');
                    const userObj = t.user || {};
                    const userName = userObj.name || 'Guest User';
                    const userContact = userObj.phone || userObj.email || '';

                    let typeBadgeClass = 'pending';
                    let typeBadgeIcon = 'fa-clock';
                    let typeBadgeText = 'TEMPORARY';

                    if (isConfirmed) {
                        typeBadgeClass = 'confirmed';
                        typeBadgeIcon = 'fa-circle-check';
                        typeBadgeText = 'CONFIRMED';
                    } else if (isRefunded) {
                        typeBadgeClass = 'failed';
                        typeBadgeIcon = 'fa-arrow-rotate-left';
                        typeBadgeText = 'REFUNDED';
                    } else if (isCancelled) {
                        typeBadgeClass = 'cancelled';
                        typeBadgeIcon = 'fa-circle-xmark';
                        typeBadgeText = 'CANCELLED';
                    }

                    let statusBadgeClass = 'pending';
                    let statusText = t.status || 'pending';

                    if (isRefunded) {
                        statusBadgeClass = 'failed';
                        statusText = 'REFUNDED VIA RAZORPAY';
                    } else if (isConfirmed) {
                        statusBadgeClass = 'confirmed';
                        statusText = 'CONFIRMED SUCCESS';
                    } else if (t.status === 'cancelled') {
                        statusBadgeClass = 'cancelled';
                        statusText = 'CANCELLED / EXITED';
                    } else if (t.payment_status === 'failed') {
                        statusBadgeClass = 'failed';
                        statusText = 'PAYMENT FAILED';
                    } else {
                        statusBadgeClass = 'pending';
                        statusText = 'TEMPORARY / UNVERIFIED';
                    }

                    let reasonText = t.cancellation_reason;
                    if (!reasonText && isTemp) {
                        reasonText = 'Money Deducted? Click Live Sync to check Razorpay status';
                    }

                    return `
                <tr class="${isTemp ? 'temp-txn-row' : (isConfirmed ? 'confirmed-txn-row' : (isRefunded ? 'refunded-txn-row' : ''))}">
                    <td><strong>#${t.id}</strong></td>
                    <td>
                        <span class="badge ${typeBadgeClass}" style="font-size:10px;">
                            <i class="fa-solid ${typeBadgeIcon}"></i>
                            ${typeBadgeText}
                        </span>
                    </td>
                    <td>
                        <div style="font-family: monospace; font-weight: 700; color: ${isConfirmed ? '#10b981' : (isRefunded ? '#a855f7' : '#f59e0b')}; font-size:12px;">
                            ${displayTxnId}
                        </div>
                        ${t.razorpay_order_id ? `<small style="font-size:10px; color:var(--text-muted);">Order: ${t.razorpay_order_id}</small>` : ''}
                    </td>
                    <td>
                        <strong>${userName}</strong><br>
                        <small class="text-muted"><i class="fa-solid fa-phone" style="font-size:10px;"></i> ${userContact}</small>
                    </td>
                    <td>
                        <div style="font-size:12px; font-weight:600;">${regionTime}</div>
                        ${isRefunded && t.refund_time_formatted ? `
                            <div style="font-size:11px; color:#c084fc; font-weight:700; margin-top:2px;" title="Exact Razorpay Refund Timestamp">
                                <i class="fa-solid fa-arrow-rotate-left"></i> Refunded: ${t.refund_time_formatted}
                            </div>
                        ` : ''}
                        <small class="text-muted" style="font-size:10px;">Hotel: ${t.hotel ? t.hotel.name : 'N/A'}</small>
                    </td>
                    <td>
                        <span class="pay-method-pill">
                            <i class="fa-solid ${t.payment_method === 'Razorpay' || t.razorpay_order_id ? 'fa-shield-halved' : 'fa-credit-card'}"></i>
                            ${t.payment_method || 'Razorpay / Online'}
                        </span>
                    </td>
                    <td>
                        <strong style="font-size:14px; color:var(--text-primary);">₹${t.total_payable || t.total_amount || 0}</strong>
                    </td>
                    <td>
                        <span class="badge ${statusBadgeClass}">${statusText}</span>
                        ${isRefunded && t.refund_time_formatted ? `
                            <div style="font-size:11px; color:#e9d5ff; font-weight:700; margin-top:3px;">
                                <i class="fa-solid fa-clock"></i> Refunded At: ${t.refund_time_formatted}
                            </div>
                        ` : ''}
                        ${reasonText ? `<div style="font-size:11px; color:#fca5a5; margin-top:3px; max-width:200px; line-height:1.2;"><i class="fa-solid fa-circle-info"></i> ${reasonText}</div>` : ''}
                    </td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <button class="btn-sm" style="background:#4f46e5; color:#fff;" onclick="openTransactionModal(${t.id})" title="Inspect Full Razorpay Details">
                                <i class="fa-solid fa-eye"></i> Details
                            </button>
                            <button class="btn-sm" style="background:#10b981; color:#fff;" onclick="openInvoiceModal(${t.id})" title="View & Print Official Invoice">
                                <i class="fa-solid fa-file-invoice"></i> Invoice
                            </button>
                            <button class="btn-sm" style="background:#0284c7; color:#fff;" onclick="verifyRazorpayStatus(${t.id})" title="Verify Live Status with Razorpay API">
                                <i class="fa-solid fa-rotate"></i> Live Sync
                            </button>
                            ${isConfirmed && !isRefunded ? `
                                <button class="btn-sm" style="background:#e11d48; color:#fff;" onclick="refundTransactionDirect(${t.id})" title="Issue Refund to Customer via Razorpay API">
                                    <i class="fa-solid fa-arrow-rotate-left"></i> Refund Txn
                                </button>
                            ` : ''}
                            <select onchange="updateTransactionStatus(${t.id}, this.value)" style="padding:3px; font-size:11px; background:#0f172a; color:#fff; border:1px solid #334155; border-radius:4px; margin-top:2px;">
                                <option value="">Action</option>
                                <option value="confirmed">Confirm Txn</option>
                                <option value="refund">Issue Refund (Razorpay)</option>
                                <option value="cancelled">Mark Cancelled</option>
                                <option value="pending">Mark Temporary</option>
                            </select>
                        </div>
                    </td>
                </tr>
            `;
                }).join('');
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading transactions'}</td></tr>`;
            }
        }

        async function openTransactionModal(txnId) {
            const modalBody = document.getElementById('transaction-modal-body');
            modalBody.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Fetching transaction & Razorpay metadata...</div>`;
            document.getElementById('transaction-modal').classList.remove('hidden');

            try {
                const res = await fetch(`${API_BASE}/admin/transactions/${txnId}`, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                const t = data.transaction || {};
                const r = data.razorpay || {};
                const isConfirmed = t.is_confirmed || t.payment_status === 'paid' || t.status === 'confirmed' || t.status === 'completed';
                const displayTxnId = t.display_transaction_id || t.transaction_id || t.temp_transaction_id || `TMP-${t.razorpay_order_id || t.id}`;
                const regionTime = t.region_time_formatted || (t.created_at ? new Date(t.created_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST' : 'N/A');
                const userObj = t.user || {};
                const hotelObj = t.hotel || {};

                let gatewayJsonHtml = '';
                if (t.gateway_response) {
                    try {
                        const parsed = JSON.parse(t.gateway_response);
                        gatewayJsonHtml = `<pre style="background:#0f172a; padding:10px; border-radius:6px; font-size:11px; max-height:150px; overflow:auto; color:#38bdf8;">${JSON.stringify(parsed, null, 2)}</pre>`;
                    } catch (e) {
                        gatewayJsonHtml = `<div style="font-size:12px; color:var(--text-muted);">${t.gateway_response}</div>`;
                    }
                }

                modalBody.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <div>
                    <h4 style="margin:0; font-size:18px;">
                        Transaction #${t.id} 
                        <span class="badge ${isConfirmed ? 'confirmed' : 'pending'}" style="margin-left:8px;">
                            ${isConfirmed ? 'CONFIRMED PAYMENT' : 'TEMPORARY / CANCELLED'}
                        </span>
                    </h4>
                    <div style="font-size:13px; font-weight:700; color:${isConfirmed ? '#10b981' : '#f59e0b'}; font-family:monospace; margin-top:4px;">
                        ${displayTxnId}
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:22px; font-weight:700; color:var(--text-primary);">₹${t.total_payable || t.total_amount || 0}</div>
                    <div style="font-size:11px; color:var(--text-muted);">${t.payment_method || 'Online Payment'}</div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border);">
                    <h5 style="margin:0 0 6px 0; font-size:13px; color:var(--primary);"><i class="fa-solid fa-user"></i> Customer Info</h5>
                    <div style="font-size:12px; display:flex; flex-direction:column; gap:3px;">
                        <div><strong>Name:</strong> ${userObj.name || 'N/A'}</div>
                        <div><strong>Email:</strong> ${userObj.email || 'N/A'}</div>
                        <div><strong>Phone:</strong> ${userObj.phone || 'N/A'}</div>
                    </div>
                </div>

                <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border);">
                    <h5 style="margin:0 0 6px 0; font-size:13px; color:var(--primary);"><i class="fa-solid fa-hotel"></i> Hotel & Booking Info</h5>
                    <div style="font-size:12px; display:flex; flex-direction:column; gap:3px;">
                        <div><strong>Hotel:</strong> ${hotelObj.name || 'N/A'} (${hotelObj.city || ''})</div>
                        <div><strong>Dates:</strong> ${t.check_in || ''} to ${t.check_out || ''}</div>
                        <div><strong>Truck No:</strong> ${t.truck_no || 'N/A'} (${t.truck_type || ''})</div>
                    </div>
                </div>
            </div>

            <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); margin-bottom:16px;">
                <h5 style="margin:0 0 8px 0; font-size:13px; color:var(--info);"><i class="fa-solid fa-clock"></i> Timestamps & Region Time</h5>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:12px;">
                    <div><strong>Booking Created (IST):</strong> ${regionTime}</div>
                    <div><strong>Server Timestamp:</strong> ${t.created_at || 'N/A'}</div>
                    ${t.refund_time_formatted ? `
                        <div style="grid-column: span 2; color:#c084fc; font-weight:700; margin-top:4px; padding-top:4px; border-top:1px dashed var(--border);">
                            <i class="fa-solid fa-arrow-rotate-left"></i> <strong>Razorpay Refund Date & Time:</strong> ${t.refund_time_formatted}
                        </div>
                    ` : ''}
                </div>
                ${t.cancellation_reason ? `
                    <div style="margin-top:8px; padding:8px; background:rgba(239, 68, 68, 0.15); border:1px solid var(--danger); border-radius:6px; font-size:12px; color:#fca5a5;">
                        <i class="fa-solid fa-circle-exclamation"></i> <strong>Cancellation / Failure Reason:</strong> ${t.cancellation_reason}
                    </div>
                ` : ''}
            </div>

            <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); margin-bottom:16px;">
                <h5 style="margin:0 0 8px 0; font-size:13px; color:#a855f7;"><i class="fa-solid fa-shield-halved"></i> Razorpay Integration Metadata</h5>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:12px;">
                    <div><strong>Razorpay Order ID:</strong> ${r.order_id || t.razorpay_order_id || 'N/A'}</div>
                    <div><strong>Razorpay Payment ID:</strong> ${r.payment_id || t.razorpay_payment_id || 'N/A'}</div>
                    <div><strong>Razorpay Key ID:</strong> ${r.key_id ? r.key_id.substring(0, 10) + '...' : 'Configured'}</div>
                    <div><strong>Amount in Paise:</strong> ${r.amount_in_paise || ((t.total_payable || 0) * 100)} paise</div>
                </div>
                ${gatewayJsonHtml ? `<div style="margin-top:10px;"><strong style="font-size:11px;">Razorpay Gateway Raw Response:</strong>${gatewayJsonHtml}</div>` : ''}
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; margin-top:20px;">
                <button class="btn-sm" style="background:#10b981; color:#fff; padding:8px 14px;" onclick="openInvoiceModal(${t.id}); closeTransactionModal();">
                    <i class="fa-solid fa-file-invoice-dollar"></i> View Payment Invoice
                </button>
                <button class="btn-sm" style="background:#0284c7; color:#fff; padding:8px 14px;" onclick="verifyRazorpayStatus(${t.id})">
                    <i class="fa-solid fa-rotate"></i> Sync Live Razorpay Status
                </button>
                ${isConfirmed && !isRefunded ? `
                    <button class="btn-sm" style="padding:8px 14px; background:#e11d48; color:#fff;" onclick="refundTransactionDirect(${t.id}); closeTransactionModal();">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Issue Razorpay Refund
                    </button>
                ` : (!isConfirmed ? `
                    <button class="btn-sm btn-success" style="padding:8px 14px;" onclick="updateTransactionStatus(${t.id}, 'confirmed'); closeTransactionModal();">
                        <i class="fa-solid fa-check"></i> Mark as Confirmed
                    </button>
                ` : '')}
                <button class="btn-sm" style="padding:8px 14px; background:#475569; color:#fff;" onclick="closeTransactionModal()">Close</button>
            </div>
        `;
            } catch (err) {
                modalBody.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading transaction details'}</div>`;
            }
        }

        function closeTransactionModal() {
            document.getElementById('transaction-modal').classList.add('hidden');
        }

        // ----------------------------------------------------
        // 8. OFFICIAL PAYMENT INVOICE
        // ----------------------------------------------------
        async function openInvoiceModal(txnId) {
            const modalBody = document.getElementById('invoice-modal-body');
            modalBody.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Generating official payment invoice...</div>`;
            document.getElementById('invoice-modal').classList.remove('hidden');

            try {
                const res = await fetch(`${API_BASE}/admin/transactions/${txnId}/invoice`, { headers: getHeaders() });
                if (!res.ok) await handleApiError(res);
                const data = await res.json();
                const inv = data.invoice || {};
                const comp = inv.company || {};
                const cust = inv.customer || {};
                const hotel = inv.hotel || {};
                const stay = inv.stay || {};
                const logi = inv.logistics || {};
                const pay = inv.payment || {};
                const price = inv.pricing || {};

                modalBody.innerHTML = `
            <div class="invoice-box">
                <!-- Invoice Header / Letterhead -->
                <div class="invoice-header-row" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                    <div>
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                            <i class="fa-solid fa-hotel" style="font-size:28px; color:var(--primary);"></i>
                            <h2 style="margin:0; font-size:22px; font-weight:800; color:var(--text-primary);">${comp.name || 'Yaan Platform'}</h2>
                        </div>
                        <div style="font-size:12px; color:var(--text-secondary); line-height:1.4;">
                            <div>${comp.address}</div>
                            <div>${comp.city} • Email: ${comp.email}</div>
                            <div><strong>GSTIN:</strong> ${comp.gstin}</div>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <span class="badge ${pay.is_refunded || pay.status === 'REFUNDED' ? 'failed' : (pay.status === 'PAID' || pay.status === 'CONFIRMED' ? 'confirmed' : 'pending')}" style="font-size:12px; padding:6px 12px; text-transform:uppercase; ${pay.is_refunded || pay.status === 'REFUNDED' ? 'background:#e11d48; color:#ffffff;' : ''}">
                            ${pay.is_refunded || pay.status === 'REFUNDED' ? 'REFUNDED (RAZORPAY)' : pay.status}
                        </span>
                        <h3 style="margin:8px 0 2px 0; font-size:18px; color:var(--primary); font-family:monospace;">${inv.invoice_number}</h3>
                        <div style="font-size:12px; color:var(--text-muted);">Date: <strong>${inv.invoice_date}</strong></div>
                    </div>
                </div>

                <hr style="border:0; border-top:1px solid var(--border); margin:16px 0;">

                <!-- Customer & Hotel Columns -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border);">
                        <h4 style="margin:0 0 6px 0; font-size:13px; color:var(--primary);"><i class="fa-solid fa-user"></i> Billed To (Customer)</h4>
                        <div style="font-size:12px; display:flex; flex-direction:column; gap:2px;">
                            <div><strong>${cust.name}</strong></div>
                            <div>Email: ${cust.email}</div>
                            <div>Phone: ${cust.phone}</div>
                        </div>
                    </div>
                    <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border);">
                        <h4 style="margin:0 0 6px 0; font-size:13px; color:var(--primary);"><i class="fa-solid fa-building-user"></i> Hotel & Stay Details</h4>
                        <div style="font-size:12px; display:flex; flex-direction:column; gap:2px;">
                            <div><strong>${hotel.name}</strong> (${hotel.city})</div>
                            <div>${hotel.address}</div>
                            <div>Check-in: <strong>${stay.check_in}</strong> to <strong>${stay.check_out}</strong></div>
                        </div>
                    </div>
                </div>

                <!-- Logistics & Vehicle Info -->
                <div style="background:var(--bg-dark); padding:10px 12px; border-radius:8px; border:1px solid var(--border); margin-bottom:16px;">
                    <div style="font-size:12px; font-weight:700; color:var(--info); margin-bottom:4px;"><i class="fa-solid fa-truck"></i> Vehicle & Transport Details</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:12px;">
                        <div><strong>Truck No:</strong> ${logi.truck_no} (${logi.truck_type})</div>
                        <div><strong>Logistics Partner:</strong> ${logi.logistics_name} (${logi.logistics_number})</div>
                    </div>
                </div>

                <!-- Financial Table -->
                <table class="custom-table" style="margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align:center;">Nights</th>
                            <th style="text-align:right;">Rate / Night</th>
                            <th style="text-align:right;">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>${hotel.name} Booking</strong><br>
                                <small class="text-muted">Accommodation & Parking for ${stay.check_in} to ${stay.check_out}</small>
                            </td>
                            <td style="text-align:center;">${stay.total_nights}</td>
                            <td style="text-align:right;">₹${(price.price_per_night || 0).toFixed(2)}</td>
                            <td style="text-align:right;">₹${(price.subtotal || 0).toFixed(2)}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Summary Breakdown -->
                <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:16px; margin-bottom:16px;">
                    <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); font-size:12px; display:flex; flex-direction:column; gap:4px;">
                        <h4 style="margin:0 0 6px 0; font-size:13px; color:#a855f7;"><i class="fa-solid fa-shield-halved"></i> Payment Metadata</h4>
                        <div><strong>Payment Method:</strong> ${pay.payment_method}</div>
                        <div><strong>Transaction ID:</strong> <span style="font-family:monospace; color:var(--success); font-weight:700;">${pay.display_transaction_id}</span></div>
                        ${pay.razorpay_order_id ? `<div><strong>Razorpay Order ID:</strong> <span style="font-family:monospace;">${pay.razorpay_order_id}</span></div>` : ''}
                        ${pay.razorpay_payment_id ? `<div><strong>Razorpay Payment ID:</strong> <span style="font-family:monospace;">${pay.razorpay_payment_id}</span></div>` : ''}
                        <div><strong>Region Timestamp:</strong> ${pay.region_time}</div>
                        ${pay.is_refunded || pay.status === 'REFUNDED' ? `
                            <div style="background:rgba(225,29,72,0.12); border:1px solid #e11d48; padding:8px 10px; border-radius:6px; margin-top:6px;">
                                <div style="color:#fecdd3; font-weight:700; font-size:12px; margin-bottom:2px;">
                                    <i class="fa-solid fa-arrow-rotate-left"></i> Payment Refunded via Razorpay
                                </div>
                                ${pay.refund_id ? `<div><strong>Razorpay Refund ID:</strong> <span style="font-family:monospace; color:#f43f5e; font-weight:700;">${pay.refund_id}</span></div>` : ''}
                                ${pay.refund_time_formatted ? `<div><strong>Refund Date & Time:</strong> <span style="color:#e9d5ff; font-weight:700;">${pay.refund_time_formatted}</span></div>` : ''}
                                ${pay.cancellation_reason ? `<div style="font-size:11px; color:#fda4af; margin-top:2px;"><strong>Reason / Note:</strong> ${pay.cancellation_reason}</div>` : ''}
                            </div>
                        ` : ''}
                    </div>

                    <div style="background:var(--bg-dark); padding:12px; border-radius:8px; border:1px solid var(--border); display:flex; flex-direction:column; gap:6px; font-size:13px;">
                        <div style="display:flex; justify-content:space-between;"><span>Subtotal:</span> <strong>₹${(price.subtotal || 0).toFixed(2)}</strong></div>
                        ${price.promotion_applied > 0 ? `<div style="display:flex; justify-content:space-between; color:var(--success);"><span>Discount:</span> <strong>-₹${(price.promotion_applied).toFixed(2)}</strong></div>` : ''}
                        <div style="display:flex; justify-content:space-between;"><span>GST (18%):</span> <strong>₹${(price.gst_amount || 0).toFixed(2)}</strong></div>
                        <hr style="border:0; border-top:1px solid var(--border); margin:4px 0;">
                        <div style="display:flex; justify-content:space-between; font-size:16px; color:var(--primary);"><span>Total Payable:</span> <strong>₹${(price.total_payable || 0).toFixed(2)}</strong></div>
                    </div>
                </div>

                <div style="text-align:center; font-size:11px; color:var(--text-muted); border-top:1px solid var(--border); padding-top:12px;">
                    Thank you for booking with Yaan! This is a computer-generated tax invoice and requires no physical signature.
                </div>
            </div>
        `;
            } catch (err) {
                modalBody.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error generating invoice'}</div>`;
            }
        }

        function closeInvoiceModal() {
            document.getElementById('invoice-modal').classList.add('hidden');
        }

        function printInvoice() {
            window.print();
        }

        async function verifyRazorpayStatus(txnId) {
            showToast('Contacting Razorpay API...', 'info');
            try {
                const res = await fetch(`${API_BASE}/admin/transactions/${txnId}/verify-razorpay`, {
                    method: 'POST',
                    headers: getHeaders()
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Razorpay verification failed');

                const t = data.transaction || {};
                if (t.payment_status === 'refunded' || data.live_status === 'refunded') {
                    showToast('Razorpay verified: Payment is REFUNDED! Status updated in system.', 'success');
                } else if (data.live_status === 'captured' || data.live_status === 'authorized' || (data.transaction && (data.transaction.payment_status === 'paid' || data.transaction.status === 'confirmed'))) {
                    showToast('Payment verified with Razorpay! Status: Confirmed Success.', 'success');
                } else {
                    showToast(data.message || 'Razorpay status synced!', 'info');
                }
                loadTransactionsData();
                // Refresh modal if open
                if (!document.getElementById('transaction-modal').classList.contains('hidden')) {
                    openTransactionModal(txnId);
                }
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        async function refundTransactionDirect(txnId) {
            const txn = currentTransactions.find(t => t.id === txnId);
            const customerName = (txn && txn.user) ? txn.user.name : 'Customer';
            const refundAmount = txn ? (txn.total_payable || txn.total_amount || 0) : 0;

            let reason = prompt(`[Razorpay Refund Request]\nEnter reason for refunding Transaction #${txnId} (min 5 characters):`, 'Customer requested refund');
            if (reason === null) return;
            reason = reason.trim();

            if (reason.length < 5) {
                showToast('Refund cancelled: A detailed reason (minimum 5 characters) is required.', 'danger');
                return;
            }

            const approved = confirm(`🔔 CONFIRM RAZORPAY REFUND:\n\nBooking/Txn ID: #${txnId}\nCustomer: ${customerName}\nRefund Amount: ₹${refundAmount}\nReason: ${reason}\n\nDo you explicitly APPROVE issuing this refund to the customer via Razorpay API?`);
            if (!approved) {
                showToast('Refund request cancelled by Admin.', 'warning');
                return;
            }

            showToast('Initiating refund with Razorpay API...', 'info');
            try {
                const res = await fetch(`${API_BASE}/admin/transactions/${txnId}/refund`, {
                    method: 'POST',
                    headers: getHeaders(),
                    body: JSON.stringify({ reason })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Razorpay refund failed');

                showToast(data.message || 'Refund processed with Razorpay successfully!', 'success');
                loadTransactionsData();
                if (!document.getElementById('transaction-modal').classList.contains('hidden')) {
                    closeTransactionModal();
                }
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

        async function updateTransactionStatus(txnId, newStatus) {
            if (!newStatus) return;

            const txn = currentTransactions.find(t => t.id === txnId);
            const isPaid = txn && (txn.payment_status === 'paid' || txn.is_confirmed || (txn.status === 'confirmed' && txn.razorpay_payment_id));

            let reason = prompt(`[Admin Override Audit Log]\nEnter mandatory reason for setting status to "${newStatus}" (min 5 characters):`,
                isPaid && newStatus === 'cancelled' ? 'Customer requested refund / Cancelled by Admin' : 'Admin manual status override');

            if (reason === null) return; // User clicked Cancel in prompt
            reason = reason.trim();

            if (reason.length < 5) {
                showToast('Status override failed: A detailed reason (minimum 5 characters) is required for audit logs.', 'danger');
                return;
            }

            try {
                // If payment was captured and admin is cancelling, call Razorpay Refund API with strict Admin approval prompt
                if (newStatus === 'cancelled' && isPaid) {
                    const customerName = (txn && txn.user) ? txn.user.name : 'Customer';
                    const refundAmount = txn ? (txn.total_payable || txn.total_amount || 0) : 0;

                    const adminApproved = confirm(`🔔 ADMIN REFUND APPROVAL REQUIRED:\n\nBooking ID: #${txnId}\nCustomer: ${customerName}\nRefund Amount: ₹${refundAmount}\nReason: ${reason}\n\nDo you explicitly APPROVE issuing this refund to the customer via Razorpay?\n\n• Click OK (YES) to APPROVE & issue the refund.\n• Click CANCEL (NO) to REJECT & keep money.`);

                    if (!adminApproved) {
                        showToast('Refund request REJECTED by Admin. No money was refunded.', 'warning');
                        loadTransactionsData();
                        return;
                    }

                    showToast('Initiating refund with Razorpay API...', 'info');
                    const res = await fetch(`${API_BASE}/admin/transactions/${txnId}/refund`, {
                        method: 'POST',
                        headers: getHeaders(),
                        body: JSON.stringify({ reason })
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error || data.message || 'Razorpay refund failed');

                    showToast(data.message || 'Refund initiated with Razorpay successfully!', 'success');
                    loadTransactionsData();
                    return;
                }

                // Standard status override (Prompt 3 Logged Override)
                const res = await fetch(`${API_BASE}/admin/transactions/${txnId}/status`, {
                    method: 'PUT',
                    headers: getHeaders(),
                    body: JSON.stringify({
                        status: newStatus,
                        payment_status: newStatus === 'confirmed' ? 'paid' : (newStatus === 'cancelled' ? 'failed' : 'pending'),
                        cancellation_reason: reason
                    })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || data.message || 'Failed to update transaction status');

                showToast(data.message || `Transaction updated to ${newStatus}`, 'success');
                loadTransactionsData();
            } catch (err) {
                showToast(err.message, 'danger');
            }
        }

// ----------------------------------------------------
// UTILITIES & TOAST NOTIFICATIONS
// ----------------------------------------------------
async function handleApiError(res) {
    let errorMsg = `Server Error (${res.status})`;
    try {
        const data = await res.json();
        errorMsg = data.error || data.message || errorMsg;
    } catch (e) { }

    if (res.status === 401 || res.status === 403) {
        authToken = '';
        localStorage.removeItem('yaan_admin_token');
        localStorage.removeItem('yaan_admin_user');
        showLoginScreen();
        throw new Error(errorMsg || 'Session expired. Please login again.');
    }
    throw new Error(errorMsg);
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let icon = 'fa-circle-info';
    if (type === 'success') icon = 'fa-circle-check';
    if (type === 'danger') icon = 'fa-triangle-exclamation';

    toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, 3500);
}

// ----------------------------------------------------
// 8. BANNERS & OFFERS BRAND MANAGEMENT
// ----------------------------------------------------
let currentBanners = [];

async function loadBannersData() {
    const container = document.getElementById('banners-container');
    if (!container) return;
    container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Loading banners & offers...</div>`;

    try {
        const res = await fetch(`${API_BASE}/admin/banners`, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        currentBanners = data.data || [];

        if (currentBanners.length === 0) {
            container.innerHTML = `<div class="empty-state">No promotional banners created yet. Click "Create New Banner" to add one.</div>`;
            return;
        }

        container.innerHTML = currentBanners.map(b => {
            const audienceBadge = b.target_audience === 'user'
                ? `<span class="badge pending" style="background:rgba(56,189,248,0.15); color:#38bdf8; border:1px solid rgba(56,189,248,0.3);"><i class="fa-solid fa-user"></i> Customers Only</span>`
                : (b.target_audience === 'owner'
                    ? `<span class="badge pending" style="background:rgba(250,204,21,0.15); color:#facc15; border:1px solid rgba(250,204,21,0.3);"><i class="fa-solid fa-user-tie"></i> Hotel Owners Only</span>`
                    : `<span class="badge approved" style="background:rgba(168,85,247,0.15); color:#c084fc; border:1px solid rgba(168,85,247,0.3);"><i class="fa-solid fa-users"></i> All (Users & Owners)</span>`);

            const capacityBadge = b.max_uses
                ? `<span class="badge pending" style="background:rgba(234,179,8,0.15); color:#eab308; border:1px solid rgba(234,179,8,0.3);"><i class="fa-solid fa-users"></i> First ${b.max_uses} Customers Only</span>`
                : `<span class="badge approved" style="background:rgba(34,197,94,0.12); color:#22c55e; border:1px solid rgba(34,197,94,0.3);"><i class="fa-solid fa-users-line"></i> All Customers</span>`;

            const statusBadge = b.is_active
                ? `<span class="badge approved" style="cursor:pointer;" onclick="toggleBannerStatus(${b.id})" title="Click to disable"><i class="fa-solid fa-circle-check"></i> ACTIVE</span>`
                : `<span class="badge alert" style="cursor:pointer;" onclick="toggleBannerStatus(${b.id})" title="Click to enable"><i class="fa-solid fa-circle-xmark"></i> INACTIVE</span>`;

            const fallbackImage = 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?auto=format&fit=crop&w=800&q=80';
            const imgUrl = b.image_url || fallbackImage;

            return `
    <div class="data-card" style="display:flex; flex-direction:column; justify-content:space-between; overflow:hidden;">
        <div style="height: 140px; width: 100%; background:#0f172a; border-radius:8px; overflow:hidden; position:relative; margin-bottom:12px;">
            <img src="${imgUrl}" alt="${b.title}" style="width:100%; height:100%; object-fit:cover;" onerror="this.src='${fallbackImage}'">
            <div style="position:absolute; top:8px; right:8px;">${statusBadge}</div>
        </div>

        <div style="flex:1;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; gap:8px;">
                <h4 style="margin:0; font-size:15px; color:var(--text-primary); font-weight:700;">${b.title}</h4>
                ${b.discount_percentage ? `<span style="background:var(--success); color:#fff; font-weight:700; padding:2px 8px; border-radius:4px; font-size:11px; flex-shrink:0;">${b.discount_percentage}% OFF</span>` : ''}
            </div>

            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px;">
                ${audienceBadge}
                ${capacityBadge}
            </div>

            <p style="font-size:12px; color:var(--text-secondary); line-height:1.4; margin: 6px 0 10px 0;">
                ${b.description || 'No detailed description provided.'}
            </p>

            ${b.discount_code ? `
                <div style="background:var(--bg-dark); border:1px dashed var(--primary); padding:6px 10px; border-radius:6px; font-size:12px; font-weight:700; color:var(--primary); display:inline-block; margin-bottom:10px;">
                    <i class="fa-solid fa-ticket"></i> CODE: ${b.discount_code}
                </div>
            ` : ''}

            ${b.expires_at ? `
                <div style="font-size:11px; color:var(--text-muted); margin-bottom:10px;">
                    <i class="fa-solid fa-hourglass-half"></i> Expires: <strong>${formatDateClean(b.expires_at)}</strong>
                </div>
            ` : ''}
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; pt-2; border-top:1px solid var(--border); margin-top:10px;">
            <button class="btn-sm" style="background:var(--bg-dark); border:1px solid var(--border); color:#fff;" onclick="openEditBannerModal(${b.id})">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </button>
            <button class="btn-sm btn-danger" onclick="deleteBanner(${b.id})">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
    `;
        }).join('');
    } catch (err) {
        container.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading banners'}</div>`;
    }
}

function openCreateBannerModal() {
    document.getElementById('banner-form').reset();
    document.getElementById('banner-id').value = '';
    const maxUsesElem = document.getElementById('banner-max-uses');
    if (maxUsesElem) maxUsesElem.value = '';
    document.getElementById('banner-modal-title').innerHTML = `<i class="fa-solid fa-rectangle-ad"></i> Create New Banner / Offer`;
    document.getElementById('banner-modal').classList.remove('hidden');
}

function openEditBannerModal(id) {
    const b = currentBanners.find(x => x.id === id);
    if (!b) return;

    document.getElementById('banner-id').value = b.id;
    document.getElementById('banner-title').value = b.title || '';
    document.getElementById('banner-description').value = b.description || '';
    document.getElementById('banner-target-audience').value = b.target_audience || 'all';
    document.getElementById('banner-discount-code').value = b.discount_code || '';
    document.getElementById('banner-discount-percentage').value = b.discount_percentage || '';
    const maxUsesElem = document.getElementById('banner-max-uses');
    if (maxUsesElem) maxUsesElem.value = b.max_uses || '';
    document.getElementById('banner-expires-at').value = b.expires_at ? b.expires_at.split('T')[0] : '';
    document.getElementById('banner-image-url').value = (b.image_path && b.image_path.startsWith('http')) ? b.image_path : '';
    document.getElementById('banner-image-file').value = '';

    document.getElementById('banner-modal-title').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit Banner / Offer`;
    document.getElementById('banner-modal').classList.remove('hidden');
}

function closeBannerModal() {
    document.getElementById('banner-modal').classList.add('hidden');
}

async function saveBanner(event) {
    if (event) event.preventDefault();

    const id = document.getElementById('banner-id').value;
    const title = document.getElementById('banner-title').value.trim();
    const description = document.getElementById('banner-description').value.trim();
    const targetAudience = document.getElementById('banner-target-audience').value;
    const discountCode = document.getElementById('banner-discount-code').value.trim();
    const discountPercentage = document.getElementById('banner-discount-percentage').value;
    const maxUsesElem = document.getElementById('banner-max-uses');
    const maxUses = maxUsesElem ? maxUsesElem.value : '';
    const expiresAt = document.getElementById('banner-expires-at').value;
    const imageUrl = document.getElementById('banner-image-url').value.trim();
    const imageFile = document.getElementById('banner-image-file').files[0];

    const formData = new FormData();
    formData.append('title', title);
    formData.append('description', description);
    formData.append('target_audience', targetAudience);
    if (discountCode) formData.append('discount_code', discountCode);
    if (discountPercentage !== '') formData.append('discount_percentage', discountPercentage);
    if (maxUses !== '') formData.append('max_uses', maxUses);
    if (expiresAt !== '') formData.append('expires_at', expiresAt);
    if (imageUrl) formData.append('image_url', imageUrl);
    if (imageFile) formData.append('image', imageFile);

    const isEdit = !!id;
    const url = isEdit ? `${API_BASE}/admin/banners/${id}` : `${API_BASE}/admin/banners`;

    if (isEdit) {
        formData.append('_method', 'PUT');
    }

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Authorization': `Bearer ${authToken}`
            },
            body: formData
        });
        const data = await res.json();
        if (!res.ok) {
            let errorMsg = data.error || data.message || 'Failed to save banner';
            if (data.errors && typeof data.errors === 'object') {
                const fieldErrors = Object.values(data.errors).flat().join(' | ');
                if (fieldErrors) errorMsg = fieldErrors;
            }
            throw new Error(errorMsg);
        }

        showToast(data.message || 'Banner saved successfully', 'success');
        closeBannerModal();
        loadBannersData();
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

async function toggleBannerStatus(id) {
    try {
        const res = await fetch(`${API_BASE}/admin/banners/${id}/status`, {
            method: 'PUT',
            headers: getHeaders()
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to update status');

        showToast(data.message || 'Status updated', 'success');
        loadBannersData();
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

async function deleteBanner(id) {
    if (!confirm('Are you sure you want to delete this banner?')) return;

    try {
        const res = await fetch(`${API_BASE}/admin/banners/${id}`, {
            method: 'DELETE',
            headers: getHeaders()
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to delete banner');

        showToast(data.message || 'Banner deleted successfully', 'success');
        loadBannersData();
    } catch (err) {
        showToast(err.message, 'danger');
    }
}