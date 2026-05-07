/**
 * Apple Store — Home Page
 */

const CATEGORY_ICONS = {
    iphone: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"/></svg>`,
    mac: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>`,
    ipad: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"/></svg>`,
    'apple-watch': `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="7" width="14" height="10" rx="2"/><path d="M9 7V4h6v3"/><path d="M9 17v3h6v-3"/><circle cx="12" cy="12" r="1"/></svg>`,
    airpods: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/><path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>`,
    accessories: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>`,
};

$(document).ready(() => {
    loadCategories();
    loadFeaturedProducts();
    animateHero();
});

function loadCategories() {
    apiGet(API.products, { action: 'categories' }).done((res) => {
        const $grid = $('#categoryGrid');
        $grid.empty();
        if (!res.categories) return;

        res.categories.forEach((cat) => {
            const icon = CATEGORY_ICONS[cat.slug] || CATEGORY_ICONS['accessories'];
            const $tile = $(`
                <a href="products.html?category=${cat.slug}" class="category-tile">
                    ${icon}
                    <span>${cat.name}</span>
                </a>
            `);
            $grid.append($tile);
        });
        feather.replace();
    }).fail(() => {
        // Demo fallback if PHP not running
        const demo = [
            { name: 'iPhone', slug: 'iphone' },
            { name: 'Mac', slug: 'mac' },
            { name: 'iPad', slug: 'ipad' },
            { name: 'Watch', slug: 'apple-watch' },
            { name: 'AirPods', slug: 'airpods' },
            { name: 'Accessories', slug: 'accessories' },
        ];
        const $grid = $('#categoryGrid');
        $grid.empty();
        demo.forEach((cat) => {
            const icon = CATEGORY_ICONS[cat.slug] || CATEGORY_ICONS['accessories'];
            $grid.append(`
                <a href="products.html?category=${cat.slug}" class="category-tile">
                    ${icon}<span>${cat.name}</span>
                </a>
            `);
        });
    });
}

function loadFeaturedProducts() {
    const $grid = $('#featuredGrid');
    // Skeleton placeholders
    $grid.html(Array(4).fill(`<div class="product-card skeleton" style="height:380px"></div>`).join(''));

    apiGet(API.products, { action: 'featured' }).done((res) => {
        $grid.empty();
        if (!res.products || !res.products.length) {
            $grid.html('<p style="color:var(--text-sec);grid-column:1/-1">No featured products found.</p>');
            return;
        }
        res.products.forEach((p) => $grid.append(renderProductCard(p)));
    }).fail(() => {
        // Demo fallback
        const demoProducts = [
            { name: 'iPhone 16 Pro', slug: 'iphone-16-pro', tagline: 'Titanium. So strong. So light. So Pro.', base_price: 1099, min_modifier: 0, is_featured: 1 },
            { name: 'MacBook Pro 14"', slug: 'macbook-pro-14', tagline: 'Mind-blowing. Head-turning.', base_price: 1999, min_modifier: 0, is_featured: 1 },
            { name: 'iPad Pro 13"', slug: 'ipad-pro-13', tagline: 'Thin. Light. Mind-blowing.', base_price: 1299, min_modifier: 0, is_featured: 1 },
            { name: 'AirPods Pro 2nd Gen', slug: 'airpods-pro-2', tagline: 'Adaptive Audio. Now playing.', base_price: 249, min_modifier: 0, is_featured: 1 },
        ];
        $grid.empty();
        demoProducts.forEach((p) => $grid.append(renderProductCard(p)));
    });
}

function animateHero() {
    const $hero = $('.hero-content > *');
    $hero.each((i, el) => {
        $(el).css({ opacity: 0, transform: 'translateY(20px)', transition: `opacity 0.6s ease ${i * 0.15}s, transform 0.6s ease ${i * 0.15}s` });
        setTimeout(() => $(el).css({ opacity: 1, transform: 'translateY(0)' }), 100 + i * 150);
    });
}