// assets/admin.js
// Dashboard admin: list transaksi, approve manual, update status.

let currentAdmin = null;
let transactionsCache = [];

window.addEventListener('DOMContentLoaded', async () => {
  const allowed = await guardAdminPage();
  if (!allowed) return;
  bindEvents();
  await loadTransactions();
});

function bindEvents() {
  const updateForm = document.getElementById('updateForm');
  if (updateForm) {
    updateForm.addEventListener('submit', updateTransaction);
  }

  const search = document.getElementById('search');
  if (search) {
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') loadTransactions();
    });
  }
}

async function guardAdminPage() {
  try {
    const res = await fetch('api/auth/me.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await res.json();

    const user = result.data?.user;

    if (!result.data?.authenticated || !user) {
      window.location.replace('login.html');
      return false;
    }

    if (!user.is_admin) {
      window.location.replace('index.html');
      return false;
    }

    currentAdmin = user;
    document.getElementById('adminName').textContent = user.username || '-';
    return true;
  } catch (error) {
    window.location.replace('login.html');
    return false;
  }
}

async function loadTransactions() {
  const tbody = document.getElementById('transactionsBody');
  tbody.innerHTML = '<tr><td colspan="7">Memuat...</td></tr>';

  const params = new URLSearchParams();
  const search = document.getElementById('search').value.trim();
  const status = document.getElementById('statusFilter').value;
  const paymentStatus = document.getElementById('paymentStatusFilter').value;
  const approval = document.getElementById('approvalFilter').value;

  if (search) params.set('search', search);
  if (status) params.set('status', status);
  if (paymentStatus) params.set('payment_status', paymentStatus);
  if (approval) params.set('approval', approval);

  try {
    const res = await fetch(`api/admin/transactions.php?${params.toString()}`, {
      credentials: 'same-origin'
    });
    const result = await res.json();

    if (!res.ok) {
      tbody.innerHTML = `<tr><td colspan="7">${escapeHtml(result.message || 'Gagal memuat transaksi.')}</td></tr>`;
      return;
    }

    transactionsCache = result.data?.transactions || [];

    if (!transactionsCache.length) {
      tbody.innerHTML = '<tr><td colspan="7">Tidak ada transaksi.</td></tr>';
      return;
    }

    tbody.innerHTML = transactionsCache.map(renderTransactionRow).join('');
  } catch (error) {
    tbody.innerHTML = '<tr><td colspan="7">Gagal memuat transaksi. Periksa koneksi atau endpoint admin.</td></tr>';
  }
}

function renderTransactionRow(item, index) {
  const needsApproval = item.payment_status === 'pending_confirmation' || item.confirmation_status === 'submitted';
  const customerName = item.full_name || item.username || '-';
  const proofLink = item.proof_file_path
    ? `<br><a href="${escapeAttribute(item.proof_file_path)}" target="_blank" rel="noopener">Lihat bukti</a>`
    : '';

  const approveButtons = needsApproval
    ? `
      <button class="btn" type="button" onclick="quickApprove(${index})">Approve</button>
      <button class="btn secondary" type="button" onclick="quickReject(${index})">Tolak</button>
    `
    : '';

  return `
    <tr>
      <td>
        <b>${escapeHtml(item.invoice_no || '-')}</b><br>
        <span class="muted">${escapeHtml(item.created_at || '')}</span>
      </td>
      <td>
        <b>${escapeHtml(customerName)}</b><br>
        <span class="muted">@${escapeHtml(item.username || '-')}</span><br>
        <span class="muted">${escapeHtml(item.email || '-')}</span><br>
        <span class="muted">${escapeHtml(item.phone || '-')}</span>
      </td>
      <td class="item-lines">${formatItems(item.items_summary)}</td>
      <td><b>Rp ${formatRupiah(item.total_amount || 0)}</b></td>
      <td>
        <span class="status-pill">${escapeHtml(item.status || '-')}</span><br>
        ${item.admin_note ? `<span class="muted">Note: ${escapeHtml(item.admin_note)}</span>` : ''}
      </td>
      <td>
        <span class="status-pill">${escapeHtml(item.payment_status || '-')}</span><br>
        <span class="muted">${escapeHtml(item.payment_method || '-')}</span><br>
        ${item.confirmation_status ? `<span class="muted">Bukti: ${escapeHtml(item.confirmation_status)}</span>` : ''}
        ${item.amount_paid ? `<br><span class="muted">Dibayar: Rp ${formatRupiah(item.amount_paid)}</span>` : ''}
        ${item.sender_account_name ? `<br><span class="muted">Pengirim: ${escapeHtml(item.sender_account_name)}</span>` : ''}
        ${proofLink}
      </td>
      <td>
        <div class="action-stack">
          ${approveButtons}
          <button class="btn secondary" type="button" onclick="openModal(${index})">Update</button>
        </div>
      </td>
    </tr>
  `;
}

function formatItems(itemsSummary) {
  if (!itemsSummary) return '-';

  return String(itemsSummary)
    .split(' || ')
    .map((line) => `<div>${escapeHtml(line)}</div>`)
    .join('');
}

function openModal(index) {
  const transaction = transactionsCache[index];
  if (!transaction) return;

  document.getElementById('transaction_id').value = transaction.id;
  document.getElementById('new_status').value = transaction.status || 'pending';
  document.getElementById('new_payment_status').value = transaction.payment_status || 'unpaid';
  document.getElementById('new_confirmation_status').value = '';
  document.getElementById('admin_note').value = transaction.admin_note || '';

  document.getElementById('modalBg').classList.add('show');
}

function closeModal() {
  document.getElementById('modalBg').classList.remove('show');
}

async function updateTransaction(event) {
  event.preventDefault();

  const payload = {
    action: 'update',
    transaction_id: document.getElementById('transaction_id').value,
    status: document.getElementById('new_status').value,
    payment_status: document.getElementById('new_payment_status').value,
    confirmation_status: document.getElementById('new_confirmation_status').value,
    admin_note: document.getElementById('admin_note').value.trim()
  };

  await submitTransactionUpdate(payload);
  closeModal();
}

async function quickApprove(index) {
  const transaction = transactionsCache[index];
  if (!transaction) return;

  const ok = confirm(`Approve pembayaran manual untuk invoice ${transaction.invoice_no}?`);
  if (!ok) return;

  await submitTransactionUpdate({
    action: 'approve_payment',
    transaction_id: transaction.id,
    status: 'processing',
    admin_note: 'Pembayaran manual disetujui admin.'
  });
}

async function quickReject(index) {
  const transaction = transactionsCache[index];
  if (!transaction) return;

  const reason = prompt(`Alasan menolak pembayaran invoice ${transaction.invoice_no}:`, 'Bukti pembayaran tidak valid.');
  if (reason === null) return;

  await submitTransactionUpdate({
    action: 'reject_payment',
    transaction_id: transaction.id,
    admin_note: reason.trim() || 'Bukti pembayaran tidak valid.'
  });
}

async function submitTransactionUpdate(payload) {
  try {
    const res = await fetch('api/admin/update_transaction.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const result = await res.json();

    if (!res.ok) {
      alert(result.message || 'Gagal update transaksi.');
      return;
    }

    alert(result.message || 'Transaksi berhasil diperbarui.');
    await loadTransactions();
  } catch (error) {
    alert('Gagal update transaksi. Periksa koneksi atau endpoint admin.');
  }
}

async function logout() {
  await fetch('api/auth/logout.php', {
    method: 'POST',
    credentials: 'same-origin'
  });

  window.location.replace('login.html');
}

function formatRupiah(value) {
  return Number(value || 0).toLocaleString('id-ID');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function escapeAttribute(value) {
  return escapeHtml(value).replaceAll('`', '&#096;');
}
