CREATE TABLE IF NOT EXISTS gallery (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255) NULL,
    image_url   VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gallery_uploaded_at (uploaded_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_folders (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_name VARCHAR(100) NOT NULL,
    cover_image VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gallery_folders_created (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_photos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NULL,
    image_url   VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gallery_photos_folder_date (folder_id, uploaded_at, id),
    CONSTRAINT fk_gallery_photos_folder
        FOREIGN KEY (folder_id) REFERENCES gallery_folders (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve flat-gallery entries in a starter album; safe to rerun.
INSERT INTO gallery_folders (folder_name, cover_image)
SELECT 'Imported Gallery',
       (SELECT g.image_url FROM gallery AS g ORDER BY g.uploaded_at DESC, g.id DESC LIMIT 1)
FROM DUAL
WHERE EXISTS (SELECT 1 FROM gallery)
  AND NOT EXISTS (
      SELECT 1 FROM gallery_folders WHERE folder_name = 'Imported Gallery'
  );

INSERT INTO gallery_photos (folder_id, title, image_url, uploaded_at)
SELECT f.id, g.title, g.image_url, g.uploaded_at
FROM gallery AS g
INNER JOIN gallery_folders AS f ON f.folder_name = 'Imported Gallery'
WHERE NOT EXISTS (
    SELECT 1
    FROM gallery_photos AS p
    WHERE p.folder_id = f.id
      AND p.image_url = g.image_url
      AND p.title <=> g.title
);
