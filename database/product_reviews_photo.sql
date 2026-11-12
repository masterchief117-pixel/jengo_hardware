-- Apply once to an existing installation whose product_reviews table predates photo uploads.
ALTER TABLE product_reviews
    ADD COLUMN photo_url VARCHAR(255) NULL AFTER comment;
