---
title: LLM Wiki Pattern
type: concept
tags: [knowledge-management, methodology]
created: 2026-06-29
sources: [karpathy-llm-wiki-pattern]
---

# LLM Wiki Pattern

## Definition

A workflow where an LLM incrementally builds and maintains a structured wiki from raw source documents, rather than retrieving from raw docs at query time (RAG). The wiki is a persistent, compounding artifact — cross-references pre-built, contradictions pre-flagged, synthesis already done.

## Why it matters

Humans abandon wikis because maintenance cost grows faster than value. LLMs don't get bored and can touch 15 files in one pass. The pattern makes personal knowledge bases sustainable by offloading all bookkeeping to the LLM.

## Related

- [[wiki/concepts/rag-vs-wiki|RAG vs Persistent Wiki]]
- [[wiki/sources/karpathy-llm-wiki-pattern|Karpathy LLM Wiki Pattern (source)]]
