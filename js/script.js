/**
 * ============================================================
 *  FILE:  js/script.js
 *  PURPOSE: Core JavaScript for the entire Apple Store.
 *           This one file is loaded on EVERY HTML page.
 *
 *  WHAT IT DOES:
 *    1. Talks to PHP files via AJAX (no page refresh needed)
 *    2. Handles Login / Register / Logout modals
 *    3. Updates the cart badge in the navbar
 *    4. Shows toast (popup) notifications
 *    5. Controls the search bar
 *    6. Controls the mobile hamburger menu
 *    7. Provides shared helper functions used everywhere
 *
 *  HOW AJAX WORKS (simple explanation):
 *    - Normal links reload the whole page
 *    - AJAX sends a request to PHP IN THE BACKGROUND
 *    - PHP returns JSON (like: {"success":true,"user":{...}})
 *    - JavaScript reads that JSON and updates the page
 *    - The user never sees a page reload
 *
 *  REQUIRES: jQuery (loaded in HTML before this file)
 * ============================================================
 */

// =============================================================
// STEP 1: Define where all our PHP files are
// These paths are relative to the HTML file calling them.
// If your folder is named differently, update SITE_URL here.
// =============================================================
const API = {
    login: 'php/login.php',        // POST: email + password
    register: 'php/register.php',     // POST: full_name, email, password, phone
    auth: 'php/auth_check.php',   // GET ?action=check | POST action=logout
    products: 'php/products.php',     // GET ?action=list|featured|detail|categories|search
    cart: 'php/add_to_cart.php',  // POST/GET action=add|get|update|remove|count
    checkout: 'php/checkout.php',     // POST action=place | GET action=list|detail
};

// =============================================================
// STEP 2: Global state — stores the current user
// =============================================================
const AppState = {
    user: null,  // null = not logged in; object = { id, full_name, email, role }
    cartCount: 0,
};

// =============================================================
// STEP 3: Helper functions
// =============================================================

/**
 * showToast(message, type, duration)
 * Shows a small popup at the bottom of the screen.
 * type = 'success' (dark) or 'error' (red)
 */
function showToast(message, type = 'success', duration = 3000) {
    const $t = $('#toast');
    $t.removeClass('success error').addClass(type).text(message).addClass('show');
    setTimeout(() => $t.removeClass('show'), duration);
}

/**
 * formatPrice(amount)
 * Turns 1099 into "$1,099.00"
 */
