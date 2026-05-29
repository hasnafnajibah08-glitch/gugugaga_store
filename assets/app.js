// assets/app.js
// Frontend user GUGUGAGA.STORE: game list, game terlaris, produk, keranjang, checkout, bukti pembayaran, review.
// v12: light theme, QRIS static fallback + dynamic payload, popular 8 teratas, logo home link.
// Tidak ada tombol/link admin di halaman user. Admin/superadmin otomatis diarahkan ke admin.html.

const API = {
  me: 'api/auth/me.php',
  login: 'api/auth/login.php',
  register: 'api/auth/register.php',
  logout: 'api/auth/logout.php',
  home: 'api/public/home.php',
  products: 'api/public/products.php',
  paymentMethods: 'api/public/payment_methods.php',
  checkout: 'api/user/checkout.php',
  transactions: 'api/user/transactions.php',
  uploadPayment: 'api/user/upload_payment_confirmation.php',
  reviews: 'api/user/reviews.php',
  qris: 'api/user/generate_qris.php',
  midtransConfig: 'api/public/midtrans_config.php',
  midtransSnapToken: 'api/user/midtrans_snap_token.php',
  midtransStatus: 'api/user/midtrans_status.php'
};

const GG_FALLBACK = window.GG_FALLBACK_DATA || { games: [], popular_games: [], products_by_game: {}, payment_methods: [] };

window.addEventListener('error', (event) => {
  const message = String(event?.message || '');
  if (message.includes('currentTarget') && message.includes('reset')) {
    event.preventDefault();
  }
});

let currentUser = null;
let games = [];
let popularGames = [];
let paymentMethods = [];
let selectedGame = null;
let selectedProduct = null;
let currentProducts = [];
let transactionsCache = [];
let midtransConfig = null;
let midtransSnapLoadPromise = null;
let cart = loadLocalCart();
let bannerIndex = 0;
let bannerTimer = null;

window.addEventListener('DOMContentLoaded', initApp);

async function initApp() {
  bindForms();
  await loadCurrentUser();

  if (currentUser?.is_admin) {
    window.location.replace('admin.html');
    return;
  }

  renderUserArea();
  await Promise.all([
    loadHomeData(),
    loadPaymentMethods(),
    loadReviews()
  ]);

  renderCart();

  if (currentUser) {
    await loadTransactions();
    await handleMidtransReturn();
  } else {
    renderGuestTransactions();
  }
}

function bindForms() {
  const searchGame = document.getElementById('searchGame');
  if (searchGame) {
    searchGame.addEventListener('input', () => renderGames(searchGame.value));
  }

  const loginForm = document.getElementById('loginForm');
  if (loginForm) {
    loginForm.addEventListener('submit', handleLogin);
  }

  const registerForm = document.getElementById('registerForm');
  if (registerForm) {
    registerForm.addEventListener('submit', handleRegister);
  }

  const checkoutForm = document.getElementById('checkoutForm');
  if (checkoutForm) {
    checkoutForm.addEventListener('submit', handleCheckout);
  }

  const paymentConfirmForm = document.getElementById('paymentConfirmForm');
  if (paymentConfirmForm) {
    paymentConfirmForm.addEventListener('submit', handlePaymentConfirmation);
  }

  const reviewForm = document.getElementById('reviewForm');
  if (reviewForm) {
    reviewForm.addEventListener('submit', handleReviewSubmit);
  }

  const quantity = document.getElementById('quantity');
  if (quantity) {
    quantity.addEventListener('input', syncQuantityBounds);
  }

  const proofFile = document.getElementById('proof_file');
  if (proofFile) {
    proofFile.addEventListener('change', previewProofFile);
  }
}

async function loadCurrentUser() {
  try {
    const res = await fetch(API.me, { credentials: 'same-origin', cache: 'no-store' });
    const result = await res.json();
    currentUser = result.data?.authenticated ? result.data.user : null;
  } catch (error) {
    currentUser = null;
  }
}

async function loadHomeData() {
  setLoading('gameList', 'Memuat game...');
  setLoading('popularGrid', 'Memuat game populer...');

  try {
    const res = await fetch(API.home, { credentials: 'same-origin', cache: 'no-store' });
    const result = await res.json();

    if (!res.ok) {
      throw new Error(result.message || 'Gagal memuat homepage.');
    }

    const data = result.data || {};
    games = Array.isArray(data.games) && data.games.length ? data.games : fallbackGames();
    popularGames = buildPopularGames(Array.isArray(data.popular_games) ? data.popular_games : [], games, 4);

    applySiteSettings(data.settings || {});
    renderBanners(data.banners || []);
    renderSocialLinks(data.social_links || []);
    renderPopularGames();
    renderGames();
  } catch (error) {
    // Jangan biarkan halaman kosong. Kalau API/database belum siap, tampilkan data cadangan dari schema awal.
    games = fallbackGames();
    popularGames = fallbackPopularGames();
    renderBanners([]);
    renderSocialLinks([]);
    renderPopularGames();
    renderGames();
    notify('Katalog ditampilkan dari data cadangan. Cek api/public/home.php kalau data database belum muncul.', 'warning');
  }
}

function applySiteSettings(settings) {
  const siteName = settings.site_name || settings.app_name || 'GUGUGAGA.STORE';
  const description = settings.footer_description || settings.site_description || 'Top up game murah, cepat, aman, dan terpercaya di Indonesia.';
  const logo = settings.logo_url || settings.site_logo || 'LOGO.GG.png';

  setText('siteName', siteName);
  setText('footerSiteName', siteName);
  setText('footerDescription', description);

  if (logo) {
    setSrc('siteLogo', logo);
    setSrc('footerLogo', logo);
  }
}

function renderBanners(banners) {
  const slides = document.getElementById('slides');
  if (!slides) return;

  const items = normalizeBanners(banners);

  bannerIndex = 0;
  slides.style.transform = 'translateX(0%)';
  slides.innerHTML = items.map((banner, index) => {
    const image = assetUrl(banner.image_url || 'slide1.png', 'slide1.png');
    const target = banner.target_url || '#gameSection';
    return `
      <a class="slide ${index === 0 ? 'is-active' : ''}" href="${escapeAttribute(target)}" aria-label="${escapeAttribute(banner.title || 'Banner GUGUGAGA.STORE')}">
        <img src="${escapeAttribute(image)}" alt="${escapeAttribute(banner.title || 'Banner')}" onerror="this.onerror=null;this.src='LOGO.GG.png'">
        <div class="slide-copy">
          <h3>${escapeHtml(banner.title || 'GUGUGAGA.STORE')}</h3>
          <p>${escapeHtml(banner.subtitle || 'Top up cepat, aman, dan terpercaya.')}</p>
        </div>
      </a>
    `;
  }).join('');

  renderSliderDots(items.length);
  startBannerSlider(items.length);
}

