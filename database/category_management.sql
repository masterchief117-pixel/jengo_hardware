-- The categories table already exists in schema.sql with the required slug
-- column and product foreign key. Add the requested starter categories without
-- duplicating categories already present in an existing database.
INSERT IGNORE INTO categories (name, slug)
SELECT 'Cement & Sand', 'cement-sand'
WHERE NOT EXISTS (
    SELECT 1 FROM categories WHERE name = 'Cement & Sand'
);

INSERT IGNORE INTO categories (name, slug)
SELECT 'Timber & Boards', 'timber-boards'
WHERE NOT EXISTS (
    SELECT 1 FROM categories WHERE name = 'Timber & Boards'
);

INSERT IGNORE INTO categories (name, slug)
SELECT 'Roofing & Mabati', 'roofing-mabati'
WHERE NOT EXISTS (
    SELECT 1 FROM categories WHERE name = 'Roofing & Mabati'
);

INSERT IGNORE INTO categories (name, slug)
SELECT 'Plumbing', 'plumbing'
WHERE NOT EXISTS (
    SELECT 1 FROM categories WHERE name = 'Plumbing'
);

INSERT IGNORE INTO categories (name, slug)
SELECT 'Tools & Hardware', 'tools-hardware'
WHERE NOT EXISTS (
    SELECT 1 FROM categories WHERE name = 'Tools & Hardware'
);
