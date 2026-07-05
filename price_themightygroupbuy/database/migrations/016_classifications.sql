-- =============================================================
-- 016_classifications.sql
-- Backlog #16, phase 1: replaces pc_products.category (single-select,
-- six fixed values) with a proper multi-select tag system. The category
-- column itself is left in place for now and dropped in a later, separate
-- migration once the code cutover is confirmed working live (clean
-- rollback point) — see wiki analysis 2026-07-03-shopping-cart-and-
-- classification-spec.md.
--
-- Backfill below is name-matched against pc_products.canonical_name, not
-- hardcoded IDs, so this is a safe no-op on a fresh/empty install.
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_classifications (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_product_classifications (
  product_id        INT UNSIGNED NOT NULL,
  classification_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, classification_id),
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (classification_id) REFERENCES pc_classifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pc_classifications (name) VALUES
  ('Mitochondrial'), ('Weight Management'), ('Fat Loss'), ('Healing & Recovery'), ('Bioregulator'),
  ('Growth Hormone'), ('Anti-Aging'), ('Skin & Hair'), ('Sleep & Recovery'), ('Sexual Health'),
  ('Cognitive'), ('Cosmetic'), ('Neuroprotective'), ('Clinical'), ('Hormone Support'),
  ('Antimicrobial'), ('Immune'), ('Growth Factors'), ('Stack'), ('Lab Supplies'),
  ('GLP / Metabolic'), ('Repair / Healing'), ('Neuro / Mood'), ('Social / Sexual'), ('Longevity'),
  ('GH Secretagogue (Non-HGH)'), ('Metabolic & Performance Support');

-- Backfill, one INSERT per tag, grouped for readability. Products not
-- listed here (and any future product) simply start unclassified until an
-- admin tags them via the new UI.

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Mitochondrial' AND p.canonical_name IN ('5-Amino-1MQ', 'MOTS-c', 'SS-31', 'AICAR');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Weight Management' AND p.canonical_name IN
  ('Adipotide', 'Adipotide/FTPP', 'FTPP Adipotide', 'Cagrilintide', 'Cagrilintide + Semaglutide', 'Retatrutide', 'Semaglutide', 'Tirzepatide');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Fat Loss' AND p.canonical_name IN ('AOD-9604', 'AOD9604', 'AICAR');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Healing & Recovery' AND p.canonical_name IN
  ('BPC-157', 'BPC+TB', 'Gonadorelin Acetate', 'KLOW', 'KPV', 'LL-37', 'PEG-MGF', 'TB-500', 'Thymosin Alpha-1', 'VIP');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Growth Hormone' AND p.canonical_name IN
  ('CJC-1295 with DAC', 'CJC-1295 without DAC', 'CJC-1295 without DAC + Ipamorelin',
   'GHRP-2 Acetate', 'GHRP-6 Acetate', 'Hexarelin Acetate', 'Ipamorelin', 'MK-677', 'Sermorelin Acetate', 'Tesamorelin');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Anti-Aging' AND p.canonical_name IN ('Epithalon', 'FOX04', 'FOXO4-DRI', 'Glutathione', 'NAD+', 'Thymalin');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Skin & Hair' AND p.canonical_name IN ('GHK-Cu', 'Melanotan 2', 'AHK-CU');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Sleep & Recovery' AND p.canonical_name IN ('DSIP', 'Melatonin');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Sexual Health' AND p.canonical_name IN ('Kisspeptin-10', 'Oxytocin Acetate', 'PT-141');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Cognitive' AND p.canonical_name IN ('Cerebrolysin', 'Pinealon', 'Selank', 'Semax');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Cosmetic' AND p.canonical_name IN
  ('AHK-CU', 'Botulinum toxin', 'GHK-Cu', 'Melanotan 1', 'Melanotan 2', 'Snap-8');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Neuroprotective' AND p.canonical_name IN ('Dihexa');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Clinical' AND p.canonical_name IN ('Melanotan 1', 'PNC-27');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Hormone Support' AND p.canonical_name IN ('HMG');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Growth Factors' AND p.canonical_name IN ('ACE-031', 'IGF-1 LR3');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Stack' AND p.canonical_name IN ('BPC+TB', 'Cagrilintide + Semaglutide', 'GLOW', 'KLOW');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Lab Supplies' AND p.canonical_name IN ('Acetic acid', 'Bacteriostatic Water');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'GLP / Metabolic' AND p.canonical_name IN
  ('5-Amino-1MQ', 'Cagrilintide', 'Retatrutide', 'Semaglutide', 'Tirzepatide');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Repair / Healing' AND p.canonical_name IN
  ('Ara-290 (Cibinetide)', 'BPC+TB', 'BPC-157', 'GLOW', 'KLOW', 'KPV', 'LL-37', 'TB-500');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Neuro / Mood' AND p.canonical_name IN
  ('Adamax', 'DSIP', 'Epithalon', 'Melatonin', 'PE 22-28', 'Selank', 'Semax', 'VIP');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Social / Sexual' AND p.canonical_name IN ('Kisspeptin-10', 'Oxytocin Acetate', 'PT-141');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Longevity' AND p.canonical_name IN
  ('Glutathione', 'IGF-1 LR3', 'MOTS-c', 'NAD+', 'SS-31', 'Thymalin');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'GH Secretagogue (Non-HGH)' AND p.canonical_name IN
  ('AOD-9604', 'CJC-1295 without DAC + Ipamorelin', 'Ipamorelin', 'Sermorelin Acetate', 'Tesamorelin');

INSERT IGNORE INTO pc_product_classifications (product_id, classification_id)
SELECT p.id, c.id FROM pc_products p, pc_classifications c
WHERE c.name = 'Metabolic & Performance Support' AND p.canonical_name IN
  ('B12', 'L-carnitine', 'Lipo-c', 'Lipo-C with B12', 'Lipo-C without B12', 'MIC(lipo C with B12)');
