---
name: abacus-development
description: >
  Configure and apply the Abacus package in Laravel applications.
license: MIT
metadata:
  author: FAEST OSS
---

# Abacus

Use this skill when a Laravel application needs to integrate the Abacus package.

## Primary Goal

- apply the `faest-oss/abacus` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project
- inspect the target code paths where the package should be applied

### 2. Apply the package's public API

Install the package with `composer require faest-oss/abacus`. Laravel discovers
`Faest\Abacus\AbacusServiceProvider` automatically. Use the package class through
`Faest\Abacus\Abacus` or its `Abacus` facade alias.

Publish all resources with `php artisan vendor:publish --tag=abacus`, or use an
individual `abacus-*` tag such as `abacus-config` or `abacus-migrations`.

## Rules, References, and Templates

Read before executing:

- no additional resource files for this skill

## Examples

- Resolve the package singleton with `app(\Faest\Abacus\Abacus::class)`.

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
