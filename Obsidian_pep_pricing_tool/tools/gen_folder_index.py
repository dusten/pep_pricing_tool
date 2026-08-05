#!/usr/bin/env python3
"""Generate a linked, self-summarizing index note for every editable folder.

Each `<folder>-index.md` gets:
  - a **breadcrumb** linking up to `index.md` and every ancestor index (so the tree
    is navigable both ways, not just parent->child);
  - a **live stats line** (file/subfolder counts + top frontmatter tags) regenerated
    every run;
  - a **curated summary** — a one-line "what's in here / when to read it" blurb kept
    between `<!-- curated:start/end -->` markers and PRESERVED across regenerations
    (edit it once by hand; the generator won't clobber it);
  - a **Subfolders** list where each child link carries the child's curated summary
    (so you can see what a subtree holds without opening it);
  - a **Files** table (one row per md file, description from its frontmatter
    `description:` -> first `# H1` -> first non-blank line).

The point: an agent can navigate the whole dataset from these notes without
re-scanning every file. Skips `raw/` (immutable — see `raw-overview.md`),
`.obsidian`, `.git`, and the vault root (served by `index.md`).

Usage:  python tools/gen_folder_index.py [vault_dir]   (default: current dir)
"""
import os, re, sys, collections

VAULT = sys.argv[1] if len(sys.argv) > 1 else "."
SKIP_DIRS = {"raw", ".obsidian", ".git", ".claude", "memory-wiki-kit"}
FM = re.compile(r'^---\n(.*?)\n---\n', re.S)
CURATED = re.compile(r'<!-- curated:start -->\n(.*?)\n<!-- curated:end -->', re.S)


def read(p):
    try:
        return open(p, encoding="utf-8", errors="replace").read()
    except Exception:
        return ""


def clean(s, n=140):
    s = re.sub(r'[*_`#>\[\]]', '', s).strip()
    s = re.sub(r'\s+', ' ', s)
    return (s[:n] + "…") if len(s) > n else s


def describe(path):
    text = read(path)
    m = re.search(r'^description:\s*(.+)$', text, re.M)
    if m and m.group(1).strip():
        return clean(m.group(1))
    body = FM.sub('', text)
    h = re.search(r'^#\s+(.+)$', body, re.M)
    if h:
        return clean(h.group(1))
    for line in body.splitlines():
        if line.strip():
            return clean(line)
    return ""


def tags_of(path):
    m = FM.search(read(path))
    if not m:
        return []
    fm = m.group(1)
    t = re.search(r'^tags:\s*\[(.*?)\]', fm, re.M)
    if t:
        return [x.strip().strip('"\'') for x in t.group(1).split(",") if x.strip()]
    # multiline `tags:\n  - a\n  - b`
    m2 = re.search(r'^tags:\s*\n((?:\s*-\s*.+\n?)+)', fm, re.M)
    if m2:
        return [re.sub(r'^\s*-\s*', '', l).strip().strip('"\'')
                for l in m2.group(1).splitlines() if l.strip()]
    return []


def index_name(folder):
    return f"{os.path.basename(folder.rstrip('/'))}-index.md"


def breadcrumb(folder):
    rel = os.path.relpath(folder, VAULT)
    parts = rel.split(os.sep)
    chain, cur = ["[[index]]"], VAULT
    for p in parts:
        cur = os.path.join(cur, p)
        chain.append(f"[[{index_name(cur)[:-3]}]]")
    chain[-1] = f"**{parts[-1]}/**"
    return " / ".join(chain)


def curated_of(path, default):
    """Preserve a hand-edited curated blurb across regenerations; seed with default."""
    if os.path.exists(path):
        m = CURATED.search(read(path))
        if m and m.group(1).strip():
            return m.group(1).strip()
    return default


def collect(folder):
    idx = index_name(folder)
    files = sorted(f for f in os.listdir(folder)
                   if f.endswith(".md") and f != idx
                   and os.path.isfile(os.path.join(folder, f)))
    subs = sorted(os.path.join(folder, d) for d in os.listdir(folder)
                  if os.path.isdir(os.path.join(folder, d)) and d not in SKIP_DIRS)
    tagc = collections.Counter()
    descs = {}
    for f in files:
        p = os.path.join(folder, f)
        descs[f] = describe(p)
        tagc.update(tags_of(p))
    return files, subs, descs, tagc