function formatPrice(amount) {
    return '$' + parseFloat(amount || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * formatDate(dateStr)
 * Turns "2025-01-15 10:30:00" into "January 15, 2025"
 */
function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
}

/**
 * apiGet(url, data)
 * Shared GET request helper
 */
function apiGet(url, data = {}) {
    return $.ajax({
        url: url,
        method: 'GET',
        data: data,
        dataType: 'json'
    });
}

/**
 * apiPost(url, data)
 * Shared POST request helper
 */
function apiPost(url, data = {}) {
    return $.ajax({
        url: url,
        method: 'POST',
        data: data,
        dataType: 'json'
    });
}
// =============================================================
// STEP 4: Cart badge — the number shown on the bag icon
// =============================================================

/**
 * updateCartBadge()
 * Calls PHP to get the cart count and shows it in the navbar.
 * Called on every page load and after adding/removing items.
 */
function updateCartBadge() {
    $.getJSON(API.cart, { action: 'count' })
        .done(function (res) {
            const count = res.count || 0;
            AppState.cartCount = count;
            if (count > 0) {
                // Show the badge with the count
                $('#cartBadge').text(count > 99 ? '99+' : count).show();
            } else {
                $('#cartBadge').hide();
            }
        });
    // If it fails (user not logged in), just hide the badge
}

// =============================================================
// STEP 5: Authentication — check session on page load
// =============================================================

/**
 * initAuth()
 * Runs on every page load.
 * Calls php/auth_check.php to ask: "is anyone logged in?"
 * If yes, updates the navbar and shows the user's name.
 */
function initAuth() {
    $.getJSON(API.auth, { action: 'check' })
        .done(function (res) {
            if (res.loggedIn && res.user) {
                AppState.user = res.user;
                updateAuthUI(res.user);  // Show user name in modal
            }
            updateCartBadge();  // Always update cart badge
        })
        .fail(function () {
            // Session check failed (PHP error) — not critical, just hide badge
            $('#cartBadge').hide();
        });
}

/**
 * updateAuthUI(user)
 * Updates the account modal to show the logged-in user's info.
 * Shows admin link if the user is an admin.
 */
function updateAuthUI(user) {
    if (!user) return;

    // Show first letter of name as avatar (e.g. "J" for "John")
    $('#userAvatar').text(user.full_name.charAt(0).toUpperCase());
    // "Hello, John!"
    $('#userGreeting').text('Hello, ' + user.full_name.split(' ')[0] + '!');
    $('#userEmailDisplay').text(user.email);

    // Switch the modal to show the logged-in panel
    $('#loginForm, #registerForm').hide();
    $('#loggedInPanel').show();

    // Show Admin Panel link if admin
    if (user.role === 'admin') {
        $('#adminLink').show();
    } else {
        $('#adminLink').hide();
    }
}

// =============================================================
// STEP 6: Login form
// =============================================================

/**
 * When user clicks the "Continue" button in the login form,
 * we send the email + password to php/login.php via AJAX POST.
 * On success: update UI and close modal.
 * On failure: show the error message under the form.
 */
$('#loginSubmit').on('click', function () {
    const email = $('#loginEmail').val().trim();
    const pass = $('#loginPassword').val();

    // Simple front-end check before calling PHP
    if (!email || !pass) {
        $('#loginError').text('Please enter your email and password.');
        return;
    }

    // Disable button to prevent double-click
    $(this).text('Signing in…').prop('disabled', true);
    $('#loginError').text('');

    // AJAX POST to php/login.php
    $.ajax({
        url: API.login,
        method: 'POST',
        dataType: 'json',           // We expect JSON back
        data: { email: email, password: pass }
    })
        .done(function (res) {
            if (res.success) {
                AppState.user = res.user;
                updateAuthUI(res.user);
                updateCartBadge();
                showToast('Welcome back, ' + res.user.full_name.split(' ')[0] + '!');
                // Close modal after a short delay
                setTimeout(function () {
                    $('#authModal').removeClass('active');
                    // If on a protected page, reload it so it shows content
                    const path = window.location.pathname;
                    if (path.includes('cart') || path.includes('checkout') || path.includes('orders')) {
                        window.location.reload();
                    }
                }, 700);
            } else {
                // PHP returned { success: false, error: "..." }
                $('#loginError').text(res.error || 'Login failed.');
            }
        })
        .fail(function (xhr) {
            // Network error or PHP crash
            const msg = xhr.responseJSON ? xhr.responseJSON.error : 'Server error. Is XAMPP running?';
            $('#loginError').text(msg);
        })
        .always(function () {
            // Re-enable button whether success or failure
            $('#loginSubmit').text('Continue').prop('disabled', false);
        });
});

// =============================================================
// STEP 7: Register form
// =============================================================

$('#registerSubmit').on('click', function () {
    const name = $('#regName').val().trim();
    const email = $('#regEmail').val().trim();
    const phone = $('#regPhone').val().trim();
    const pass = $('#regPassword').val();

    if (!name || !email || !pass) {
        $('#registerError').text('Name, email, and password are required.');
        return;
    }
    if (pass.length < 6) {
        $('#registerError').text('Password must be at least 6 characters.');
        return;
    }

    $(this).text('Creating account…').prop('disabled', true);
    $('#registerError').text('');

    // AJAX POST to php/register.php
    $.ajax({
        url: API.register,
        method: 'POST',
        dataType: 'json',
        data: { full_name: name, email: email, phone: phone, password: pass }
    })
        .done(function (res) {
            if (res.success) {
                AppState.user = res.user;
                updateAuthUI(res.user);
                updateCartBadge();
                showToast('Account created! Welcome to Apple Store 🎉');
                setTimeout(function () { $('#authModal').removeClass('active'); }, 700);
            } else {
                $('#registerError').text(res.error || 'Registration failed.');
            }
        })
        .fail(function (xhr) {
            const msg = xhr.responseJSON ? xhr.responseJSON.error : 'Server error. Is XAMPP running?';
            $('#registerError').text(msg);
        })
        .always(function () {
            $('#registerSubmit').text('Create Account').prop('disabled', false);
        });
});

// =============================================================
// STEP 8: Logout
// =============================================================

$('#logoutBtn').on('click', function () {
    $.ajax({ url: API.auth, method: 'POST', data: { action: 'logout' }, dataType: 'json' })
        .always(function () {
            // Clear state regardless of server response
            AppState.user = null;
            AppState.cartCount = 0;
            $('#cartBadge').hide();

            // Reset modal to login form
            $('#loggedInPanel').hide();
            $('#loginForm').show();
            $('#authModal').removeClass('active');

            showToast('You have been signed out.');

            // Redirect away from protected pages
            const path = window.location.pathname;
            if (path.includes('cart') || path.includes('checkout') || path.includes('orders')) {
                window.location.href = 'index.html';
            }
        });
});

// =============================================================
// STEP 9: Account modal — open / close / switch tabs
// =============================================================

// Open modal when account icon is clicked
$('#accountBtn').on('click', function () {
    if (AppState.user) {
        // Already logged in — show user panel
        $('#loginForm, #registerForm').hide();
        $('#loggedInPanel').show();
    } else {
        // Not logged in — show login form
        $('#loggedInPanel, #registerForm').hide();
        $('#loginForm').show();
        $('#loginError').text('');
    }
    $('#authModal').addClass('active');
    feather.replace();  // Re-render icons inside modal
});

// Close modal with X button
$('#authModalClose').on('click', function () {
    $('#authModal').removeClass('active');
});

// Close modal by clicking the dark overlay behind it
$('#authModal').on('click', function (e) {
    if ($(e.target).is(this)) {
        $(this).removeClass('active');
    }
});

// Switch between Login and Register tabs
$('#showRegister').on('click', function (e) {
    e.preventDefault();
    $('#loginForm').hide();
    $('#registerForm').show();
    $('#registerError').text('');
});
$('#showLogin').on('click', function (e) {
    e.preventDefault();
    $('#registerForm').hide();
    $('#loginForm').show();
    $('#loginError').text('');
});

// =============================================================
// STEP 10: Add to Cart function (used on product.html)
// =============================================================

/**
 * addToCart(productId, variantId, quantity, btn)
 * Called when user clicks "Add to Bag" on product page.
 * @param productId  - the product's database ID
 * @param variantId  - the selected variant ID (or null)
 * @param quantity   - how many to add
 * @param btn        - the button element (so we can change its text)
 */
function addToCart(productId, variantId, quantity, btn) {
    // Must be logged in to add to cart
    if (!AppState.user) {
        $('#authModal').addClass('active');
        feather.replace();
        showToast('Please sign in to add items to your bag.', 'error');
        return;
    }

    const $btn = btn ? $(btn) : null;
    if ($btn) $btn.text('Adding…').prop('disabled', true);

    $.ajax({
        url: API.cart,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'add',
            product_id: productId,
            variant_id: variantId || '',
            quantity: quantity || 1
        }
    })
        .done(function (res) {
            if (res.success) {
                // Update badge
                AppState.cartCount = res.count;
                $('#cartBadge').text(res.count > 99 ? '99+' : res.count).show();

                showToast('Added to your bag! 🛍️');

                if ($btn) {
                    $btn.text('Added ✓').addClass('btn-dark');
                    setTimeout(function () {
                        $btn.text('Add to Bag').removeClass('btn-dark').prop('disabled', false);
                    }, 2000);
                }
            } else {
                showToast(res.error || 'Could not add to cart.', 'error');
                if ($btn) $btn.text('Add to Bag').prop('disabled', false);
            }
        })
        .fail(function () {
            showToast('Server error. Please try again.', 'error');
            if ($btn) $btn.text('Add to Bag').prop('disabled', false);
        });
}

