// Global State & API Configuration
const API_BASE = '/api';
let authToken = localStorage.getItem('yaan_admin_token') || '';
let currentUser = JSON.parse(localStorage.getItem('yaan_admin_user') || 'null');
let currentOwners = [];

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
    event.preventDefault();
    const email = document.getElementById('login-email').value.trim();
    const password = document.getElementById('login-password').value;
    const role = document.getElementById('login-role').value;

    const errorBanner = document.getElementById('login-error');
    const errorText = document.getElementById('login-error-text');
    errorBanner.classList.add('hidden');

    const btnLogin = document.getElementById('btn-login');
    btnLogin.disabled = true;
    btnLogin.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> authenticating...`;

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

        showToast('Login successful!', 'success');
        showMainApp();
    } catch (err) {
        errorText.textContent = err.message;
        errorBanner.classList.remove('hidden');
    } finally {
        btnLogin.disabled = false;
        btnLogin.innerHTML = `<span>Sign In to Admin App</span> <i class="fa-solid fa-arrow-right"></i>`;
    }
}

function handleLogout() {
    if (confirm('Are you sure you want to log out?')) {
        if (authToken) {
            fetch(`${API_BASE}/logout`, {
                method: 'POST',
                headers: getHeaders()
            }).catch(() => {});
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
    document.getElementById('login-screen').classList.add('active');
    document.getElementById('main-shell').classList.add('hidden');
}

function showMainApp() {
    document.getElementById('login-screen').classList.remove('active');
    document.getElementById('main-shell').classList.remove('hidden');
    if (currentUser) {
        document.getElementById('admin-name-display').textContent = currentUser.name || 'Admin';
    }
    loadDashboardData();
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
    if (tabId === 'users') loadUsersData();
    if (tabId === 'reviews') loadReviewsData();
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

// ----------------------------------------------------
// 1. DASHBOARD DATA
// ----------------------------------------------------
async function loadDashboardData() {
    try {
        const res = await fetch(`${API_BASE}/admin/dashboard`, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const m = data.metrics || {};

        document.getElementById('stat-revenue').textContent = `₹${(m.total_revenue || 0).toLocaleString('en-IN')}`;
        document.getElementById('stat-bookings').textContent = m.total_bookings || 0;
        document.getElementById('stat-pending-hotels').textContent = m.pending_hotels || 0;
        document.getElementById('stat-pending-owners').textContent = m.pending_owners || 0;
        document.getElementById('stat-users').textContent = m.users_count || 0;
        document.getElementById('stat-owners').textContent = m.owners_count || 0;
        document.getElementById('stat-approved-hotels').textContent = m.approved_hotels || 0;

        // Badges in drawer
        const bHotels = document.getElementById('badge-pending-hotels');
        const bOwners = document.getElementById('badge-pending-owners');
        if (m.pending_hotels > 0) {
            bHotels.textContent = m.pending_hotels;
            bHotels.classList.remove('hidden');
        } else {
            bHotels.classList.add('hidden');
        }

        if (m.pending_owners > 0) {
            bOwners.textContent = m.pending_owners;
            bOwners.classList.remove('hidden');
        } else {
            bOwners.classList.add('hidden');
        }

        renderRecentBookings(data.recent_bookings);
        renderRecentHotels(data.recent_hotels);
    } catch (err) {
        console.error('Failed to load dashboard:', err);
    }
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
async function loadHotelsData() {
    const container = document.getElementById('hotels-container');
    const status = document.getElementById('filter-hotel-status').value;
    const search = document.getElementById('search-hotels').value;

    container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Loading hotels...</div>`;

    try {
        let url = `${API_BASE}/admin/hotels?status=${status}&search=${encodeURIComponent(search)}`;
        const res = await fetch(url, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const hotels = data.data || [];

        if (hotels.length === 0) {
            container.innerHTML = `<div class="empty-state">No hotels found</div>`;
            return;
        }

        container.innerHTML = hotels.map(h => `
            <div class="data-card">
                <div class="data-card-header">
                    <div class="data-card-title">
                        <h4>${h.name}</h4>
                        <div class="data-card-sub"><i class="fa-solid fa-location-dot"></i> ${h.city || 'N/A'} • ${h.address || ''}</div>
                    </div>
                    <span class="badge ${h.status}">${h.status}</span>
                </div>
                <div style="font-size:13px; color: var(--text-secondary); margin: 8px 0;">
                    <div><strong>Owner:</strong> ${h.owner ? h.owner.name : 'Unknown'} (${h.owner ? h.owner.email : ''})</div>
                    <div><strong>Rooms:</strong> ${h.total_rooms || 0} Total • ₹${h.price_per_night}/night</div>
                </div>
                <div style="display:flex; gap: 8px; margin-top: auto;">
                    ${h.status !== 'approved' ? `<button class="btn-sm btn-success" onclick="updateHotelStatus(${h.id}, 'approved')"><i class="fa-solid fa-check"></i> Approve</button>` : ''}
                    ${h.status !== 'rejected' ? `<button class="btn-sm btn-danger" onclick="updateHotelStatus(${h.id}, 'rejected')"><i class="fa-solid fa-xmark"></i> Reject</button>` : ''}
                    ${h.status === 'approved' ? `<button class="btn-sm btn-warning" onclick="updateHotelStatus(${h.id}, 'suspended')"><i class="fa-solid fa-ban"></i> Suspend</button>` : ''}
                </div>
            </div>
        `).join('');
    } catch (err) {
        container.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading hotels'}</div>`;
    }
}

