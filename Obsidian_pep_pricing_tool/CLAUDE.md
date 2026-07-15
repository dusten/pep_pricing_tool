# Wiki Schema — pep_pricing_tool Knowledge Base

Personal knowledge base following the [[karpathy-llm-wiki-pattern]]. Domain: peptide vendors, pricing, research, and the pep_pricing_tool project.

## Directory layout

```
raw/          ← immutable source documents (you drop files here, LLM never modifies)
  assets/     ← locally downloaded images from clipped articles
wiki/
  sources/    ← one summary page per ingested source
  entities/   ← vendors, peptides, compounds, people
  concepts/   ← pricing models, research topics, domain ideas
  analyses/   ← filed query answers, comparisons, investigations
  _templates/ ← page templates (not linked in index)
plans/        ← implementation plans from plan-mode sessions (symlinked from ~/.claude/plans/)
index.md      ← catalog of all wiki pages with one-line summaries
log.md        ← append-only record of ingests, queries, lint passes
CLAUDE.md     ← this file
```

## Page frontmatter

Every wiki page (not templates) starts with:

```yaml
---
title: Page Title
type: source | entity | concept | analysis
tags: []
created: YYYY-MM-DD
sources: []   # for entity/concept pages: list of source slugs that informed this page
---
```

## Naming conventions

- Files: `kebab-case.md`
- Source pages: named after the source slug (e.g. `karpathy-llm-wiki-pattern.md`)
- Entity pages: the entity's canonical name (e.g. `peptide-sciences.md`)
- Concept pages: the concept name (e.g. `tiered-pricing.md`)
- Analysis pages: `YYYY-MM-DD-topic-slug.md`

## Linking

- Use `[[wiki/page-name|Display Text]]` for cross-references between wiki pages
- Use `[[raw/filename]]` to reference a source document
- Always link entity and concept names on first mention in a page

## Workflows

### Ingest a new source

1. Read the source file in `raw/`
2. Discuss key takeaways with the user if interactive; otherwise proceed
3. Create `wiki/sources/<slug>.md` using the source template
4. Update or create entity pages in `wiki/entities/` for any vendors, peptides, or people mentioned
5. Update or create concept pages in `wiki/concepts/` for domain ideas
6. Note contradictions with existing pages explicitly (use `> [!warning] Contradicts [[page]]` callout)
7. Update `index.md` — add the new source page and any new entity/concept pages
8. Append an entry to `log.md`: `## [YYYY-MM-DD] ingest | Source Title`

### Answer a query

1. Read `index.md` to find relevant pages
2. Read those pages and synthesize an answer
3. If the answer is worth keeping, file it as `wiki/analyses/YYYY-MM-DD-topic.md`
4. Update `index.md` and append to `log.md`: `## [YYYY-MM-DD] query | Question summary`

### Lint the wiki

1. Scan all pages for: orphan pages (no inbound links), stale claims flagged with newer sources, important concepts mentioned but lacking their own page, missing cross-references
2. Report findings and ask user which to fix
3. Append to `log.md`: `## [YYYY-MM-DD] lint | N issues found`

## Obsidian tips

- Graph view shows orphans and hubs
- Dataview queries work on frontmatter if the plugin is installed
- `raw/assets/` is the attachment folder — bind Ctrl+Shift+D to "Download attachments for current file"
