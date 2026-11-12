-- Apply once to an existing installation to add customer product reviews.
CREATE TABLE IF NOT EXISTS product_reviews (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    INT UNSIGNED NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    rating        INT NOT NULL,
    comment       TEXT NOT NULL,
    photo_url     VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_reviews_product_date (product_id, created_at),
    CONSTRAINT chk_product_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