function normalizeBanners(banners) {
  const fromApi = Array.isArray(banners)
    ? banners.filter((banner) => banner && (banner.image_url || banner.title || banner.subtitle))
    : [];

if (false && fromApi.length) {
  return fromApi;
}

 return [
  {
    title: 'Top Up Game Murah & Cepat',
    subtitle: 'Proses instan, aman, dan terpercaya.',
    image_url: 'assets/banner/slide1.png',
    target_url: '#gameSection'
  },
  {
    title: 'Promo GUGUGAGA.STORE',
    subtitle: 'Banyak pilihan game populer.',
    image_url: 'assets/banner/slide2.png',
    target_url: '#popularGrid'
  },
  {
    title: 'Top Up 24 Jam',
    subtitle: 'Pembayaran mudah dengan QRIS dan transfer bank.',
    image_url: 'assets/banner/slide3.png',
    target_url: '#gameSection'
  }
];
}

function renderSliderDots(count) {
  const slides = document.getElementById('slides');
  const slider = slides?.closest('.slider');
  if (!slider) return;

  let dots = slider.querySelector('.slider-dots');
  if (!dots) {
    dots = document.createElement('div');
    dots.className = 'slider-dots';
    slider.appendChild(dots);
  }

  if (count <= 1) {
    dots.innerHTML = '';
    return;
  }

  dots.innerHTML = Array.from({ length: count }, (_, index) => `
    <button class="slider-dot ${index === 0 ? 'active' : ''}" type="button" aria-label="Slide ${index + 1}" onclick="goToBannerSlide(${index})"></button>
  `).join('');
}

function startBannerSlider(count) {
  if (bannerTimer) {
    clearInterval(bannerTimer);
    bannerTimer = null;
  }

  if (count <= 1 || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    updateBannerSlider();
    return;
  }

  bannerTimer = setInterval(() => {
    bannerIndex = (bannerIndex + 1) % count;
    updateBannerSlider();
  }, 4200);
}

function goToBannerSlide(index) {
  const slides = document.getElementById('slides');
  const count = slides?.children?.length || 0;
  if (!count) return;

  bannerIndex = Math.max(0, Math.min(Number(index) || 0, count - 1));
  updateBannerSlider();
  startBannerSlider(count);
}

function updateBannerSlider() {
  const slides = document.getElementById('slides');
  if (!slides) return;

  const count = slides.children.length || 1;
  bannerIndex = ((bannerIndex % count) + count) % count;
  slides.style.transform = `translateX(-${bannerIndex * 100}%)`;

  [...slides.children].forEach((slide, index) => {
    slide.classList.toggle('is-active', index === bannerIndex);
  });

  const slider = slides.closest('.slider');
  slider?.querySelectorAll('.slider-dot').forEach((dot, index) => {
    dot.classList.toggle('active', index === bannerIndex);
  });
}

function renderSocialLinks(links) {
  const wrapper = document.getElementById('socialLinks');
  if (!wrapper) return;

  if (!links.length) {
    wrapper.innerHTML = `
      <a href="https://wa.me/6281229566695" target="_blank" rel="noopener">WhatsApp</a>
      <a href="https://t.me/GUGUGAGASTORE" target="_blank" rel="noopener">Telegram</a>
      <a href="mailto:gugugagastore@gmail.com">Email</a>
    `;
    return;
  }

  wrapper.innerHTML = links.map((link) => `
    <a href="${escapeAttribute(link.url)}" target="_blank" rel="noopener">
      ${link.icon_url ? `<img src="${escapeAttribute(assetUrl(link.icon_url))}" alt="" onerror="this.style.display='none'">` : ''}
      ${escapeHtml(link.platform)}
    </a>
  `).join('');
}

function renderPopularGames() {
  const wrapper = document.getElementById('popularGrid');
  if (!wrapper) return;

  const list = buildPopularList(4);

  if (!list.length) {
    wrapper.innerHTML = '<p class="muted">Belum ada game populer.</p>';
    return;
  }

 wrapper.innerHTML = list.map((game) => `
  <button class="popular-card horizontal" type="button" onclick="selectGame(${Number(game.id)})">
    
    <img 
      src="${escapeAttribute(assetUrl(game.image_url || 'LOGO.GG.png', 'LOGO.GG.png'))}" 
      alt="${escapeAttribute(game.name)}"
      onerror="this.onerror=null;this.src='LOGO.GG.png'"
    >

    <div class="popular-info">
      <b>${escapeHtml(game.name)}</b>
      <small>
        ${escapeHtml(game.publisher || 'Top Up')}
      </small>
    </div>

  </button>
`).join('');
}

function buildPopularList(limit = 4) {
  const merged = [];
  const seen = new Set();

  [...(popularGames || []), ...(games || [])].forEach((game) => {
    const id = Number(game?.id || 0);
    if (!id || seen.has(id)) return;
    seen.add(id);
    merged.push(game);
  });

  return merged.slice(0, limit);
}

function renderGames(keyword = '') {
  const wrapper = document.getElementById('gameList');
  if (!wrapper) return;

  const term = String(keyword || '').trim().toLowerCase();
  const filtered = games.filter((game) => {
    const haystack = `${game.name || ''} ${game.publisher || ''} ${game.slug || ''}`.toLowerCase();
    return !term || haystack.includes(term);
  });

  if (!filtered.length) {
    wrapper.innerHTML = '<p class="muted">Game tidak ditemukan.</p>';
    return;
  }

  wrapper.innerHTML = filtered.map((game) => `
    <button class="game-card" type="button" onclick="selectGame(${Number(game.id)})">
      <img class="game-thumb" src="${escapeAttribute(assetUrl(game.image_url || 'LOGO.GG.png', 'LOGO.GG.png'))}" alt="${escapeAttribute(game.name)}" onerror="this.onerror=null;this.src='LOGO.GG.png'">
      <div class="game-info">
        <b>${escapeHtml(game.name)}</b>
        <small>${escapeHtml(game.publisher || 'Top Up')}</small>
      </div>
    </button>
  `).join('');
}

async function selectGame(gameId) {
  const game = games.find((item) => Number(item.id) === Number(gameId)) || popularGames.find((item) => Number(item.id) === Number(gameId));
  if (!game) return;

  selectedGame = game;
  selectedProduct = null;

  setText('selectedGameName', game.name || 'Game');
  setText('idLabel', game.id_label || 'ID');
  setAttribute('game_user_identifier', 'placeholder', game.id_placeholder || 'Masukkan ID');
  setText('serverLabel', game.server_label || 'Server');

  const serverInput = document.getElementById('serverInput');
  if (serverInput) {
    toggleHidden(serverInput, Number(game.requires_server) !== 1);
  }

  const topupPanel = document.getElementById('topupPanel');
  if (topupPanel) {
    toggleHidden(topupPanel, false);
  }

  const qtyPanel = document.getElementById('qtyPanel');
  if (qtyPanel) {
    toggleHidden(qtyPanel, true);
  }

  const addCartBtn = document.getElementById('addCartBtn');
  if (addCartBtn) {
    addCartBtn.disabled = true;
  }

  setLoading('productList', 'Memuat produk...');

  try {
    const res = await fetch(`${API.products}?game_id=${encodeURIComponent(game.id)}`, { credentials: 'same-origin', cache: 'no-store' });
    const result = await res.json();

    if (!res.ok) {
      throw new Error(result.message || 'Gagal memuat produk.');
    }

    const products = result.data?.products || [];
    renderProducts(products.length ? products : fallbackProducts(game.id));
    scrollToSection('topupPanel');
  } catch (error) {
    const products = fallbackProducts(game.id);
    if (products.length) {
      renderProducts(products);
      scrollToSection('topupPanel');
      notify('Produk ditampilkan dari data cadangan. Cek api/public/products.php kalau data database belum muncul.', 'warning');
      return;
    }
    setLoading('productList', 'Produk belum bisa dimuat.');
  }
}

