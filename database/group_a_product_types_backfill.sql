-- Historical pre-CONTRACT script: do not run after product_types is removed.
-- Group A EXPAND: additional rehearsal/backfill script.
-- Schema source: gjc_ensure_merchant_application_product_types_schema()
-- in connection/app.php. Invoke it before running this script.
-- Recheck consistency and the 120-character token limit first.
-- Keep existing dual-write behavior throughout verification.

START TRANSACTION;

INSERT INTO merchant_application_product_types
    (merchant_application_id, product_type)
SELECT DISTINCT a.id, TRIM(j.product_type)
FROM merchant_applications a
JOIN JSON_TABLE(
    CONCAT('[', REPLACE(JSON_QUOTE(a.product_types), ',', '","'), ']'),
    '$[*]' COLUMNS (product_type TEXT PATH '$')
) AS j ON TRUE
WHERE TRIM(j.product_type) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM merchant_application_product_types p
      WHERE p.merchant_application_id = a.id
        AND p.product_type = TRIM(j.product_type)
  );

SELECT ROW_COUNT() AS inserted_rows;

COMMIT;