// =============================================================
// STEP 11: Product card HTML template
// Used on homepage and products page to render product cards.
// =============================================================

/**
 * renderProductCard(product)
 * Returns an HTML string for a product card.
 */
function renderProductCard(product) {
    const price = parseFloat(product.base_price) + parseFloat(product.min_modifier || 0);
    const badge = product.is_featured ? '<span class="product-badge">Featured</span>' : '';

    // If the product has an image in the database, show it.
    // Otherwise show a placeholder SVG icon.
    const imgHtml = product.image_main
        ? `<img src="${product.image_main}" alt="${product.name}"
               onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
           <div class="product-placeholder" style="display:none;">
               <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="1.5">
                   <rect x="5" y="2" width="14" height="20" rx="2"/>
               </svg>
           </div>`
        : `<div class="product-placeholder">
               <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="1.5">
                   <rect x="5" y="2" width="14" height="20" rx="2"/>
               </svg>
           </div>`;

    return `
        <a href="product.html?slug=${product.slug}" class="product-card">
            <div class="product-card-img">
                ${badge}
                ${imgHtml}
            </div>
            <div class="product-card-body">
                <h3>${product.name}</h3>
                <p>${product.tagline || product.category_name || ''}</p>
                <div class="product-card-price">From <strong>${formatPrice(price)}</strong></div>
            </div>
        </a>
    `;
}