function renderProducts(products) {
  const wrapper = document.getElementById('productList');
  currentProducts = (products || []).map(safeProduct);
  if (!wrapper) return;

  if (!currentProducts.length) {
    wrapper.innerHTML = '<p class="muted">Produk untuk game ini belum tersedia.</p>';
    return;
  }

  wrapper.innerHTML = currentProducts.map((product) => `
    <button class="product-card" id="product_${Number(product.id)}" type="button" onclick="selectProductById(${Number(product.id)})">
      ${product.icon_url ? `<img src="${escapeAttribute(assetUrl(product.icon_url))}" alt="" onerror="this.style.display='none'">` : ''}
      <b>${escapeHtml(product.name)}</b>
      <span>Rp ${formatRupiah(product.unit_price)}</span>
      <small>Min ${Number(product.min_qty || 1)} / Max ${Number(product.max_qty || 5)}</small>
    </button>
  `).join('');
}

function safeProduct(product) {
  return {
    id: Number(product.id),
    game_id: Number(product.game_id),
    name: String(product.name || ''),
    product_type: String(product.product_type || ''),
    unit_price: Number(product.unit_price || 0),
    icon_url: String(product.icon_url || ''),
    min_qty: Number(product.min_qty || 1),
    max_qty: Number(product.max_qty || 5)
  };
}

function selectProductById(productId) {
  const product = currentProducts.find((item) => Number(item.id) === Number(productId));
  if (!product) return;
  selectProduct(product);
}

function selectProduct(product) {
  selectedProduct = product;

  document.querySelectorAll('.product-card').forEach((item) => item.classList.remove('active'));
  const active = document.getElementById(`product_${Number(product.id)}`);
  if (active) active.classList.add('active');

  const quantity = document.getElementById('quantity');
  if (quantity) {
    quantity.min = product.min_qty || 1;
    quantity.max = product.max_qty || 5;
    quantity.value = product.min_qty || 1;
  }

  const qtyPanel = document.getElementById('qtyPanel');
  if (qtyPanel) {
    toggleHidden(qtyPanel, false);
  }

  const addCartBtn = document.getElementById('addCartBtn');
  if (addCartBtn) {
    addCartBtn.disabled = false;
  }
}

function syncQuantityBounds() {
  const quantity = document.getElementById('quantity');
  if (!quantity || !selectedProduct) return;

  const min = Number(selectedProduct.min_qty || 1);
  const max = Number(selectedProduct.max_qty || 5);
  let value = Number(quantity.value || min);

  if (value < min) value = min;
  if (value > max) value = max;

  quantity.value = value;
}

function updateQuantity(delta) {
  const quantity = document.getElementById('quantity');
  if (!quantity || !selectedProduct) return;

  quantity.value = Number(quantity.value || selectedProduct.min_qty || 1) + Number(delta || 0);
  syncQuantityBounds();
}

function addToCart() {
  if (!selectedGame || !selectedProduct) {
    notify('Pilih game dan item terlebih dahulu.', 'warning');
    return;
  }

  const identifierInput = document.getElementById('game_user_identifier');
  const serverInput = document.getElementById('game_server');
  const quantityInput = document.getElementById('quantity');

  const identifier = identifierInput?.value.trim() || '';
  const server = serverInput?.value.trim() || '';
  const quantity = Number(quantityInput?.value || selectedProduct.min_qty || 1);

  if (!identifier) {
    notify(`${selectedGame.id_label || 'ID'} wajib diisi.`, 'warning');
    identifierInput?.focus();
    return;
  }

  if (Number(selectedGame.requires_server) === 1 && !server) {
    notify(`${selectedGame.server_label || 'Server'} wajib diisi.`, 'warning');
    serverInput?.focus();
    return;
  }

  const item = {
    local_id: `${Date.now()}_${Math.random().toString(16).slice(2)}`,
    game_id: Number(selectedGame.id),
    game_name: selectedGame.name,
    product_id: Number(selectedProduct.id),
    product_name: selectedProduct.name,
    game_user_identifier: identifier,
    game_server: server,
    quantity,
    unit_price: Number(selectedProduct.unit_price || 0),
    subtotal: Number(selectedProduct.unit_price || 0) * quantity
  };

  cart.push(item);
  saveLocalCart();
  renderCart();
  notify('Item berhasil masuk keranjang.', 'success');
  scrollToSection('cartSection');
}

function renderCart() {
  const wrapper = document.getElementById('cartList');
  const totalEl = document.getElementById('cartTotal');
  const checkoutBtn = document.getElementById('checkoutBtn');

  const total = cart.reduce((sum, item) => sum + Number(item.subtotal || 0), 0);
  if (totalEl) totalEl.textContent = `Rp${formatRupiah(total)}`;
  if (checkoutBtn) checkoutBtn.disabled = cart.length < 1;

  if (!wrapper) return;

  if (!cart.length) {
    wrapper.innerHTML = '<p class="muted">Keranjang masih kosong.</p>';
    return;
  }

  wrapper.innerHTML = cart.map((item, index) => `
    <div class="cart-item">
      <div>
        <b>${escapeHtml(item.game_name)} - ${escapeHtml(item.product_name)}</b>
        <p class="muted">
          ${escapeHtml(item.game_user_identifier)}${item.game_server ? ` / ${escapeHtml(item.game_server)}` : ''}<br>
          ${Number(item.quantity)} x Rp ${formatRupiah(item.unit_price)}
        </p>
      </div>
      <div>
        <b>Rp ${formatRupiah(item.subtotal)}</b><br>
        <button class="btn secondary" type="button" onclick="removeCartItem(${index})">Hapus</button>
      </div>
    </div>
  `).join('');
}

function removeCartItem(index) {
  cart.splice(index, 1);
  saveLocalCart();
  renderCart();
}

async function loadPaymentMethods() {
  try {
    const res = await fetch(API.paymentMethods, { credentials: 'same-origin', cache: 'no-store' });
    const result = await res.json();
    if (!res.ok) throw new Error(result.message || 'Gagal memuat pembayaran.');

    const methods = result.data?.payment_methods || [];
    paymentMethods = methods.length ? methods : fallbackPaymentMethods();
    fillPaymentSelects();
  } catch (error) {
    paymentMethods = fallbackPaymentMethods();
    fillPaymentSelects();
  }
}

