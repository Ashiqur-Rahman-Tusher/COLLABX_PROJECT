// Centralized App Core Helper & Guard
const App = {
    // 1. Unified & Flexible API Fetcher
    async api(endpoint, methodOrOptions = 'GET', payloadData = null) {
        let method = 'GET';
        let data = null;

        // Support both App.api(url, 'POST', data) AND App.api(url, { method: 'POST', data })
        if (typeof methodOrOptions === 'object' && methodOrOptions !== null) {
            method = methodOrOptions.method || 'GET';
            data = methodOrOptions.data || null;
        } else {
            method = methodOrOptions;
            data = payloadData;
        }

        const options = {
            method: method.toUpperCase(),
            headers: {}
        };

        if (data) {
            if (data instanceof FormData) {
                options.body = data;
            } else {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(data);
            }
        }

        try {
            const response = await fetch(`api/${endpoint}`, options);
            const result = await response.json();

            if (response.status === 401 && !window.location.pathname.includes('login.html')) {
                App.toast('Session expired! Kripaya login korun.', 'error');
                setTimeout(() => window.location.href = 'login.html', 1500);
                return null;
            }

            return result;
        } catch (error) {
            console.error('API Error:', error);
            App.toast('Server-er sathe jogajog korte somossha hoyeche.', 'error');
            return null;
        }
    },

    // 2. Universal Notification / Toast Alert
    toast(message, type = 'info') {
        let toastBox = document.getElementById('toast-box');
        if (!toastBox) {
            toastBox = document.createElement('div');
            toastBox.id = 'toast-box';
            toastBox.className = 'fixed top-5 right-5 z-50 flex flex-col gap-2';
            document.body.appendChild(toastBox);
        }

        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-blue-600';
        toast.className = `${bgColor} text-white px-4 py-3 rounded shadow-lg text-sm transition-all duration-300 transform translate-y-0`;
        toast.innerText = message;

        toastBox.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-y-[-10px]');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },

    // 3. Loading Button Helper
    setBusy(button, isBusy, text = 'Loading...') {
        if (!button) return;
        if (isBusy) {
            button.dataset.originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="inline-block animate-spin mr-2">⏳</span> ${text}`;
        } else {
            button.disabled = false;
            if (button.dataset.originalText) {
                button.innerHTML = button.dataset.originalText;
            }
        }
    },

    // 4. Dynamic Page Session & Role Guard
    async guard(allowedRoles = []) {
        const auth = await App.api('auth/check_auth.php');
        
        if (!auth || auth.status !== 200) {
            window.location.href = 'login.html';
            return null;
        }

        const user = auth.user;
        const userRole = (user.role || user.primary_role || '').toLowerCase();

        // Auto Update User Name in Header
        const nameDisplay = document.querySelector('.user-name-display');
        if (nameDisplay && user.name) {
            nameDisplay.innerText = user.name;
        }

        const avatarDisplay = document.querySelector('.user-avatar-display');
        if (avatarDisplay && user.name) {
            avatarDisplay.innerText = user.name.charAt(0).toUpperCase();
        }

        // Check Role Permissions
        if (allowedRoles.length > 0) {
            const normalizedAllowed = allowedRoles.map(r => r.toLowerCase());
            if (!user.is_admin && !normalizedAllowed.includes(userRole)) {
                App.toast('Apnar ei page-e access korar permission nei.', 'error');
                setTimeout(() => {
                    if (userRole === 'idea_owner' || userRole === 'owner') window.location.href = 'owner-dashboard.html';
                    else if (userRole === 'investor') window.location.href = 'investor-dashboard.html';
                    else if (userRole === 'manpower' || userRole === 'worker') window.location.href = 'manpower-dashboard.html';
                    else window.location.href = 'login.html';
                }, 1500);
                return null;
            }
        }

        // Bind Logout Buttons
        const logoutBtns = document.querySelectorAll('.logout-btn');
        logoutBtns.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                const res = await App.api('auth/logout.php', 'POST');
                if (res && res.status === 200) {
                    window.location.href = 'login.html';
                }
            });
        });

        return user;
    }
};

/**
 * Global Custom Toast Notification & Negotiation System
 * CollabX Platform
 */

// 1. Global Custom Toast Notification
function showToast(message, type = 'success') {
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.className = 'fixed top-5 right-5 z-50 flex flex-col gap-3 pointer-events-none';
        document.body.appendChild(toastContainer);
    }

    const toast = document.createElement('div');
    const isSuccess = type === 'success';

    toast.className = `pointer-events-auto flex items-center gap-3 px-5 py-3.5 rounded-2xl shadow-xl border text-sm font-semibold transition-all duration-300 transform translate-x-10 opacity-0 ${
        isSuccess 
        ? 'bg-emerald-900 text-emerald-100 border-emerald-700' 
        : 'bg-rose-900 text-rose-100 border-rose-700'
    }`;

    toast.innerHTML = `
        <span class="text-base">${isSuccess ? '✅' : '❌'}</span>
        <span>${message}</span>
    `;

    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.classList.remove('translate-x-10', 'opacity-0');
    }, 10);

    setTimeout(() => {
        toast.classList.add('translate-x-10', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// 2. Auto Inject Modal HTML into Document Body
function injectNegotiationModalHTML() {
    if (document.getElementById('negotiationModal')) return;

    const modalHTML = `
    <div id="negotiationModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-stone-200 transform transition-all space-y-6">
        <div>
          <h3 class="text-xl font-serif font-bold text-stone-900">Send Counter Offer</h3>
          <p class="text-xs text-stone-500 mt-1">Submit your revised investment terms for this proposal.</p>
        </div>

        <form id="counterOfferForm" onsubmit="handleCounterOfferSubmit(event)" class="space-y-4">
          <input type="hidden" id="modalInvestorId">
          <input type="hidden" id="modalProjectId">

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-stone-600 mb-1">Counter Offer Amount (BDT)</label>
            <input type="number" id="modalAmount" required class="w-full px-4 py-2.5 rounded-xl border border-stone-300 focus:ring-2 focus:ring-amber-600 focus:outline-none text-stone-900 text-sm font-semibold" placeholder="e.g. 50000">
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-stone-600 mb-1">Profit / Equity Share (%)</label>
            <input type="number" step="0.1" id="modalEquity" required class="w-full px-4 py-2.5 rounded-xl border border-stone-300 focus:ring-2 focus:ring-amber-600 focus:outline-none text-stone-900 text-sm font-semibold" placeholder="e.g. 5">
          </div>

          <div class="flex items-center justify-end gap-3 pt-4 border-t border-stone-100">
            <button type="button" onclick="closeNegotiationModal()" class="px-5 py-2.5 text-xs font-bold text-stone-600 bg-stone-100 hover:bg-stone-200 rounded-xl transition cursor-pointer">
              Cancel
            </button>
            <button type="submit" id="submitModalBtn" class="px-5 py-2.5 text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-xl transition shadow-sm cursor-pointer">
              Submit Counter Offer
            </button>
          </div>
        </form>
      </div>
    </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// 3. Open Modal
function openNegotiationModal(investorUserId, currentAmount, currentEquity, projectId = null) {
    injectNegotiationModalHTML();

    const targetProjectId = projectId || (typeof currentProjectId !== 'undefined' ? currentProjectId : null);

    document.getElementById('modalInvestorId').value = investorUserId;
    document.getElementById('modalProjectId').value = targetProjectId;
    document.getElementById('modalAmount').value = currentAmount || '';
    document.getElementById('modalEquity').value = currentEquity || 5;

    document.getElementById('negotiationModal').classList.remove('hidden');
}

// 4. Close Modal
function closeNegotiationModal() {
    const modal = document.getElementById('negotiationModal');
    if (modal) modal.classList.add('hidden');
}

// 5. Submit Form with Custom Toast
async function handleCounterOfferSubmit(event) {
    event.preventDefault();

    const submitBtn = document.getElementById('submitModalBtn');
    submitBtn.disabled = true;
    submitBtn.innerText = 'Submitting...';

    const investorUserId = document.getElementById('modalInvestorId').value;
    const targetProjectId = document.getElementById('modalProjectId').value;
    const newAmount = document.getElementById('modalAmount').value;
    const newEquity = document.getElementById('modalEquity').value;

    try {
        const formData = new FormData();
        formData.append('action', 'submit_proposal');
        formData.append('project_id', targetProjectId);
        formData.append('user_id', investorUserId);
        formData.append('role', 'investor');
        formData.append('proposed_salary', newAmount);
        formData.append('proposed_percentage', newEquity);
        formData.append('proposed_by', 'idea_owner');

        const response = await fetch('api/agreements/negotiate.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.status === 200) {
            closeNegotiationModal();
            showToast(result.message || "Proposal terms submitted successfully!", 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(result.message || "Failed to submit counter offer.", 'error');
        }
    } catch (error) {
        console.error("Negotiation Error:", error);
        showToast("Server connection error during negotiation!", 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Submit Counter Offer';
    }
}
// Dynamic Link Routing based on Notification Type/Data
function getNotifTargetLink(n) {
  if (n.link && n.link !== '#' && n.link !== '') {
    return n.link;
  }
  
  const type = (n.type || '').toLowerCase();
  if (type === 'deposit' || type === 'withdraw' || type === 'payout' || type === 'wallet') {
    return 'wallet.html';
  }
  if (type === 'agreement' || type === 'proposal' || type === 'negotiate' || type === 'offer') {
    return 'investor-dashboard.html';
  }
  return 'wallet.html';
}

// Mark Notification as Read in DB (Calls api/notifications/mark_as_read.php)
// Mark Notification as Read in DB
async function markNotificationAsRead(notifId, targetUrl) {
  const userId = typeof getCurrentUserId === 'function' ? getCurrentUserId() : 9;
  
  try {
    const formData = new FormData();
    formData.append('notification_id', notifId);
    formData.append('user_id', userId);

    fetch(`api/notifications/mark_as_read.php`, { 
      method: 'POST',
      body: formData 
    });
  } catch (e) {
    console.error('Mark as read error:', e);
  }
  
  if (targetUrl && targetUrl !== '#') {
    window.location.href = targetUrl;
  }
}

// Universal Global Notification Loader
async function loadNotifications() {
  const notifList = document.getElementById('notifList');
  const badge = document.getElementById('notifBadge');
  
  if (!notifList && !badge) return;

  const userId = typeof getCurrentUserId === 'function' ? getCurrentUserId() : 9;

  try {
    // Calls api/notifications/get_notifications.php
    const res = await fetch(`api/notifications/get_notifications.php?user_id=${userId}`);
    const result = await res.json();
    
    const notifications = result.notifications || result.data || [];

    if (result.status === 200 && notifications.length > 0) {
      if (badge) badge.classList.remove('hidden');
      
      if (notifList) {
        notifList.innerHTML = notifications.map(n => {
          const targetUrl = getNotifTargetLink(n);
          const isUnread = n.is_read == 0;
          const notifId = n.notification_id || n.id;
          
          return `
            <a href="${targetUrl}" 
               onclick="event.preventDefault(); markNotificationAsRead(${notifId}, '${targetUrl}')" 
               class="block p-3 rounded-xl border transition cursor-pointer group ${isUnread ? 'bg-emerald-50/60 border-emerald-200' : 'bg-stone-50 border-stone-100 hover:bg-stone-100'}">
              
              <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-bold text-stone-900 group-hover:text-emerald-700 transition flex items-center gap-1.5">
                  ${isUnread ? '<span class="w-1.5 h-1.5 rounded-full bg-emerald-600 inline-block"></span>' : ''}
                  ${n.title || 'Notification'}
                </p>
                <span class="text-[9px] text-stone-400 font-mono">${n.created_at || 'Just now'}</span>
              </div>
              
              <p class="text-[11px] text-stone-600 mt-1 line-clamp-2">${n.message || ''}</p>
              
              <div class="mt-2 flex items-center gap-1 text-[10px] font-semibold text-emerald-700 opacity-0 group-hover:opacity-100 transition-opacity">
                <span>View Details</span>
                <span>→</span>
              </div>
            </a>
          `;
        }).join('');
      }
    } else if (notifList) {
      if (badge) badge.classList.add('hidden');
      notifList.innerHTML = `<p class="text-xs text-stone-400 text-center py-3">No new notifications</p>`;
    }
  } catch (err) {
    console.error('Notification Load Error:', err);
  }
}

document.addEventListener('DOMContentLoaded', loadNotifications);