// =============================================================
// STEP 12: Search bar
// =============================================================

$('#searchToggle').on('click', function () {
    $('#searchBar').toggleClass('active');
    if ($('#searchBar').hasClass('active')) {
        $('#searchInput').focus();
    }
});

$('#searchClose').on('click', function () {
    $('#searchBar').removeClass('active');
    $('#searchInput').val('');
    $('#searchResults').hide().empty();
});

let searchTimer;
$('#searchInput').on('input', function () {
    const q = $(this).val().trim();
    clearTimeout(searchTimer);
    if (q.length < 2) { $('#searchResults').hide(); return; }

    // Wait 300ms after typing stops before searching (debounce)
    searchTimer = setTimeout(function () {
        $.getJSON(API.products, { action: 'search', q: q })
            .done(function (res) {
                const $res = $('#searchResults');
                if (!res.products || !res.products.length) { $res.hide(); return; }

                const html = res.products.slice(0, 5).map(function (p) {
                    const imgHtml = p.image_main
                        ? `<img src="${p.image_main}" alt="${p.name}" style="width:40px;height:40px;object-fit:contain;border-radius:8px;">`
                        : `<div style="width:40px;height:40px;background:#f5f5f7;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                               <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#aaa" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/></svg>
                           </div>`;
                    return `<div class="search-result-item" onclick="window.location.href='product.html?slug=${p.slug}'">
                                ${imgHtml}
                                <div class="search-result-info">
                                    <h4>${p.name}</h4>
                                    <p>${formatPrice(p.base_price)}</p>
                                </div>
                            </div>`;
                }).join('');

                $res.html(html).show();
            });
    }, 300);
});

// =============================================================
// STEP 13: Mobile hamburger menu
// =============================================================

$('#navHamburger').on('click', function () {
    const $links = $('#navLinks');
    $links.toggleClass('mobile-open');

    if ($links.hasClass('mobile-open')) {
        $links.css({
            display: 'flex',
            flexDirection: 'column',
            position: 'absolute',
            top: '44px',
            left: 0,
            right: 0,
            background: 'rgba(255,255,255,0.97)',
            padding: '16px',
            backdropFilter: 'blur(20px)',
            borderBottom: '1px solid #e8e8ed',
            zIndex: 999
        });
    } else {
        $links.css('display', '');
    }
    feather.replace();
});

// =============================================================
// STEP 14: Navbar scroll effect
// =============================================================

$(window).on('scroll', function () {
    if ($(this).scrollTop() > 20) {
        $('#navbar').css('background', 'rgba(255,255,255,0.95)');
    } else {
        $('#navbar').css('background', 'var(--nav-bg)');
    }
});

// =============================================================
// STEP 15: Initialize everything when page loads
// =============================================================
$(document).ready(function () {
    feather.replace();
    initAuth();
});