---
title: "LLM Wiki — Karpathy Pattern"
type: source
tags: [knowledge-management, llm, obsidian, methodology]
created: 2026-06-29
url: https://gist.github.com/karpathy
author: Andrej Karpathy
---

# LLM Wiki — Karpathy Pattern

## Summary

A pattern for using LLMs to build and maintain a persistent personal wiki. The key distinction from RAG: instead of re-deriving answers from raw documents at query time, the LLM incrementally compiles knowledge into a structured, interlinked wiki that gets richer with every source added. Knowledge accumulates; it isn't rediscovered.

## Key points

- **Wiki vs RAG**: RAG re-derives from raw docs every query. This pattern compiles knowledge once into a persistent wiki that stays maintained.
- **Three layers**: raw sources (immutable), the wiki (LLM-owned markdown files), and the schema (CLAUDE.md / AGENTS.md telling the LLM how to operate).
- **Three operations**: ingest (process a new source, touch 10–15 pages), query (answer against the wiki, file good answers back), lint (health-check for orphans, contradictions, stale claims).
- **index.md** is content-oriented — a catalog the LLM reads first to find relevant pages before drilling in.
- **log.md** is chronological — append-only record of ingests/queries/lints, parseable with `grep "^## \["`.
- **Good query answers get filed back** into the wiki as analysis pages — explorations compound, not just sources.
- **LLM as programmer, Obsidian as IDE, wiki as codebase** — you browse in real time as the LLM edits.
- Optional tooling: Obsidian Web Clipper (clip to markdown), Dataview (frontmatter queries), Marp (slide decks), qmd (local hybrid BM25/vector search CLI + MCP server for larger wikis).
- The wiki is a git repo — version history, branching, collaboration for free.

## Concepts mentioned

- [[wiki/concepts/llm-wiki-pattern|LLM Wiki Pattern]]
- [[wiki/concepts/rag-vs-wiki|RAG vs Persistent Wiki]]

## Contradictions / open questions

- LLMs can't natively read markdown with inline images in one pass — workaround is read text first, then view images separately (noted as "a bit clunky").
- Index file approach scales to ~100 sources / ~hundreds of pages before needing proper search (qmd or similar).
