-- User notification preferences (JSON in TEXT column)
USE Gemora;

ALTER TABLE users ADD COLUMN preference TEXT NULL AFTER image;
