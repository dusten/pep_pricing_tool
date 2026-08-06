#!/usr/bin/env python3
"""
2026-08-06-protidexbio-review-queue-decisions.py

Local Python script for the initial (pre-bug-discovery) triage pass on the
123-item review queue backlog created by vendor "protidexbio peptide LTD
Factory" uploading "Faye's latest price list.pdf" — see this project's
CLAUDE.md session-start Code Map and Obsidian_pep_pricing_tool/log.md for
the full narrative.

Reads a TSV dump of `pc_pending_imports` (id, match_type, candidate_product_id,
candidate_name, raw_json) for this file's 123 rows, and classifies each into
APPROVE (map onto an existing catalog product_id) or UNSURE (leave pending)
based on: an explicit warning naming the real compound, a system-suggested
name_mismatch candidate that's internally consistent, direct corroboration
against real vendor_sku data already verified in the catalog, or a
distinctive single-match domain pattern (BPC-157, IU-dosed HGH/HCG/HMG).

IMPORTANT — this pass was later found to have two classes of mistakes, fixed
in a follow-up pass once the source PDF was directly transcribed (see
2026-08-06-protidexbio-ground-truth.py and the "off-by-one" bug the user
caught): (1) SM5-SM30 approved onto Sermorelin Acetate when the vendor's own
"name" column says Semaglutide — a stale/wrong automated name_mismatch
suggestion was trusted without a second source of corroboration; (2) several
approved-with-wrong-price rows where the extraction's own price/name pairing
was internally shifted within one merged-cell table block (CD5 through
G610), which this pass's "trust the raw extracted price" approach couldn't
catch without the source document. Kept here as the accurate historical
record of what this first pass actually decided and why, not as a template
to reuse uncritically — the lesson is in the ground-truth script + prompt
fix, not in loosening this pass's confidence bar.

Expects pending_protidex.tsv (a `SELECT id, match_type, candidate_product_id,
candidate_name, raw_json` dump, tab-separated) in the same directory when run
standalone; not needed to re-run this, kept for the record only.
"""
import json

with open('pending_protidex.tsv') as f:
    lines = f.readlines()

rows = {}
for line in lines:
    parts = line.rstrip('\n').split('\t')
    pid, match_type, cand_id, cand_name, raw = parts
    d = json.loads(raw)
    rows[int(pid)] = {'match_type': match_type, 'cand_name': cand_name, **d}

# name -> product_id, for confident approvals (see module docstring for caveats)
APPROVE = {
    'SM5': 42, 'SM10': 42, 'SM15': 42, 'SM20': 42, 'SM30': 42,          # WRONG — real answer is Semaglutide (3), see ground-truth script
    'TR5': 41, 'TR10': 41, 'TR15': 41, 'TR20': 41, 'TR30': 41,
    'TR40': 41, 'TR40(Pen)': 41, 'TR50': 41, 'TR60': 41,                # Tirzepatide
    'AOD5': 95, 'AOD10': 95,                                            # AOD-9604 (price wrong on both, see ground-truth)
    'BPC5': 1, 'BPC10': 1, 'BPC20': 1,                                   # BPC-157
    'TB5': 2, 'TB10': 2,                                                 # TB-500 (defaults to full per wiki)
    'BB10': 51, 'BB20': 51,                                              # BPC+TB combo
    'CD5': 59, 'CD10': 59,                                               # CJC-1295 with DAC — CD10 is a phantom row, doesn't exist in source
    'CND5': 57, 'CND10': 57,                                             # CJC-1295 without DAC
    'CPD10': 58,                                                        # CJC-1295 w/o DAC + Ipamorelin combo
    'MT1': 93, 'MT2': 94,                                                # Melanotan 1 / 2 (MT2 price wrong, see ground-truth)
    'ET5': 23, 'ET10': 23, 'ET50': 23,                                   # Epithalon (ET5/ET10 price wrong, see ground-truth)
    'G15K': 73, 'G10K': 73, 'G5K': 73,                                   # HCG
    'G75': 66,                                                          # HMG
    'H10': 106, 'H12': 106, 'H15': 106, 'H24': 106, 'H36': 106,          # HGH
    'AHK50': 35,                                                        # AHK-CU (price wrong, see ground-truth)
    'AHK R': 516,                                                       # AHK CU RAW (separate catalog product)
    'CU50': 29, 'CU100': 29,                                            # GHK-Cu
    'CU R': 515,                                                        # GHK CU RAW (separate catalog product)
    'RT5': 40, 'RT10': 40, 'RT15': 40, 'RT20': 40, 'RT30': 40,
    'RT40': 40, 'RT40(Pen)': 40, 'RT50': 40, 'RT60': 40,                 # Retatrutide
    'NJ100': 4, 'NJ500': 4, 'NJ1000': 4,                                 # NAD+
    'KS5': 72, 'KS10': 72,                                               # Kisspeptin-10
    'TA5': 74, 'TA10': 74,                                               # Thymosin Alpha-1
    'TY10': 82,                                                         # Thymalin
    'GT600': 30, 'GT1500': 30,                                          # Glutathione (GT600 price wrong, see ground-truth)
    'KPV10': 44, 'KPV50': 44,                                           # KPV (already literal)
    'SAM5 (5AM)': 26, 'SAM10(10AM)': 26, 'SAM50(50AM)': 26,              # 5-Amino-1MQ
    'Tesa5': 48, 'Tesa10': 48, 'Tesa20': 48,                             # Tesamorelin
    'Tesa10+IPA3': 312, 'Tesa10+IPA10': 312,                            # Tesamorelin + Ipamorelin combo
    'BBG70': 31,                                                        # GLOW
    'BBGK80': 32,                                                       # KLOW
    'AA': 64,                                                           # Acetic acid
    'BAC10': 65, 'BAC3': 65,                                            # Bacteriostatic Water
    'LB': 13,                                                           # Lemon Bottle
    'GON5': 96, 'GON10': 96,                                            # Gonadorelin Acetate
    'ELO5': 400, 'ELO10': 400,                                          # Eloralintide
    'DS5': 21, 'DS10': 21,                                              # DSIP
}

decided_ids = set()
decisions = {}
for pid, r in rows.items():
    name = r['canonical_name']
    decisions[pid] = ('approve', APPROVE[name]) if name in APPROVE else ('unsure', name)
    decided_ids.add(pid)

assert decided_ids == set(rows.keys())

if __name__ == '__main__':
    approved = {k: v for k, v in decisions.items() if v[0] == 'approve'}
    unsure   = {k: v for k, v in decisions.items() if v[0] == 'unsure'}
    print(f"APPROVE: {len(approved)}  UNSURE: {len(unsure)}  TOTAL: {len(decisions)}")
