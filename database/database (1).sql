-- ============================================================
-- Apple Store E-Commerce — Database Schema + Seed Data
-- HOW TO USE:
--   1. Open phpMyAdmin (http://localhost/phpmyadmin)
--   2. Click "SQL" tab at the top
--   3. Paste this entire file and click "Go"
--   OR in terminal: mysql -u root -p < database.sql
-- ============================================================

-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS apple_store
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Tell MySQL to use this database for all commands below
USE apple_store;

-- ============================================================
-- TABLE: users
-- Stores customer and admin accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,       -- must be unique (acts as username)
    password   VARCHAR(255) NOT NULL,              -- always stored as a bcrypt hash, never plain text
    phone      VARCHAR(20),
    address    TEXT,
    city       VARCHAR(80),
    country    VARCHAR(80) DEFAULT 'Pakistan',
    role       ENUM('customer','admin') DEFAULT 'customer',  -- two roles: customer or admin
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: categories
-- iPhone, Mac, iPad, Apple Watch, AirPods, Accessories
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(80) NOT NULL,
    slug       VARCHAR(80) NOT NULL UNIQUE,   -- URL-friendly name e.g. "apple-watch"
    icon       VARCHAR(50),
    sort_order INT DEFAULT 0                  -- controls display order on site
);

