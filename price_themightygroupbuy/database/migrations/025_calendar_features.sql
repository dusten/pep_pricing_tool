-- Backlog #18: admin-picked featured product for the public price calendar.
-- One product (optionally a specific spec) featured per date; the public
-- calendar reveals that product's full detail (vendor, exact price, delta)
-- for that day, while everything else stays teased.
CREATE TABLE IF NOT EXISTS pc_calendar_features (
  feature_date     DATE         NOT NULL PRIMARY KEY, -- one featured product per day
  product_id       INT UNSIGNED NOT NULL,
  specification_id INT UNSIGNED NULL,                 -- optional: pin a specific spec, else cheapest is shown
  note             VARCHAR(200) NULL,                 -- optional admin blurb shown with the feature
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id)       REFERENCES pc_products(id)       ON DELETE CASCADE,
  FOREIGN KEY (specification_id) REFERENCES pc_specifications(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by)       REFERENCES pc_users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
