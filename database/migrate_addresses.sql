-- Structured address fields (region, city/township, apartment/building)
USE Gemora;

ALTER TABLE addresses ADD COLUMN region VARCHAR(100) NULL AFTER user_id;
ALTER TABLE addresses ADD COLUMN city_township VARCHAR(100) NULL AFTER region;
ALTER TABLE addresses ADD COLUMN apartment_building VARCHAR(255) NULL AFTER city_township;
ALTER TABLE addresses ADD COLUMN street_address VARCHAR(255) NULL AFTER apartment_building;