-- ============================================================
-- TABLE: products
-- Each row is one product (e.g. iPhone 16 Pro)
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    category_id   INT NOT NULL,
    name          VARCHAR(150) NOT NULL,
    slug          VARCHAR(150) NOT NULL UNIQUE,  -- used in URL: product.html?slug=iphone-16-pro
    tagline       VARCHAR(200),                  -- short marketing line shown on cards
    description   TEXT,                          -- longer description shown on product page
    base_price    DECIMAL(10,2) NOT NULL,         -- starting price (cheapest variant)
    image_main    VARCHAR(255),                   -- path to main image e.g. images/iphone16pro.jpg
    image_gallery TEXT,                           -- JSON array of extra images (optional)
    is_featured   TINYINT(1) DEFAULT 0,           -- 1 = show on homepage featured section
    is_active     TINYINT(1) DEFAULT 1,           -- 0 = soft-deleted / hidden
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- ============================================================
-- TABLE: product_variants
-- Each product can have multiple variants (color + storage combos)
-- e.g. iPhone 16 Pro 256GB Black Titanium
-- ============================================================
CREATE TABLE IF NOT EXISTS product_variants (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    product_id     INT NOT NULL,
    storage        VARCHAR(30),              -- e.g. "128GB", "512GB SSD", "42mm"
    color          VARCHAR(50),              -- e.g. "Black Titanium"
    color_hex      VARCHAR(7),              -- e.g. "#2C2C2C" — used to show color swatch
    price_modifier DECIMAL(10,2) DEFAULT 0.00, -- added on top of base_price
    stock          INT DEFAULT 10,
    sku            VARCHAR(80) UNIQUE,       -- unique product code for inventory
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: cart
-- Stores each user's cart items persistently in the database
-- This means cart survives browser close / login on another device
-- ============================================================
CREATE TABLE IF NOT EXISTS cart (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT,                          -- NULL if product has no variants
    quantity   INT NOT NULL DEFAULT 1,
    added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- ============================================================
-- TABLE: orders
-- Created when customer completes checkout
-- ============================================================
CREATE TABLE IF NOT EXISTS orders (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    order_number     VARCHAR(30) NOT NULL UNIQUE,  -- e.g. ORD-A1B2C3D4
    status           ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    subtotal         DECIMAL(10,2) NOT NULL,
    shipping         DECIMAL(10,2) DEFAULT 0.00,
    tax              DECIMAL(10,2) DEFAULT 0.00,
    total            DECIMAL(10,2) NOT NULL,
    shipping_address TEXT NOT NULL,
    payment_method   VARCHAR(50) DEFAULT 'COD',    -- Cash on Delivery
    notes            TEXT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- TABLE: order_items
-- Each row = one product line inside an order
-- ============================================================
CREATE TABLE IF NOT EXISTS order_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT NOT NULL,
    product_id   INT NOT NULL,
    variant_id   INT,
    product_name VARCHAR(150) NOT NULL,   -- snapshot of name at time of purchase
    variant_info VARCHAR(100),            -- e.g. "256GB Black Titanium"
    quantity     INT NOT NULL,
    unit_price   DECIMAL(10,2) NOT NULL,
    total_price  DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- ============================================================
-- SEED DATA — Admin User
-- Email:    admin@applestore.com
-- Password: Admin@123
-- NOTE: The hash below is a proper bcrypt hash of "Admin@123"
-- ============================================================
INSERT INTO users (full_name, email, password, role) VALUES
('Admin User', 'admin@applestore.com',
 '$2y$10$TKh8H1.PfzsHDcMpFBByEuGFqBNKXOWBJuFrXvBJVdtXIhPMlrW5u',
 'admin');

-- ============================================================
-- SEED DATA — Categories
-- ============================================================
INSERT INTO categories (name, slug, icon, sort_order) VALUES
('iPhone',      'iphone',      'smartphone', 1),
('Mac',         'mac',         'monitor',    2),
('iPad',        'ipad',        'tablet',     3),
('Apple Watch', 'apple-watch', 'watch',      4),
('AirPods',     'airpods',     'headphones', 5),
('Accessories', 'accessories', 'package',    6);

-- ============================================================
-- SEED DATA — Products
-- image_main paths point to images/ folder
-- You add your own images there with the same filenames
-- ============================================================

-- iPhones
INSERT INTO products (category_id, name, slug, tagline, description, base_price, image_main, is_featured, is_active) VALUES
(1, 'iPhone 16 Pro', 'iphone-16-pro',
 'Titanium. So strong. So light. So Pro.',
 'The iPhone 16 Pro features a stunning Super Retina XDR display, the powerful A18 Pro chip, and a pro camera system that shoots 4K video at 120fps. With titanium design and all-day battery life, this is the most powerful iPhone ever made.',
 1099.00, 'images/iphone16pro.jpg', 1, 1),

(1, 'iPhone 16', 'iphone-16',
 'Built for Apple Intelligence.',
 'iPhone 16 features the powerful A18 chip, Camera Control, and a 48MP main camera. Comes in five stunning colors with up to 28 hours of video playback.',
 799.00, 'images/iphone16.jpg', 1, 1),

(1, 'iPhone 15', 'iphone-15',
 'A total powerhouse.',
 'iPhone 15 features Dynamic Island, a 48MP main camera, USB-C, and the powerful A16 Bionic chip. Available in five beautiful colors.',
 599.00, 'images/iphone15.jpg', 0, 1);

-- Macs
INSERT INTO products (category_id, name, slug, tagline, description, base_price, image_main, is_featured, is_active) VALUES
(2, 'MacBook Pro 14"', 'macbook-pro-14',
 'Mind-blowing. Head-turning.',
 'The MacBook Pro 14-inch with M4 Pro chip delivers extraordinary performance with up to 24 CPU cores and 40 GPU cores. With a stunning Liquid Retina XDR display and up to 22 hours of battery life.',
 1999.00, 'images/macbookpro14.jpg', 1, 1),

(2, 'MacBook Air 13"', 'macbook-air-13',
 'Impossibly thin. Endlessly capable.',
 'MacBook Air with M3 is our thinnest and lightest laptop ever, with a stunning 13.6-inch Liquid Retina display, up to 18 hours of battery life, and all-day performance for everything you do.',
 1099.00, 'images/macbookair13.jpg', 1, 1),

(2, 'iMac 24"', 'imac-24',
 'All-in-one. All-inspiring.',
 'iMac with M3 chip delivers a powerful all-in-one desktop experience with a gorgeous 24-inch 4.5K Retina display, an advanced 12MP camera, and studio-quality three-mic array.',
 1299.00, 'images/imac24.jpg', 0, 1);

-- iPads
INSERT INTO products (category_id, name, slug, tagline, description, base_price, image_main, is_featured, is_active) VALUES
(3, 'iPad Pro 13"', 'ipad-pro-13',
 'Thin. Light. Mind-blowing.',
 'The thinnest Apple product ever. iPad Pro features the M4 chip, an Ultra Retina XDR display with tandem OLED technology, and Apple Pencil Pro support.',
 1299.00, 'images/ipadpro13.jpg', 1, 1),

(3, 'iPad Air 11"', 'ipad-air-11',
 'Performance and capability in a thin, light design.',
 'iPad Air features the powerful M2 chip, a beautiful 11-inch Liquid Retina display, and support for Apple Pencil and Magic Keyboard.',
 599.00, 'images/ipadair11.jpg', 0, 1);

-- Apple Watch
INSERT INTO products (category_id, name, slug, tagline, description, base_price, image_main, is_featured, is_active) VALUES
(4, 'Apple Watch Series 10', 'apple-watch-series-10',
 'Thinnest. Biggest display ever.',
 'Apple Watch Series 10 is the thinnest Apple Watch ever with the largest display, faster charging, and advanced health features including sleep apnea detection.',
 399.00, 'images/applewatchs10.jpg', 1, 1),

(4, 'Apple Watch Ultra 2', 'apple-watch-ultra-2',
 'Precision. Durability. Power.',
 'Apple Watch Ultra 2 is the most capable Apple Watch ever. Built for athletes and adventurers, with precision dual-frequency GPS, up to 60 hours of battery life, and the brightest display on any Apple Watch.',
 799.00, 'images/applewatchultra2.jpg', 0, 1);

-- AirPods
INSERT INTO products (category_id, name, slug, tagline, description, base_price, image_main, is_featured, is_active) VALUES
(5, 'AirPods Pro 2nd Gen', 'airpods-pro-2',
 'Adaptive Audio. Now playing.',
 'AirPods Pro (2nd generation) deliver up to 2x more Active Noise Cancellation than the previous generation with Adaptive Audio that seamlessly blends ANC and Transparency mode.',
 249.00, 'images/airpodspro2.jpg', 1, 1),

(5, 'AirPods 4', 'airpods-4',
 'Unreal comfort. Incredible sound.',
 'AirPods 4 feature an entirely new design with Active Noise Cancellation, Transparency mode, and Personalized Spatial Audio for an immersive listening experience.',
 129.00, 'images/airpods4.jpg', 0, 1);

-- ============================================================
-- SEED DATA — Product Variants
-- ============================================================

-- iPhone 16 Pro variants (product_id = 1)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(1, '128GB', 'Black Titanium',   '#2C2C2C', 0.00,   15, 'IP16P-128-BLK'),
(1, '128GB', 'White Titanium',   '#F5F5F0', 0.00,   12, 'IP16P-128-WHT'),
(1, '128GB', 'Natural Titanium', '#B8A99A', 0.00,   10, 'IP16P-128-NAT'),
(1, '128GB', 'Desert Titanium',  '#C8A97E', 0.00,    8, 'IP16P-128-DST'),
(1, '256GB', 'Black Titanium',   '#2C2C2C', 100.00, 15, 'IP16P-256-BLK'),
(1, '256GB', 'White Titanium',   '#F5F5F0', 100.00, 12, 'IP16P-256-WHT'),
(1, '256GB', 'Natural Titanium', '#B8A99A', 100.00, 10, 'IP16P-256-NAT'),
(1, '512GB', 'Black Titanium',   '#2C2C2C', 300.00,  8, 'IP16P-512-BLK'),
(1, '512GB', 'White Titanium',   '#F5F5F0', 300.00,  6, 'IP16P-512-WHT'),
(1, '1TB',   'Black Titanium',   '#2C2C2C', 500.00,  5, 'IP16P-1TB-BLK');

-- iPhone 16 variants (product_id = 2)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(2, '128GB', 'Black',       '#1A1A1A', 0.00,   20, 'IP16-128-BLK'),
(2, '128GB', 'White',       '#FAFAF5', 0.00,   18, 'IP16-128-WHT'),
(2, '128GB', 'Pink',        '#F4C2C2', 0.00,   15, 'IP16-128-PNK'),
(2, '128GB', 'Teal',        '#4E8D8D', 0.00,   12, 'IP16-128-TEL'),
(2, '128GB', 'Ultramarine', '#4166F5', 0.00,   10, 'IP16-128-ULT'),
(2, '256GB', 'Black',       '#1A1A1A', 100.00, 20, 'IP16-256-BLK'),
(2, '256GB', 'White',       '#FAFAF5', 100.00, 18, 'IP16-256-WHT'),
(2, '512GB', 'Black',       '#1A1A1A', 300.00, 10, 'IP16-512-BLK');

-- MacBook Pro 14" variants (product_id = 4)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(4, '512GB SSD', 'Space Black', '#1C1C1E', 0.00,   8, 'MBP14-512-SBK'),
(4, '512GB SSD', 'Silver',      '#E8E8E8', 0.00,   8, 'MBP14-512-SLV'),
(4, '1TB SSD',   'Space Black', '#1C1C1E', 200.00, 6, 'MBP14-1TB-SBK'),
(4, '1TB SSD',   'Silver',      '#E8E8E8', 200.00, 6, 'MBP14-1TB-SLV');

-- MacBook Air 13" variants (product_id = 5)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(5, '256GB SSD', 'Midnight',  '#1D2D44', 0.00,   12, 'MBA13-256-MNT'),
(5, '256GB SSD', 'Starlight', '#E8E0D5', 0.00,   12, 'MBA13-256-STL'),
(5, '256GB SSD', 'Sky Blue',  '#7EB8D4', 0.00,   10, 'MBA13-256-SKB'),
(5, '512GB SSD', 'Midnight',  '#1D2D44', 200.00,  8, 'MBA13-512-MNT'),
(5, '512GB SSD', 'Starlight', '#E8E0D5', 200.00,  8, 'MBA13-512-STL');

-- iPad Pro 13" variants (product_id = 7)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(7, '256GB', 'Space Black', '#1C1C1E', 0.00,   10, 'IPDP13-256-SBK'),
(7, '256GB', 'Silver',      '#E8E8E8', 0.00,   10, 'IPDP13-256-SLV'),
(7, '512GB', 'Space Black', '#1C1C1E', 200.00,  6, 'IPDP13-512-SBK'),
(7, '1TB',   'Space Black', '#1C1C1E', 600.00,  4, 'IPDP13-1TB-SBK');

-- Apple Watch Series 10 variants (product_id = 9)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(9, '42mm', 'Jet Black',  '#1A1A1A', 0.00,  15, 'AWS10-42-JBK'),
(9, '42mm', 'Rose Gold',  '#C9956C', 0.00,  12, 'AWS10-42-RSG'),
(9, '42mm', 'Silver',     '#E0E0E0', 0.00,  12, 'AWS10-42-SLV'),
(9, '46mm', 'Jet Black',  '#1A1A1A', 30.00, 10, 'AWS10-46-JBK'),
(9, '46mm', 'Rose Gold',  '#C9956C', 30.00,  8, 'AWS10-46-RSG');

-- AirPods Pro 2 variants (product_id = 11)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(11, NULL, 'White', '#FFFFFF', 0.00, 25, 'APP2-WHT'),
(11, NULL, 'Black', '#1A1A1A', 0.00, 20, 'APP2-BLK');

-- AirPods 4 variants (product_id = 12)
INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku) VALUES
(12, NULL, 'White', '#FFFFFF', 0.00, 30, 'AP4-WHT');