function fillPaymentSelects() {
  const checkoutSelect = document.getElementById('payment_method_id');
  const confirmSelect = document.getElementById('confirm_payment_method_id');

  const options = paymentMethods.length
    ? paymentMethods.map((method) => `<option value="${Number(method.id)}">${escapeHtml(method.name)}</option>`).join('')
    : '<option value="">Metode pembayaran belum tersedia</option>';

  if (checkoutSelect) checkoutSelect.innerHTML = options;
  if (confirmSelect) confirmSelect.innerHTML = options;

  changePayment();
  changeConfirmPaymentMethod();
}

function selectedCheckoutPaymentMethod() {
  const select = document.getElementById('payment_method_id');
  const id = Number(select?.value || 0);
  return paymentMethods.find((method) => Number(method.id) === id) || null;
}

function selectedConfirmPaymentMethod() {
  const select = document.getElementById('confirm_payment_method_id');
  const id = Number(select?.value || 0);
  return paymentMethods.find((method) => Number(method.id) === id) || null;
}

function changePayment() {
  const info = document.getElementById('paymentInfo');
  if (!info) return;

  const method = selectedCheckoutPaymentMethod();
  if (!method) {
    info.innerHTML = '';
    toggleHidden(info, true);
    return;
  }

  info.innerHTML = renderPaymentMethodInfo(method);
  toggleHidden(info, false);
}

function changeConfirmPaymentMethod() {
  const method = selectedConfirmPaymentMethod();
  const bankFields = document.getElementById('bankConfirmFields');
  const bankSelect = document.getElementById('bank_account_id');

  if (!bankFields || !bankSelect) return;

  const isBank = method?.method_type === 'bank';
  toggleHidden(bankFields, !isBank);

  const accounts = method?.bank_accounts || [];
  bankSelect.innerHTML = accounts.length
    ? accounts.map((account) => `<option value="${Number(account.id)}">${escapeHtml(account.bank_name)} - ${escapeHtml(account.account_number)} a.n ${escapeHtml(account.account_name)}</option>`).join('')
    : '<option value="">Tidak ada rekening</option>';
}

function renderPaymentMethodInfo(method) {
  const banks = method.bank_accounts || [];
  const bankHtml = banks.length ? `
    <div class="bank-list">
      ${banks.map((account) => `
        <div class="bank-account">
          <b>${escapeHtml(account.bank_name)}</b><br>
          <span>${escapeHtml(account.account_number)}</span><br>
          <small>a.n ${escapeHtml(account.account_name)}</small>
        </div>
      `).join('')}
    </div>
  ` : '';

  const qrisHint = isQrisMethod(method) ? `
    <div class="qris-card">
      <b>QR Code QRIS akan muncul setelah invoice dibuat.</b>
      <span class="qris-note">Sistem membuat QRIS sesuai nomor invoice dan nominal checkout, lalu user bisa scan QR tersebut dari layar pembayaran.</span>
    </div>
  ` : '';

  const midtransHint = isMidtransMethod(method) ? `
    <div class="qris-card">
      <b>Pembayaran otomatis via Midtrans Snap.</b>
      <span class="qris-note">Setelah invoice dibuat, popup Snap akan terbuka. User memilih QRIS, e-wallet, VA, atau metode lain yang aktif di dashboard Midtrans. Status pembayaran akan diperbarui otomatis lewat webhook.</span>
    </div>
  ` : '';

  return `
    <div class="payment-box">
      ${method.logo_url ? `<img src="${escapeAttribute(assetUrl(method.logo_url))}" alt="${escapeAttribute(method.name)}" onerror="this.style.display='none'">` : ''}
      <h3>${escapeHtml(method.name)}</h3>
      ${method.instructions ? `<p>${escapeHtml(method.instructions)}</p>` : ''}
      ${qrisHint}
      ${midtransHint}
      ${bankHtml}
    </div>
  `;
}

function showCheckoutModal() {
  if (!cart.length) {
    notify('Keranjang masih kosong.', 'warning');
    return;
  }

  if (!currentUser) {
    notify('Login dulu sebelum checkout.', 'warning');
    showLoginModal();
    return;
  }

  const summary = document.getElementById('checkoutSummary');
  if (summary) {
    const total = cart.reduce((sum, item) => sum + Number(item.subtotal || 0), 0);
    summary.innerHTML = `
      ${cart.map((item) => `
        <div class="checkout-line">
          <span>${escapeHtml(item.game_name)} - ${escapeHtml(item.product_name)} x${Number(item.quantity)}</span>
          <b>Rp ${formatRupiah(item.subtotal)}</b>
        </div>
      `).join('')}
      <hr>
      <div class="checkout-line"><b>Total</b><b>Rp ${formatRupiah(total)}</b></div>
    `;
  }

  showOnlyModal('checkoutModal');
  changePayment();
}