def stats_line(nfiles, nsub, tagc):
    bits = [f"**{nfiles}** page{'s' if nfiles != 1 else ''}"]
    if nsub:
        bits.append(f"**{nsub}** subfolder{'s' if nsub != 1 else ''}")
    line = " · ".join(bits)
    top = [t for t, _ in tagc.most_common(6)]
    if top:
        line += " · top tags: " + ", ".join(top)
    return line


def main():
    # Pass 1: gather everything so a parent can show its children's summaries.
    data = {}
    for root, dirs, _ in os.walk(VAULT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        if os.path.relpath(root, VAULT) == ".":
            continue
        files, subs, descs, tagc = collect(root)
        name = os.path.basename(root.rstrip('/'))
        default = f"{name}/ — {len(files)} page(s)" + (f", {len(subs)} subfolder(s)" if subs else "") + \
                  (". Top tags: " + ", ".join(t for t, _ in tagc.most_common(4)) if tagc else "") + \
                  ".  *(edit this line to describe what's here and when to read it)*"
        curated = curated_of(os.path.join(root, index_name(root)), default)
        data[root] = dict(files=files, subs=subs, descs=descs, tagc=tagc, curated=curated)

    # Pass 2: write.
    for root, d in data.items():
        name = os.path.basename(root.rstrip('/'))
        lines = [
            "---", "type: folder-index", "tags: [folder-index, nav]", "---", "",
            f"# {name}/ — Folder Index", "",
            breadcrumb(root), "",
            "## Summary",
            stats_line(len(d["files"]), len(d["subs"]), d["tagc"]), "",
            "<!-- curated:start -->", d["curated"], "<!-- curated:end -->", "",
        ]
        if d["subs"]:
            lines.append("## Subfolders")
            for sub in d["subs"]:
                child = index_name(sub)[:-3]
                csum = data.get(sub, {}).get("curated", "").splitlines()[0] if data.get(sub) else ""
                # strip the seed suffix from an unedited child summary
                csum = csum.replace("  *(edit this line to describe what's here and when to read it)*", "")
                lines.append(f"- [[{child}]] — {csum}".rstrip(" —"))
            lines.append("")
        lines.append(f"## Files ({len(d['files'])})")
        if d["files"]:
            lines += ["| File | Description |", "|---|---|"]
            for f in d["files"]:
                lines.append(f"| [[{f[:-3]}]] | {d['descs'][f].replace('|', chr(92)+'|')} |")
        else:
            lines.append("*(no markdown files directly in this folder)*")
        lines.append("")
        open(os.path.join(root, index_name(root)), "w", encoding="utf-8").write("\n".join(lines))

    gen_raw_overview(VAULT)
    print(f"Wrote {len(data) + 1} index files (incl. raw-overview).")


def gen_raw_overview(vault):
    raw = os.path.join(vault, "raw")
    if not os.path.isdir(raw):
        return
    out = os.path.join(vault, "raw-overview.md")
    curated = curated_of(out, "The immutable `raw/` source tree — original clippings, "
                          "transcripts, exports, and customer notes. Read a `wiki/` page "
                          "instead of these unless you need the primary source.")
    lines = [
        "---", "type: folder-index", "tags: [folder-index, nav, raw]", "---", "",
        "# raw/ — Source Tree Overview", "",
        "[[index]] / **raw/**", "",
        "## Summary",
        "<!-- curated:start -->", curated, "<!-- curated:end -->", "",
        "*`raw/` is immutable — no index files are written inside it; this is its map. "
        "Auto-generated by `tools/gen_folder_index.py`.*", "",
        "| Folder | Files (by type) |", "|---|---|",
    ]
    for root, dirs, fnames in os.walk(raw):
        dirs.sort()
        depth = os.path.relpath(root, vault).count(os.sep) - 1
        exts = collections.Counter(os.path.splitext(f)[1].lower() or "—" for f in fnames)
        breakdown = ", ".join(f"{n} {e}" for e, n in sorted(exts.items())) or "—"
        indent = "&nbsp;&nbsp;&nbsp;&nbsp;" * depth
        lines.append(f"| {indent}`{os.path.basename(root)}/` | {breakdown} |")
    lines.append("")
    open(out, "w", encoding="utf-8").write("\n".join(lines))


if __name__ == "__main__":
    main()