async function updateHotelStatus(id, newStatus) {
    if (!confirm(`Are you sure you want to change hotel status to ${newStatus}?`)) return;

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
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

// ----------------------------------------------------
// 3. OWNER KYC MANAGEMENT
// ----------------------------------------------------
async function loadOwnersData() {
    const container = document.getElementById('owners-container');
    const verified = document.getElementById('filter-owner-verified').value;
    const search = document.getElementById('search-owners').value;

    container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i> Loading owners...</div>`;

    try {
        let url = `${API_BASE}/admin/owners?verified=${verified}&search=${encodeURIComponent(search)}`;
        const res = await fetch(url, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        currentOwners = data.data || [];

        if (currentOwners.length === 0) {
            container.innerHTML = `<div class="empty-state">No owners found</div>`;
            return;
        }

        container.innerHTML = currentOwners.map(o => {
            const profile = o.owner_profile || o.ownerProfile || {};
            const isVerified = profile.is_profile_complete !== undefined ? profile.is_profile_complete : o.is_verified;
            return `
            <div class="data-card">
                <div class="data-card-header">
                    <div class="data-card-title">
                        <h4>${o.name}</h4>
                        <div class="data-card-sub"><i class="fa-solid fa-envelope"></i> ${o.email} • <i class="fa-solid fa-phone"></i> ${o.phone}</div>
                    </div>
                    <span class="badge ${isVerified ? 'verified' : 'pending'}">${isVerified ? 'Verified KYC' : 'Pending KYC'}</span>
                </div>
                <div style="font-size:13px; color: var(--text-secondary); display:flex; flex-direction:column; gap:4px; background:#f9f9f9; padding:8px; border-radius:6px; margin: 8px 0;">
                    <div><strong>Hotel:</strong> ${profile.hotel_name || 'N/A'}</div>
                    <div><strong>Listed Hotels:</strong> ${o.hotels_count || 0}</div>
                    ${profile.gst_number ? `<div><strong>GST No:</strong> ${profile.gst_number}</div>` : ''}
                    ${profile.fssai_number ? `<div><strong>FSSAI No:</strong> ${profile.fssai_number}</div>` : ''}
                    ${profile.bank_name ? `<div><strong>Bank:</strong> ${profile.bank_name} (${profile.account_number || ''}) - ${profile.ifsc_code || ''}</div>` : ''}
                </div>
                <div style="display:flex; gap: 8px; margin-top: auto; flex-wrap: wrap;">
                    <button class="btn-sm" style="background:#4a5568; color:white;" onclick="openKycModal(${o.id})"><i class="fa-solid fa-file-invoice"></i> Inspect Full KYC</button>
                    ${!isVerified ? 
                        `<button class="btn-sm btn-success" onclick="verifyOwner(${o.id}, true)"><i class="fa-solid fa-user-check"></i> Approve KYC</button>` : 
                        `<button class="btn-sm btn-warning" onclick="verifyOwner(${o.id}, false)"><i class="fa-solid fa-user-xmark"></i> Revoke KYC</button>`
                    }
                </div>
            </div>
            `;
        }).join('');
    } catch (err) {
        container.innerHTML = `<div class="empty-state text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading owners'}</div>`;
    }
}

