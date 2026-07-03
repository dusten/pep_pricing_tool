---
title: RAG vs Persistent Wiki
type: concept
tags: [knowledge-management, llm, rag]
created: 2026-06-29
sources: [karpathy-llm-wiki-pattern]
---

# RAG vs Persistent Wiki

## Definition

**RAG (Retrieval-Augmented Generation)**: Upload documents, LLM retrieves relevant chunks at query time and generates an answer. No accumulation — knowledge is re-derived from scratch every query.

**Persistent Wiki**: LLM compiles knowledge from sources into an interlinked wiki once. Subsequent queries read the already-synthesized wiki. Contradictions are pre-flagged, cross-references pre-built.

## Why it matters

Subtle questions requiring synthesis of five documents require the LLM to find and piece together fragments every time in RAG. In the wiki pattern, that synthesis already exists as a wiki page.

## Related

- [[wiki/concepts/llm-wiki-pattern|LLM Wiki Pattern]]
- [[wiki/sources/karpathy-llm-wiki-pattern|Karpathy LLM Wiki Pattern (source)]]