async function handleCheckout(event) {
  event?.preventDefault?.();

  const form = getSubmitForm(event, 'checkoutForm');

  if (!currentUser) {
    showLoginModal();
    return;
  }

  const paymentMethodId = Number(document.getElementById('payment_method_id')?.value || 0);
  if (!paymentMethodId) {
    notify('Metode pembayaran wajib dipilih.', 'warning');
    return;
  }

  const selectedMethod = selectedCheckoutPaymentMethod();

  const payload = {
    payment_method_id: paymentMethodId,
    customer_note: form?.customer_note?.value?.trim?.() || document.getElementById('customer_note')?.value.trim() || '',
    items: cart.map((item) => ({
      product_id: item.product_id,
      game_user_identifier: item.game_user_identifier,
      game_server: item.game_server,
      quantity: item.quantity
    }))
  };

  try {
    const res = await fetch(API.checkout, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (!res.ok) {
      notify(result.message || 'Gagal membuat invoice.', 'error');
      return;
    }

    const transaction = result.data?.transaction || {};

    cart = [];
    saveLocalCart();
    renderCart();
    closeModal();
    notify(result.message || 'Invoice berhasil dibuat.', 'success');

    await loadTransactions();

    const midtrans = result.data?.midtrans || null;
    if ((isMidtransMethod(selectedMethod) || isMidtransTransaction(transaction)) && transaction.invoice_no) {
      await startMidtransPayment(transaction.invoice_no, midtrans?.snap_token || '', midtrans?.redirect_url || '');
      return;
    }

    if (isQrisMethod(selectedMethod) && transaction.invoice_no) {
      const qrisFallbackTransaction = {
        ...transaction,
        qris_image_url: transaction.qris_image_url || transaction.payment_method?.qris_image_url || selectedMethod?.qris_image_url || '',
        payment_method_name: selectedMethod?.name || transaction.payment_method?.name || transaction.payment_method || 'QRIS'
      };
      await showQrisModalByInvoice(transaction.invoice_no, qrisFallbackTransaction);
    } else {
      scrollToSection('transactionSection');
    }
  } catch (error) {
    notify('Gagal membuat invoice. Periksa koneksi.', 'error');
  }
}

async function loadTransactions() {
  const wrapper = document.getElementById('transactionList');
  if (!wrapper) return;

  if (!currentUser) {
    renderGuestTransactions();
    return;
  }

  wrapper.innerHTML = '<p class="muted">Memuat transaksi...</p>';

  try {
    const res = await fetch(API.transactions, { credentials: 'same-origin' });
    const result = await res.json();

    if (!res.ok) {
      throw new Error(result.message || 'Gagal memuat transaksi.');
    }

    const transactions = result.data?.transactions || [];
    renderTransactions(transactions);
  } catch (error) {
    wrapper.innerHTML = '<p class="muted">Riwayat transaksi belum bisa dimuat.</p>';
  }
}

function renderGuestTransactions() {
  const wrapper = document.getElementById('transactionList');
  if (!wrapper) return;
  wrapper.innerHTML = '<p class="muted">Login untuk melihat riwayat transaksi dan upload bukti pembayaran.</p>';
}

function renderTransactions(transactions) {
  const wrapper = document.getElementById('transactionList');
  transactionsCache = transactions || [];
  if (!wrapper) return;

  if (!transactionsCache.length) {
    wrapper.innerHTML = '<p class="muted">Belum ada transaksi.</p>';
    return;
  }

  wrapper.innerHTML = transactionsCache.map((trx, index) => {
    const isMidtrans = isMidtransTransaction(trx);
    const canUpload = !isMidtrans && ['unpaid', 'rejected'].includes(trx.payment_status);
    const canPayMidtrans = isMidtrans
      && ['unpaid', 'rejected'].includes(String(trx.payment_status || '').toLowerCase())
      && !['expired', 'cancelled', 'failed', 'refunded'].includes(String(trx.status || '').toLowerCase());
    const canShowQris = isQrisTransaction(trx)
      && ['unpaid', 'rejected', 'pending_confirmation'].includes(String(trx.payment_status || '').toLowerCase());
    const proof = trx.proof_file_path ? `<a href="${escapeAttribute(trx.proof_file_path)}" target="_blank" rel="noopener">Lihat bukti</a>` : '';
    const qrisButton = canShowQris ? `<br><button class="btn secondary" type="button" onclick="showQrisModalByTransactionIndex(${index})">Lihat QRIS</button>` : '';
    const midtransButton = canPayMidtrans ? `<br><button class="btn" type="button" onclick="payMidtransByTransactionIndex(${index})">Bayar via Midtrans</button>` : '';

    return `
      <div class="transaction-card">
        <div>
          <b>${escapeHtml(trx.invoice_no)}</b>
          <p class="muted">${formatItemsSummary(trx.items_summary)}</p>
          <p class="muted">${escapeHtml(trx.created_at || '')}</p>
        </div>
        <div>
          <b>Rp ${formatRupiah(trx.total_amount)}</b><br>
          <span class="status-pill">${escapeHtml(trx.status)}</span>
          <span class="status-pill">${escapeHtml(trx.payment_status)}</span><br>
          <small>${escapeHtml(trx.payment_method || '-')}</small><br>
          ${trx.confirmation_status ? `<small>Bukti: ${escapeHtml(trx.confirmation_status)}</small><br>` : ''}
          ${proof}
          ${qrisButton}
          ${midtransButton}
          ${canUpload ? `<br><button class="btn" type="button" onclick="showPaymentConfirmModalByIndex(${index})">Upload Bukti</button>` : ''}
        </div>
      </div>
    `;
  }).join('');
}

function safeTransaction(trx) {
  return {
    invoice_no: String(trx.invoice_no || ''),
    total_amount: Number(trx.total_amount || 0),
    payment_method: String(trx.payment_method || ''),
    payment_method_code: String(trx.payment_method_code || ''),
    payment_status: String(trx.payment_status || ''),
    method_type: String(trx.method_type || ''),
    qris_image_url: String(trx.qris_image_url || ''),
    midtrans_snap_token: String(trx.midtrans_snap_token || ''),
    midtrans_redirect_url: String(trx.midtrans_redirect_url || ''),
    midtrans_transaction_status: String(trx.midtrans_transaction_status || '')
  };
}

function showQrisModalByTransactionIndex(index) {
  const trx = transactionsCache[index];
  if (!trx) return;
  showQrisModalByInvoice(trx.invoice_no, trx);
}

async function showQrisModalByInvoice(invoiceNo, transaction = {}) {
  if (!currentUser) {
    showLoginModal();
    return;
  }

  if (!invoiceNo) {
    notify('Nomor invoice tidak valid untuk QRIS.', 'error');
    return;
  }

  showOnlyModal('qrisModal');
  const content = document.getElementById('qrisContent');
  if (content) {
    content.innerHTML = '<p class="muted">Membuat QRIS...</p>';
  }

  try {
    const res = await fetch(`${API.qris}?invoice_no=${encodeURIComponent(invoiceNo)}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await res.json();

    if (!res.ok) {
      throw new Error(result.message || 'Gagal membuat QRIS.');
    }

    renderQrisModal(result.data?.qris || {}, transaction);
  } catch (error) {
    const fallbackQris = buildStaticQrisFallback(invoiceNo, transaction, error?.message || 'QRIS dinamis belum aktif.');
    if (fallbackQris) {
      renderQrisModal(fallbackQris, transaction);
      return;
    }

    if (content) {
      content.innerHTML = `
        <div class="qris-card">
          <b>QRIS belum bisa dibuat.</b>
          <span class="qris-note">${escapeHtml(error.message || 'Periksa konfigurasi QRIS di database.')}</span>
          <span class="qris-note">Solusi cepat: upload gambar QRIS asli sebagai <b>qris.png</b> di folder utama website, atau isi <b>payment_methods.qris_image_url</b> dengan path gambar QRIS tersebut.</span>
        </div>
      `;
    }
  }
}

function buildStaticQrisFallback(invoiceNo, transaction = {}, reason = '') {
  const method = findPaymentMethodForTransaction(transaction);
  const imageUrl = transaction.qris_image_url
    || transaction.payment_method?.qris_image_url
    || method?.qris_image_url
    || '';

  if (!imageUrl) {
    return null;
  }

  const paymentName = transaction.payment_method_name
    || transaction.payment_method?.name
    || transaction.payment_method
    || method?.name
    || 'QRIS';

  return {
    invoice_no: invoiceNo || transaction.invoice_no || '-',
    total_amount: Number(transaction.total_amount || 0),
    payment_method: paymentName,
    payload: null,
    image_url: imageUrl,
    is_dynamic: false,
    mode: 'static_image_fallback',
    warnings: [`QRIS dinamis belum aktif. ${reason} Sistem menampilkan QRIS statis dari database.`]
  };
}

function renderQrisModal(qris, fallbackTransaction = {}) {
  const content = document.getElementById('qrisContent');
  if (!content) return;

  const invoiceNo = qris.invoice_no || fallbackTransaction.invoice_no || '-';
  const amount = Number(qris.total_amount || fallbackTransaction.total_amount || 0);
  const payload = qris.payload || '';
  const staticImage = qris.image_url || fallbackTransaction.qris_image_url || fallbackTransaction.payment_method?.qris_image_url || '';
  const imageUrl = payload ? generatedQrImageUrl(payload, 320) : assetUrl(staticImage, '');
  const warnings = Array.isArray(qris.warnings) ? qris.warnings.filter(Boolean) : [];
  if (qris.warning) warnings.push(String(qris.warning));
  if (qris.config_notice) warnings.push(String(qris.config_notice));

  const modeText = qris.is_dynamic
    ? 'QRIS dinamis dibuat sesuai nominal invoice.'
    : 'QRIS statis/manual ditampilkan dari data metode pembayaran.';

  const imageHtml = imageUrl ? `
    <div class="qris-image-wrap">
      <img src="${escapeAttribute(imageUrl)}" alt="QRIS ${escapeAttribute(invoiceNo)}" onerror="this.closest('.qris-image-wrap').innerHTML='<p class=&quot;muted&quot;>Gambar QRIS gagal dimuat. Pastikan file QRIS ada dan path database benar.</p>'">
    </div>
  ` : '<p class="muted">Gambar QRIS belum tersedia.</p>';

  content.innerHTML = `
    <div class="qris-card">
      <div>
        <b>Invoice ${escapeHtml(invoiceNo)}</b><br>
        <span>Total: <b>Rp ${formatRupiah(amount)}</b></span>
      </div>
      ${imageHtml}
      <span class="qris-note">${escapeHtml(modeText)} Scan QR di atas, lalu upload bukti pembayaran dari riwayat transaksi.</span>
      ${warnings.length ? `<span class="qris-note warning-note">Catatan: ${escapeHtml(warnings.join(' '))}</span>` : ''}
      ${payload ? `<div class="qris-payload-box" title="Payload QRIS">${escapeHtml(payload)}</div>` : ''}
    </div>
  `;
}

function generatedQrImageUrl(payload, size = 320) {
  const safeSize = Math.max(180, Math.min(Number(size) || 320, 640));
  return `https://api.qrserver.com/v1/create-qr-code/?size=${safeSize}x${safeSize}&margin=12&data=${encodeURIComponent(payload)}`;
}

function isQrisMethod(method) {
  const type = String(method?.method_type || '').toLowerCase();
  const code = String(method?.code || '').toLowerCase();
  const name = String(method?.name || method?.payment_method || '').toLowerCase();
  return type === 'qris' || code.includes('qris') || name.includes('qris');
}

function isQrisTransaction(transaction) {
  const type = String(transaction?.method_type || '').toLowerCase();
  const code = String(transaction?.payment_method_code || transaction?.code || '').toLowerCase();
  const name = String(transaction?.payment_method || transaction?.name || '').toLowerCase();
  return type === 'qris' || code.includes('qris') || name.includes('qris');
}

function findPaymentMethodForTransaction(transaction = {}) {
  const nested = transaction.payment_method;
  if (nested && typeof nested === 'object') return nested;

  const methodId = Number(transaction.payment_method_id || 0);
  const code = String(transaction.payment_method_code || transaction.code || '').toLowerCase();
  const name = String(transaction.payment_method || '').toLowerCase();

  return paymentMethods.find((method) => {
    if (methodId && Number(method.id) === methodId) return true;
    if (code && String(method.code || '').toLowerCase() === code) return true;
    if (name && String(method.name || '').toLowerCase() === name) return true;
    return false;
  }) || paymentMethods.find(isQrisMethod) || null;
}


function isMidtransMethod(method) {
  const type = String(method?.method_type || '').toLowerCase();
  const code = String(method?.code || method?.payment_method_code || '').toLowerCase();
  const name = String(method?.name || method?.payment_method || '').toLowerCase();
  return [type, code, name].some((value) => value.includes('midtrans') || value.includes('snap'));
}

function isMidtransTransaction(transaction) {
  const type = String(transaction?.method_type || '').toLowerCase();
  const code = String(transaction?.payment_method_code || transaction?.code || '').toLowerCase();
  const name = String(transaction?.payment_method || transaction?.name || '').toLowerCase();
  return [type, code, name].some((value) => value.includes('midtrans') || value.includes('snap'));
}

async function loadMidtransConfig() {
  if (midtransConfig) return midtransConfig;

  const res = await fetch(API.midtransConfig, { credentials: 'same-origin', cache: 'no-store' });
  const result = await res.json();

  if (!res.ok) {
    throw new Error(result.message || 'Gagal memuat konfigurasi Midtrans.');
  }

  midtransConfig = result.data || {};
  return midtransConfig;
}

async function ensureMidtransSnapReady() {
  if (window.snap && typeof window.snap.pay === 'function') {
    return true;
  }

  if (midtransSnapLoadPromise) {
    return midtransSnapLoadPromise;
  }

  midtransSnapLoadPromise = (async () => {
    const config = await loadMidtransConfig();
    if (!config.enabled || !config.client_key) {
      throw new Error('Client Key Midtrans belum diisi.');
    }

    await new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-midtrans-snap="true"]');
      if (existing) {
        if (existing.getAttribute('data-loaded') === 'true') {
          resolve();
          return;
        }
        existing.addEventListener('load', resolve, { once: true });
        existing.addEventListener('error', () => reject(new Error('Snap.js gagal dimuat.')), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.src = config.snap_js_url;
      script.setAttribute('data-client-key', config.client_key);
      script.setAttribute('data-midtrans-snap', 'true');
      script.onload = () => {
        script.setAttribute('data-loaded', 'true');
        resolve();
      };
      script.onerror = () => reject(new Error('Snap.js gagal dimuat. Periksa koneksi internet atau Client Key.'));
      document.body.appendChild(script);
    });

    if (!window.snap || typeof window.snap.pay !== 'function') {
      throw new Error('Snap.js belum siap digunakan.');
    }

    return true;
  })();

  return midtransSnapLoadPromise;
}

async function requestMidtransSnapToken(invoiceNo) {
  const res = await fetch(API.midtransSnapToken, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ invoice_no: invoiceNo })
  });
  const result = await res.json();

  if (!res.ok) {
    throw new Error(result.message || 'Gagal membuat Snap token Midtrans.');
  }

  return result.data || {};
}