function openKycModal(ownerId) {
    const owner = currentOwners.find(o => o.id === ownerId);
    if (!owner) return;

    const profile = owner.owner_profile || owner.ownerProfile || {};
    const isVerified = profile.is_profile_complete !== undefined ? profile.is_profile_complete : owner.is_verified;

    const getDocItem = (title, path, url) => {
        if (!url && !path) return '';
        const docUrl = url || (path ? (path.startsWith('http') ? path : `/storage/${path.replace(/^\/+/, '')}`) : '#');
        const isImg = docUrl.match(/\.(jpeg|jpg|gif|png|webp)($|\?)/i);
        return `
            <div class="doc-box">
                <strong style="display:block; font-size:12px; margin-bottom:6px;">${title}</strong>
                ${isImg ? `<a href="${docUrl}" target="_blank"><img src="${docUrl}" alt="${title}" onerror="this.src='https://via.placeholder.com/150?text=Document';"></a>` : ''}
                <a href="${docUrl}" target="_blank" class="btn-sm" style="display:inline-block; font-size:11px; margin-top:4px; text-decoration:none;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open File
                </a>
            </div>
        `;
    };

    const modalBody = document.getElementById('kyc-modal-body');
    modalBody.innerHTML = `
        <div style="margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <h4 style="margin:0; font-size:18px;">${owner.name}</h4>
                <span class="badge ${isVerified ? 'verified' : 'pending'}">${isVerified ? 'Verified KYC' : 'Pending KYC'}</span>
            </div>
            <div style="font-size:13px; color:var(--text-secondary); display:flex; flex-direction:column; gap:4px;">
                <div><i class="fa-solid fa-envelope"></i> <strong>Email:</strong> ${owner.email}</div>
                <div><i class="fa-solid fa-phone"></i> <strong>Phone:</strong> ${owner.phone}</div>
                <div><i class="fa-solid fa-hotel"></i> <strong>Hotel Name:</strong> ${profile.hotel_name || 'N/A'}</div>
                <div><i class="fa-solid fa-location-dot"></i> <strong>Address:</strong> ${profile.address || 'N/A'}, ${profile.city || ''}, ${profile.state || ''} ${profile.pincode || ''}</div>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border); margin:16px 0;">

        <div style="margin-bottom:16px;">
            <h5 style="margin:0 0 8px 0; font-size:14px;"><i class="fa-solid fa-building-columns"></i> Tax & Bank Information</h5>
            <div style="font-size:13px; color:var(--text-secondary); display:grid; grid-template-columns:1fr 1fr; gap:8px; background:var(--bg-surface); padding:10px; border-radius:6px;">
                <div><strong>GST Number:</strong> ${profile.gst_number || 'N/A'}</div>
                <div><strong>FSSAI Number:</strong> ${profile.fssai_number || 'N/A'}</div>
                <div><strong>Bank Name:</strong> ${profile.bank_name || 'N/A'}</div>
                <div><strong>Account No:</strong> ${profile.account_number || 'N/A'}</div>
                <div><strong>IFSC Code:</strong> ${profile.ifsc_code || 'N/A'}</div>
            </div>
        </div>

        <div>
            <h5 style="margin:0 0 8px 0; font-size:14px;"><i class="fa-solid fa-file-shield"></i> Uploaded KYC Documents</h5>
            <div class="doc-grid">
                ${getDocItem('Aadhaar Front', profile.aadhaar_front, profile.aadhaar_front_url)}
                ${getDocItem('Aadhaar Back', profile.aadhaar_back, profile.aadhaar_back_url)}
                ${getDocItem('PAN Card', profile.pan_card, profile.pan_card_url)}
                ${getDocItem('GST Certificate', profile.gst_image, profile.gst_image_url)}
                ${getDocItem('FSSAI License', profile.fssai_license, profile.fssai_license_url)}
                ${getDocItem('Business Proof', profile.business_proof, profile.business_proof_url)}
            </div>
            ${(!profile.aadhaar_front && !profile.pan_card && !profile.business_proof) ? '<div class="empty-state">No document files uploaded by owner yet</div>' : ''}
        </div>

        <div style="display:flex; gap:10px; margin-top:20px; justify-content:flex-end;">
            ${!isVerified ? 
                `<button class="btn-sm btn-success" style="padding:8px 16px;" onclick="verifyOwner(${owner.id}, true); closeKycModal();"><i class="fa-solid fa-check"></i> Approve Owner KYC</button>` : 
                `<button class="btn-sm btn-warning" style="padding:8px 16px;" onclick="verifyOwner(${owner.id}, false); closeKycModal();"><i class="fa-solid fa-xmark"></i> Revoke KYC</button>`
            }
            <button class="btn-sm" style="padding:8px 16px;" onclick="closeKycModal()">Close</button>
        </div>
    `;

    document.getElementById('kyc-modal').classList.remove('hidden');
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

    tbody.innerHTML = `<tr><td colspan="8" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Loading bookings...</td></tr>`;

    try {
        let url = `${API_BASE}/admin/bookings?status=${status}&search=${encodeURIComponent(search)}`;
        const res = await fetch(url, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const bookings = data.data || [];

        if (bookings.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center">No bookings found</td></tr>`;
            return;
        }

        tbody.innerHTML = bookings.map(b => `
            <tr>
                <td>#${b.id}</td>
                <td><strong>${b.user ? b.user.name : 'Guest'}</strong><br><small class="text-muted">${b.user ? b.user.phone : ''}</small></td>
                <td>${b.hotel ? b.hotel.name : 'N/A'}<br><small class="text-muted">${b.hotel ? b.hotel.city : ''}</small></td>
                <td>${b.check_in || ''} to ${b.check_out || ''}</td>
                <td><strong>₹${b.total_amount || 0}</strong></td>
                <td><span class="badge ${b.payment_status || 'pending'}">${b.payment_status || 'pending'}</span></td>
                <td><span class="badge ${b.status}">${b.status}</span></td>
                <td>
                    <select onchange="updateBookingStatus(${b.id}, this.value)" style="padding: 4px; font-size:12px;">
                        <option value="">Change</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading bookings'}</td></tr>`;
    }
}

async function updateBookingStatus(id, status) {
    if (!status) return;

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
    const search = document.getElementById('search-users').value;

    tbody.innerHTML = `<tr><td colspan="6" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Loading users...</td></tr>`;

    try {
        let url = `${API_BASE}/admin/users?search=${encodeURIComponent(search)}`;
        const res = await fetch(url, { headers: getHeaders() });
        if (!res.ok) await handleApiError(res);
        const data = await res.json();
        const users = data.data || [];

        if (users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center">No users found</td></tr>`;
            return;
        }

        tbody.innerHTML = users.map(u => `
            <tr>
                <td>#${u.id}</td>
                <td><strong>${u.name}</strong></td>
                <td>${u.email}<br><small class="text-muted">${u.phone}</small></td>
                <td>${u.bookings_count || 0} bookings</td>
                <td><span class="badge ${u.is_verified ? 'verified' : 'pending'}">${u.is_verified ? 'Active' : 'Unverified'}</span></td>
                <td>
                    <button class="btn-sm ${u.is_verified ? 'btn-warning' : 'btn-success'}" onclick="toggleUserStatus(${u.id}, ${!u.is_verified})">
                        ${u.is_verified ? 'Disable' : 'Activate'}
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message || 'Error loading users'}</td></tr>`;
    }
}

async function toggleUserStatus(id, isVerified) {
    try {
        const res = await fetch(`${API_BASE}/admin/users/${id}/status`, {
            method: 'PUT',
            headers: getHeaders(),
            body: JSON.stringify({ is_verified: isVerified })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Failed to update user status');

        showToast(data.message || 'User status updated', 'success');
        loadUsersData();
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

// ----------------------------------------------------
// 6. REVIEWS MODERATION
// ----------------------------------------------------
async function loadReviewsData() {
    const container = document.getElementById('reviews-container');
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
                        <h4>${r.hotel ? r.hotel.name : 'Hotel'}</h4>
                        <div class="data-card-sub">by ${r.user ? r.user.name : 'Anonymous'}</div>
                    </div>
                    <span class="badge approved"><i class="fa-solid fa-star"></i> ${r.rating || 5}</span>
                </div>
                <p style="font-size:13px; color: var(--text-secondary); line-height: 1.4; margin: 8px 0;">
                    "${r.comment || r.review || 'No text comment provided.'}"
                </p>
                <div style="margin-top: auto;">
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
    } catch(e) {}

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
