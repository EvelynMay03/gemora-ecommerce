-- Checkout: shipping address on orders
USE Gemora;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS shipping_address TEXT NULL AFTER shipping;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS payment_screenshot VARCHAR(255) NULL AFTER payment_method;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS order_kind ENUM('instock', 'preorder') NULL AFTER status;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS order_number VARCHAR(20) NULL UNIQUE AFTER order_kind;
