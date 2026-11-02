-- One-time upgrade for databases created from the older query.sql
-- (standalone `images` table, no `variant_id` on images).
-- Back up Gemora first. If a statement errors because the change already exists, skip that line.

USE Gemora;

ALTER TABLE images ADD COLUMN variant_id INT NULL AFTER image_id;
ALTER TABLE images ADD COLUMN sort_order INT DEFAULT 0;

UPDATE images i
INNER JOIN variants v ON v.image_id = i.image_id
SET i.variant_id = v.variant_id
WHERE i.variant_id IS NULL;

DELETE FROM images WHERE variant_id IS NULL;

ALTER TABLE images MODIFY variant_id INT NOT NULL;
ALTER TABLE images ADD CONSTRAINT fk_images_variant FOREIGN KEY (variant_id) REFERENCES variants(variant_id) ON DELETE CASCADE;
