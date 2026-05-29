// assets/login.js
// Login standalone. Admin/superadmin langsung ke admin.html, customer ke index.html. v12.

const loginForm = document.getElementById('loginForm');

if (loginForm) {
  loginForm.addEventListener('submit', login);
}

async function login(event) {
  event.preventDefault();

  const form = document.getElementById('loginForm') || event.target;

  const payload = {
    login: form.login?.value || form.username?.value || '',
    password: form.password?.value || ''
  };

  try {
    const res = await fetch('api/auth/login.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const result = await res.json();

    if (!res.ok) {
      alert(result.message || 'Login gagal.');
      return;
    }

    const roleName = result.data?.user?.role_name || '';
    const isAdmin = roleName === 'admin' || roleName === 'superadmin' || result.data?.user?.is_admin === true;
    const redirectUrl = result.data?.redirect_url || (isAdmin ? 'admin.html' : 'index.html');

    window.location.replace(redirectUrl);
  } catch (error) {
    alert('Login gagal. Periksa koneksi atau endpoint login.');
  }
}
