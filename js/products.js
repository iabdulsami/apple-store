/**
 * Apple Store — Products Listing Page
 */

// =============================================================
// API HELPERS
// =============================================================

function apiGet(url, params = {}) {
    return $.ajax({
        url: url,
        method: 'GET',
        data: params,
        dataType: 'json'
    }).fail(function (xhr) {
        console.error('API GET error:', url, xhr);
    });
}

function apiPost(url, data = {}) {
    return $.ajax({
        url: url,
        method: 'POST',
        data: data,
        dataType: 'json'
    }).fail(function (xhr) {
        console.error('API POST error:', url, xhr);
    });
}

// =============================================================
// PRODUCTS PAGE
// =============================================================

let currentCategory = '';

$(document).ready(() => {
    const params = new URLSearchParams(window.location.search);

    currentCategory = params.get('category') || '';

    loadCategoryFilters();
    loadProducts(currentCategory);
    updatePageTitle(currentCategory);
});

// =============================================================
// PAGE TITLE
// =============================================================

function updatePageTitle(cat) {

    const titles = {
        iphone: {
            h: 'iPhone',
            sub: 'Explore the iPhone lineup.'
        },

        mac: {
            h: 'Mac',
            sub: 'Power through anything.'
        },

        ipad: {
            h: 'iPad',
            sub: 'Your next computer is not a computer.'
        },

        'apple-watch': {
            h: 'Apple Watch',
            sub: 'The future of health is on your wrist.'
        },

        airpods: {
            h: 'AirPods',
            sub: 'Legendary sound. Everywhere.'
        },

        accessories: {
            h: 'Accessories',
            sub: 'Complete your experience.'
        }
    };

    const t = cat && titles[cat]
        ? titles[cat]
        : {
            h: 'All Products',
            sub: 'Discover our complete range of Apple products.'
        };

    $('#listingTitle').text(t.h);
    $('#listingSubtitle').text(t.sub);

    document.title = `${t.h} — Apple Store`;
}

// =============================================================
// CATEGORY FILTERS
// =============================================================

function loadCategoryFilters() {

    apiGet(API.products, {
        action: 'categories'
    })

        .done((res) => {

            const $filters = $('#categoryFilters');

            $filters.empty();

            // All button
            $filters.append(`
            <button class="filter-btn ${currentCategory === '' ? 'active' : ''}" data-cat="">
                All
            </button>
        `);

            if (!res.categories) return;

            res.categories.forEach((cat) => {

                const $btn = $(`
                <button class="filter-btn ${currentCategory === cat.slug ? 'active' : ''}" data-cat="${cat.slug}">
                    ${cat.name}
                </button>
            `);

                $filters.append($btn);
            });
        });

    // Filter click
    $(document).on('click', '.filter-btn', function () {

        $('.filter-btn').removeClass('active');

        $(this).addClass('active');

        const cat = $(this).data('cat');

        currentCategory = cat;

        loadProducts(cat);

        updatePageTitle(cat);

        const url = cat
            ? `?category=${cat}`
            : 'products.html';

        history.pushState(null, '', url);
    });
}

// =============================================================
// LOAD PRODUCTS
// =============================================================

function loadProducts(category) {

    const $grid = $('#productsGrid');

    // Loading skeleton
    $grid.html(
        Array(6).fill(`
            <div class="product-card skeleton" style="height:380px"></div>
        `).join('')
    );

    const params = {
        action: 'list'
    };

    if (category) {
        params.category = category;
    }

    apiGet(API.products, params)

        .done((res) => {

            $grid.empty();

            if (!res.products || !res.products.length) {

                $grid.html(`
                <p style="color:var(--text-sec);grid-column:1/-1;padding:40px 0">
                    No products found in this category.
                </p>
            `);

                return;
            }

            res.products.forEach((p) => {

                $grid.append(renderProductCard(p));
            });
        })

        .fail(() => {

            // Demo fallback
            const demo = [

                {
                    name: 'iPhone 16 Pro',
                    slug: 'iphone-16-pro',
                    tagline: 'Titanium. So strong.',
                    base_price: 1099,
                    min_modifier: 0,
                    is_featured: 1,
                    category_name: 'iPhone'
                },

                {
                    name: 'iPhone 16',
                    slug: 'iphone-16',
                    tagline: 'Built for Apple Intelligence.',
                    base_price: 799,
                    min_modifier: 0,
                    is_featured: 1,
                    category_name: 'iPhone'
                },

                {
                    name: 'MacBook Pro 14"',
                    slug: 'macbook-pro-14',
                    tagline: 'Mind-blowing.',
                    base_price: 1999,
                    min_modifier: 0,
                    is_featured: 0,
                    category_name: 'Mac'
                },

                {
                    name: 'MacBook Air 13"',
                    slug: 'macbook-air-13',
                    tagline: 'Impossibly thin.',
                    base_price: 1099,
                    min_modifier: 0,
                    is_featured: 1,
                    category_name: 'Mac'
                },

                {
                    name: 'iPad Pro 13"',
                    slug: 'ipad-pro-13',
                    tagline: 'Thin. Light. Mind-blowing.',
                    base_price: 1299,
                    min_modifier: 0,
                    is_featured: 1,
                    category_name: 'iPad'
                },

                {
                    name: 'AirPods Pro 2',
                    slug: 'airpods-pro-2',
                    tagline: 'Adaptive Audio.',
                    base_price: 249,
                    min_modifier: 0,
                    is_featured: 0,
                    category_name: 'AirPods'
                }
            ];

            $grid.empty();

            const filtered = category
                ? demo.filter(p =>
                    p.category_name.toLowerCase().replace(' ', '-') === category ||
                    p.slug.includes(category.replace('apple-watch', 'watch'))
                )
                : demo;

            (filtered.length ? filtered : demo).forEach((p) => {

                $grid.append(renderProductCard(p));
            });
        });
}