async function syncMidtransStatus(invoiceNo) {
  if (!invoiceNo) return null;

  try {
    const res = await fetch(`${API.midtransStatus}?invoice_no=${encodeURIComponent(invoiceNo)}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await res.json();
    if (!res.ok) throw new Error(result.message || 'Gagal sinkron status Midtrans.');
    return result.data?.transaction || null;
  } catch (error) {
    // Webhook tetap menjadi sumber update utama. Kegagalan cek manual tidak menghentikan user flow.
    return null;
  }
}

async function startMidtransPayment(invoiceNo, snapToken = '', redirectUrl = '') {
  if (!currentUser) {
    showLoginModal();
    return;
  }

  if (!invoiceNo) {
    notify('Nomor invoice Midtrans tidak valid.', 'error');
    return;
  }

  try {
    let token = snapToken;
    if (!token) {
      const data = await requestMidtransSnapToken(invoiceNo);
      token = data.snap_token || '';
      redirectUrl = data.redirect_url || redirectUrl || '';

      if (!token) {
        notify(data.payment_status === 'paid' ? 'Invoice ini sudah dibayar.' : 'Snap token belum tersedia.', 'info');
        await loadTransactions();
        scrollToSection('transactionSection');
        return;
      }
    }

    await ensureMidtransSnapReady();

    window.snap.pay(token, {
      onSuccess: async function () {
        notify('Pembayaran diterima. Mengecek status invoice...', 'success');
        await syncMidtransStatus(invoiceNo);
        await loadTransactions();
        scrollToSection('transactionSection');
      },
      onPending: async function () {
        notify('Pembayaran dibuat dan masih menunggu penyelesaian.', 'info');
        await syncMidtransStatus(invoiceNo);
        await loadTransactions();
        scrollToSection('transactionSection');
      },
      onError: async function () {
        notify('Pembayaran belum berhasil. Silakan coba lagi atau pilih metode lain di Snap.', 'error');
        await syncMidtransStatus(invoiceNo);
        await loadTransactions();
      },
      onClose: async function () {
        notify('Popup Midtrans ditutup. Kamu bisa lanjut bayar dari riwayat transaksi.', 'info');
        await loadTransactions();
        scrollToSection('transactionSection');
      }
    });
  } catch (error) {
    if (redirectUrl) {
      window.location.href = redirectUrl;
      return;
    }
    notify(error.message || 'Gagal membuka pembayaran Midtrans.', 'error');
  }
}

async function payMidtransByTransactionIndex(index) {
  const trx = transactionsCache[index];
  if (!trx) return;
  await startMidtransPayment(trx.invoice_no, trx.midtrans_snap_token || '', trx.midtrans_redirect_url || '');
}

async function handleMidtransReturn() {
  if (!currentUser) return;

  const params = new URLSearchParams(window.location.search);
  const invoiceNo = params.get('invoice_no') || params.get('order_id') || '';
  const isPaymentReturn = params.get('payment') === 'finish' || params.has('result') || params.has('transaction_status');

  if (!invoiceNo || !isPaymentReturn) {
    return;
  }

  await syncMidtransStatus(invoiceNo);
  await loadTransactions();
  notify('Status pembayaran Midtrans sudah dicek ulang.', 'info');

  if (window.history && typeof window.history.replaceState === 'function') {
    window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
  }

  scrollToSection('transactionSection');
}

function formatItemsSummary(summary) {
  if (!summary) return '-';
  return String(summary).split(' || ').map(escapeHtml).join('<br>');
}

function showPaymentConfirmModalByIndex(index) {
  const trx = transactionsCache[index];
  if (!trx) return;
  showPaymentConfirmModal(safeTransaction(trx));
}

function showPaymentConfirmModal(transaction) {
  if (!currentUser) {
    showLoginModal();
    return;
  }

  const invoice = document.getElementById('confirm_invoice_no');
  const amount = document.getElementById('amount_paid');
  const preview = document.getElementById('proofPreview');
  const form = document.getElementById('paymentConfirmForm');

  resetFormSafe(form);

  if (invoice) invoice.value = transaction.invoice_no || '';
  if (amount) amount.value = Math.round(Number(transaction.total_amount || 0));
  if (preview) {
    preview.src = '';
    preview.style.display = 'none';
  }

  showOnlyModal('paymentConfirmModal');
  changeConfirmPaymentMethod();
}

async function handlePaymentConfirmation(event) {
  event?.preventDefault?.();

  const form = getSubmitForm(event, 'paymentConfirmForm');

  if (!currentUser) {
    showLoginModal();
    return;
  }

  if (!form) {
    notify('Form upload bukti tidak ditemukan.', 'error');
    return;
  }

  const formData = new FormData(form);

  try {
    const res = await fetch(API.uploadPayment, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const result = await res.json();

    if (!res.ok) {
      notify(result.message || 'Gagal mengirim bukti pembayaran.', 'error');
      return;
    }

    closeModal();
    notify(result.message || 'Bukti pembayaran berhasil dikirim.', 'success');
    await loadTransactions();
  } catch (error) {
    notify('Gagal mengirim bukti pembayaran. Periksa koneksi.', 'error');
  }
}

function previewProofFile(event) {
  const file = event.target.files?.[0];
  const preview = document.getElementById('proofPreview');
  if (!preview) return;

  if (!file) {
    preview.src = '';
    preview.style.display = 'none';
    return;
  }

  preview.src = URL.createObjectURL(file);
  preview.style.display = 'block';
}

async function loadReviews() {
  const wrapper = document.getElementById('reviewList');
  if (!wrapper) return;

  try {
    const res = await fetch(API.reviews, { credentials: 'same-origin' });
    const result = await res.json();
    if (!res.ok) throw new Error(result.message || 'Gagal memuat review.');

    const reviews = result.data?.reviews || [];
    if (!reviews.length) {
      wrapper.innerHTML = '<p class="muted">Belum ada ulasan.</p>';
      return;
    }

    wrapper.innerHTML = reviews.map((review) => `
      <div class="review-card">
        <b>${escapeHtml(maskUsername(review.username || 'Pelanggan'))}</b>
        <span>${'⭐'.repeat(Number(review.rating || 0))}</span>
        <p>${escapeHtml(review.review_text)}</p>
        <small>${escapeHtml(review.created_at || '')}</small>
      </div>
    `).join('');
  } catch (error) {
    wrapper.innerHTML = '<p class="muted">Ulasan belum bisa dimuat.</p>';
  }
}

async function handleReviewSubmit(event) {
  event?.preventDefault?.();

  const form = getSubmitForm(event, 'reviewForm');

  if (!currentUser) {
    notify('Login dulu untuk menulis ulasan.', 'warning');
    showLoginModal();
    return;
  }

  if (!form) {
    notify('Form ulasan tidak ditemukan.', 'error');
    return;
  }

  const payload = {
    rating: Number(form.rating?.value || 0),
    review_text: form.review_text?.value?.trim?.() || ''
  };

  try {
    const res = await fetch(API.reviews, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (!res.ok) {
      notify(result.message || 'Gagal mengirim ulasan.', 'error');
      return;
    }

    resetFormSafe(form);
    closeModal();
    notify(result.message || 'Ulasan berhasil dikirim.', 'success');
    await loadReviews();
  } catch (error) {
    notify('Gagal mengirim ulasan. Periksa koneksi.', 'error');
  }
}

async function handleLogin(event) {
  event?.preventDefault?.();

  const form = getSubmitForm(event, 'loginForm');
  if (!form) {
    notify('Form login tidak ditemukan.', 'error');
    return;
  }

  const payload = {
    login: form.login?.value || form.username?.value || '',
    password: form.password?.value || ''
  };

  try {
    const res = await fetch(API.login, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (!res.ok) {
      notify(result.message || 'Login gagal.', 'error');
      return;
    }

    // Pakai redirect dari backend. Kalau redirect_url tidak terkirim, tetap cek role dari response.
    const roleName = result.data?.user?.role_name || '';
    const isAdmin = roleName === 'admin' || roleName === 'superadmin' || result.data?.user?.is_admin === true;
    const redirectUrl = result.data?.redirect_url || (isAdmin ? 'admin.html' : 'index.html');

    window.location.replace(redirectUrl);
    return;
  } catch (error) {
    notify('Login gagal. Periksa koneksi.', 'error');
  }
}

async function handleRegister(event) {
  event?.preventDefault?.();

  const form = getSubmitForm(event, 'registerForm');
  if (!form) {
    notify('Form registrasi tidak ditemukan.', 'error');
    return;
  }

  const payload = {
    username: form.username?.value?.trim?.() || '',
    full_name: form.full_name?.value?.trim?.() || '',
    email: form.email?.value?.trim?.() || '',
    phone: form.phone?.value?.trim?.() || '',
    password: form.password?.value || ''
  };

  try {
    const res = await fetch(API.register, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (!res.ok) {
      notify(result.message || 'Registrasi gagal.', 'error');
      return;
    }

    resetFormSafe(form);
    notify(result.message || 'Registrasi berhasil. Silakan login.', 'success');
    showLoginModal();
  } catch (error) {
    notify('Registrasi gagal. Periksa koneksi.', 'error');
  }
}

async function logout() {
  try {
    await fetch(API.logout, {
      method: 'POST',
      credentials: 'same-origin'
    });
  } catch (error) {
    // tetap reset UI lokal
  }

  currentUser = null;
  renderUserArea();
  renderGuestTransactions();
  notify('Logout berhasil.', 'success');
}

function renderUserArea() {
  const wrapper = document.getElementById('userArea');
  if (!wrapper) return;

  if (!currentUser) {
    wrapper.innerHTML = `
      <button onclick="showLoginModal()">Login</button>
      <button onclick="showRegisterModal()">Register</button>
    `;
    return;
  }

  wrapper.innerHTML = `
    <span class="user-chip">Halo, <b>${escapeHtml(currentUser.username || 'User')}</b></span>
    <button onclick="logout()">Logout</button>
  `;
}

function showLoginModal() {
  showOnlyModal('loginModal');
}

function showRegisterModal() {
  showOnlyModal('registerModal');
}

function showReviewModal() {
  if (!currentUser) {
    notify('Login dulu untuk menulis ulasan.', 'warning');
    showLoginModal();
    return;
  }
  showOnlyModal('reviewModal');
}

function showOnlyModal(modalId) {
  const modalBg = document.getElementById('modalBg');
  if (!modalBg) return;

  modalBg.classList.add('show');
  document.querySelectorAll('#modalBg .modal').forEach((modal) => {
    toggleHidden(modal, modal.id !== modalId);
  });
}

function closeModal() {
  const modalBg = document.getElementById('modalBg');
  if (!modalBg) return;

  modalBg.classList.remove('show');
  document.querySelectorAll('#modalBg .modal').forEach((modal) => toggleHidden(modal, true));
}

function getSubmitForm(event, fallbackId) {
  // Jangan gunakan event.currentTarget. Di Firefox/Android nilainya bisa null setelah async.
  const target = event?.target;
  if (target && typeof target.reset === 'function') {
    return target;
  }
  return document.getElementById(fallbackId) || null;
}

function resetFormSafe(form) {
  if (form && typeof form.reset === 'function') {
    form.reset();
  }
}


function fallbackGames() {
  return Array.isArray(GG_FALLBACK.games) ? GG_FALLBACK.games : [];
}

function buildPopularGames(preferred = [], sourceGames = [], limit = 8) {
  const merged = [];
  const seen = new Set();

  const pushGame = (game) => {
    if (!game || game.id === undefined || game.id === null) return;
    const id = String(game.id);
    if (seen.has(id)) return;
    seen.add(id);
    merged.push(game);
  };

  [...(preferred || [])]
    .sort((a, b) => Number(b.sales_count || 0) - Number(a.sales_count || 0) || Number(b.is_popular || 0) - Number(a.is_popular || 0) || Number(a.sort_order || 999) - Number(b.sort_order || 999))
    .forEach(pushGame);

  [...(sourceGames || [])]
    .filter((game) => Number(game.is_popular) === 1)
    .sort((a, b) => Number(b.sales_count || 0) - Number(a.sales_count || 0) || Number(a.sort_order || 999) - Number(b.sort_order || 999))
    .forEach(pushGame);

  [...(sourceGames || [])]
    .sort((a, b) => Number(b.sales_count || 0) - Number(a.sales_count || 0) || Number(a.sort_order || 999) - Number(b.sort_order || 999))
    .forEach(pushGame);

  return merged.slice(0, limit);
}

function fallbackPopularGames() {
  const preferred = Array.isArray(GG_FALLBACK.popular_games) ? GG_FALLBACK.popular_games : [];
  return buildPopularGames(preferred, fallbackGames(), 8);
}

function fallbackProducts(gameId) {
  const map = GG_FALLBACK.products_by_game || {};
  return Array.isArray(map[String(gameId)]) ? map[String(gameId)] : [];
}

function fallbackPaymentMethods() {
  return Array.isArray(GG_FALLBACK.payment_methods) ? GG_FALLBACK.payment_methods : [];
}

function scrollToSection(id) {
  const el = document.getElementById(id);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function loadLocalCart() {
  try {
    const raw = localStorage.getItem('gugugaga_cart');
    const parsed = JSON.parse(raw || '[]');
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    return [];
  }
}

function saveLocalCart() {
  localStorage.setItem('gugugaga_cart', JSON.stringify(cart));
}

function notify(message, type = 'info') {
  const notif = document.getElementById('notif');
  if (!notif) {
    alert(message);
    return;
  }

  notif.textContent = message;
  notif.className = `notif show ${type}`;

  clearTimeout(notify.timer);
  notify.timer = setTimeout(() => {
    notif.classList.remove('show');
  }, 3500);
}

function toggleHidden(element, hidden) {
  if (!element) return;
  element.classList.toggle('hidden', Boolean(hidden));
}

function setLoading(id, message) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = `<p class="muted">${escapeHtml(message)}</p>`;
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function setSrc(id, value) {
  const el = document.getElementById(id);
  if (el) el.src = assetUrl(value, el.getAttribute('src') || '');
}

function setAttribute(id, attr, value) {
  const el = document.getElementById(id);
  if (el) el.setAttribute(attr, value);
}

function formatRupiah(value) {
  return Number(value || 0).toLocaleString('id-ID');
}

function formatNumber(value) {
  return Number(value || 0).toLocaleString('id-ID');
}

function assetUrl(value, fallback = '') {
  const raw = String(value || fallback || '').trim();
  if (!raw) return '';
  if (/^(https?:|data:|blob:)/i.test(raw)) return raw;
  if (raw.startsWith('./')) return raw.slice(2);
  return raw.replace(/^\/+/, '');
}

function maskUsername(username) {
  username = String(username || 'User');

  if (username.length <= 2) {
    return username.charAt(0) + '*';
  }

  return username.substring(0, 2) + '*'.repeat(username.length - 2);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
function maskUsername(username) {
  username = String(username || 'User');

  if (username.length <= 1) {
    return '*';
  }

  return username.charAt(0) + '*'.repeat(username.length - 1);
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
