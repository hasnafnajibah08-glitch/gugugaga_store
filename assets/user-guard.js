// assets/user-guard.js
// Pasang di halaman user agar admin/superadmin tidak berada di halaman transaksi user.

async function preventAdminOnUserPage() {
  try {
    const res = await fetch('api/auth/me.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await res.json();
    const user = result.data?.user;

    if (user?.is_admin) {
      window.location.replace('admin.html');
    }
  } catch (error) {
    // Abaikan agar halaman publik tetap bisa dibuka oleh guest.
  }
}

window.addEventListener('DOMContentLoaded', preventAdminOnUserPage);
