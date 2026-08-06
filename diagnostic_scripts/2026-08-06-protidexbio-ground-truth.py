#!/usr/bin/env python3
"""
2026-08-06-protidexbio-ground-truth.py

Local Python forensic scripts (not server PHP — no PDF/vision tooling exists
server-side, this ran on the local machine against a downloaded copy of the
vendor file) used to investigate a reported price/name misalignment bug in
the review queue for vendor "protidexbio peptide LTD Factory", file
"Faye's latest price list.pdf" (pc_vendor_files.id 90, pc_claude_call_log.id 110).

User reported: the pending-review card for "IGF-1" showed price $64, but the
source file shows $64 belongs to MOTS-c and IGF-1 is actually $220.

GROUND_TRUTH below is a hand-transcription of every row in the source PDF
(number, sku_name, group_name/real product name, spec, price), done by
rendering the PDF to a high-res PNG (`pdftoppm -r 200`) and reading it in
overlapping ~750px vertical strips to avoid misreading merged/rowspan name
cells. Cross-referenced against every one of the 123 pc_pending_imports rows
this file produced (ids 4003-4125) and the 88 that had already been approved,
to find every case where the committed/decided price or product diverged
from the real source document — not just the one row the user spotted.

Findings (see the full session for the complete list):
- Extraction used the vendor's unlabeled SKU-code column as canonical_name
  for most rows instead of the separately-present "name" column — e.g. rows
  1-5 came out as canonical_name "SM5".."SM30" (approved onto Sermorelin
  Acetate, matching a stale/wrong name_mismatch suggestion) when the source's
  own "name" column says these are Semaglutide.
- A genuine row-alignment slip in one merged-name block (CD5 through G610,
  ~18 source rows) duplicated one row (a phantom "CD10" that doesn't exist
  in the source) and dropped another (PT-141), which nets out to same row
  count but shifts every price in between by one row relative to its real
  name — this is the exact bug the user caught via IGF-1/MOTS-c.
- A handful of other isolated 1-2 row swaps elsewhere (2S10/2S50, OT5/HX2).

Root cause and prevention plan discussed in the session's chat log, not
duplicated here — see the extraction prompt at
backend/lib/claude.php:buildExtractionSystemPrompt() rule 7 and rule 9.
"""

# (row_number_in_source_pdf, vendor_sku_code, real_product_name_or_group, spec_label, price_usd)
GROUND_TRUTH = [
(1,'SM5','Semaglutide','5mg',40),(2,'SM10','Semaglutide','10mg',50),(3,'SM15','Semaglutide','15mg',60),
(4,'SM20','Semaglutide','20mg',80),(5,'SM30','Semaglutide','30mg',110),
(6,'TR5','Tirzepatide','5mg',40),(7,'TR10','Tirzepatide','10mg',55),(8,'TR15','Tirzepatide','15mg',75),
(9,'TR20','Tirzepatide','20mg',95),(10,'TR30','Tirzepatide','30mg',110),(11,'TR40','Tirzepatide','40mg',130),
(12,'TR40(Pen)','Tirzepatide','40mg(pen)',40),(13,'TR50','Tirzepatide','50mg',150),(14,'TR60','Tirzepatide','60mg',170),
(15,'TR100','Tirzepatide','100mg(5ml)',250),(16,'TR120','Tirzepatide','120mg(5ml)',280),
(17,'RT5','Retatrutide','5mg',60),(18,'RT10','Retatrutide','10mg',90),(19,'RT15','Retatrutide','15mg',120),
(20,'RT20','Retatrutide','20mg',150),(21,'RT30','Retatrutide','30mg',190),(22,'RT40','Retatrutide','40mg',230),
(23,'RT40(Pen)','Retatrutide','40mg(pen)',40),(24,'RT50','Retatrutide','50mg',270),(25,'RT60','Retatrutide','60mg',300),
(26,'ELO5','Eloralintide','5mg',160),(27,'ELO10','Eloralintide','10mg',280),
(28,'H10','HGH','10iu',60),(29,'H12','HGH','12iu',70),(30,'H15','HGH','15iu',80),(31,'H24','HGH','24iu',120),(32,'H36','HGH','36iu',195),
(33,'ADA10','Adamax','10mg',130),
(34,'BPC5','BPC-157','5mg',50),(35,'BPC10','BPC-157','10mg',70),(36,'BPC20','BPC-157','20mg',110),
(37,'TB5','TB500','5mg',70),(38,'TB10','TB500','10mg',120),
(39,'BB10','BPC 5mg + TB 5mg','10mg',100),(40,'BB20','BPC 10mg + TB 10mg','20mg',190),
(41,'2S10','SS-31','10mg',97),(43,'2S50','SS-31','50mg',417),
(44,'CND5','CJC-1295 without DAC','5mg',83),(45,'CND10','CJC-1295 without DAC','10mg',167),
(46,'CP10','CJC-1295 without DAC 5mg + IPA 5mg','10mg',115),
(47,'CD5','CJC-1295 with DAC','5mg',167),
(48,'AOD5','AOD9604','5mg',90),(49,'AOD10','AOD9604','10mg',160),
(50,'IGF-1','IGF-1','1mg',220),
(51,'MS10','MOTS-c','10mg',64),(52,'MS40','MOTS-c','40mg',200),
(53,'MT1','MT-1','10mg',48),(54,'MT2','MT-2 (Melanotan 2 Acetate)','10mg',48),
(55,'ET5','Epithalon','5mg',39),(56,'ET10','Epithalon','10mg',60),(57,'ET50','Epithalon','50mg',167),
(58,'PE-8','Pe-22-28','10mg',70),
(59,'Car20','Cartalax','20mg',110),
(60,'G25','GHRP-2 Acetate','5mg',29),(61,'G210','GHRP-2 Acetate','10mg',50),
(62,'G65','GHRP-6 Acetate','5mg',29),(63,'G610','GHRP-6 Acetate','10mg',50),
(64,'PT41','PT-141','10mg',66),
(65,'SK5','Selank','5mg',50),(66,'SK10','Selank','10mg',80),
(67,'XA5','Semax','5mg',50),(68,'XA10','Semax','10mg',80),
(69,'KPV10','KPV','10mg',75),(70,'KPV50','KPV','50mg',210),
(71,'VIP','VIP','5mg',90),
(72,'Tesa5','Tesamorelin','5mg',95),(73,'Tesa10','Tesamorelin','10mg',180),(74,'Tesa20','Tesamorelin','20mg',345),
(75,'Tesa10+IPA3','Tesamorelin 10mg+Ipamorelin 3mg','13mg',215),(76,'Tesa10+IPA10','Tesamorelin 10mg+Ipamorelin 10mg','20mg',250),
(77,'SMO5','Sermorelin Acetate','5mg',78),(78,'SMO10','Sermorelin Acetate','10mg',167),
(79,'TA5','Thymosin Alpha-1','5mg',80),(80,'TA10','Thymosin Alpha-1','10mg',150),
(81,'IP5','Ipamorelin','5mg',45),(82,'IP10','Ipamorelin','10mg',75),
(83,'CGL5','Cagrilintide','5mg',100),(84,'CGL10','Cagrilintide','10mg',180),
(85,'NJ100','NAD+','100mg',40),(86,'NJ500','NAD+','500mg',80),(87,'NJ1000','NAD+','1000mg',110),
(88,'CU50','GHK-CU','50mg',33),(89,'CU100','GHK-CU','100mg',56),
(90,'AHK50','AHK-CU','50mg',50),
(91,'BBG70','BPC 157 10mg+GHK-CU 50mg+TB500 10mg (GLOW)','70mg',190),
(92,'BBGK80','BPC 157 10mg+GHK-CU 50mg+TB500 10mg+KPV 10mg (KLOW)','80mg',220),
(93,'KS5','KissPeptin-10','5mg',55),(94,'KS10','KissPeptin-10','10mg',88),
(95,'DS5','DSIP','5mg',47),(96,'DS10','DSIP','10mg',90),
(98,'OT5','Oxytocin Acetate','5mg',60),
(99,'HX2','Hexarelin Acetate','2mg',42),(100,'HX5','Hexarelin Acetate','5mg',92),
(101,'FMP2','PEG MGF','2mg',83),
(102,'G15K','HCG','15000IU',150),(103,'G10K','HCG','10000IU',130),(104,'G5K','HCG','5000IU',83),
(105,'IGD','IGF-DES','2mg',56),
(106,'FR5','HGH Fragment 176-191','5mg',97),
(107,'LL375','LL37','5mg',99),
(108,'TY10','Thymalin','10mg',64),
(109,'GT600','Glutathione','600mg',60),(110,'GT1500','Glutathione','1500mg',80),
(111,'MT10','melatonin','10mg',56),
(112,'GON5','Gonadorelin','5mg',75),(113,'GON10','Gonadorelin','10mg',105),
(114,'5AM5 (5AM)','5-amino-1mq','5mg',50),(115,'5AM10(10AM)','5-amino-1mq','10mg',70),(116,'5AM50(50AM)','5-amino-1mq','50mg',90),
(117,'G75a','HMG (Menotropins for Injection)','75IU(6vials)',50),(118,'G75b','HMG (Menotropins for Injection)','75IU(10vials)',70),
(119,'NP810','snap-8','10mg',50),
(120,'RA10','Ara-290','10mg',88),
(121,'AE1','ACE-031','1mg',306),
(122,'AR50','AICAR','50mg',67),
(123,'CERE','cerebrolysin','60mg(6vials)',68),
(130,'LB','Lemon Bottle','10ml',88),
(131,'CU R','GHK CU RAW','1g',35),
(132,'AHK R','AHK CU RAW','1g',60),
(133,'AA','Acetic acid 0.6% water','3ml',18),
(134,'BAC10','BAC water','10ml',15),
(135,'BAC3','BAC water','3ml',10),
]

if __name__ == '__main__':
    print(f"{len(GROUND_TRUTH)} ground-truth rows transcribed from the source PDF.")
