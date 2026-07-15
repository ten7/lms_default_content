# LMS Default Content — Technical Summary

This document covers the architecture, decisions, and module structure for how we export and import LMS course content across environments using Drupal's `default_content` ecosystem.

---

## The Problem

We needed a way to bundle LMS course content (courses, lessons, activities, and course images) so it can be installed on any fresh environment by enabling a module — the same pattern we already use for media via `greendale_default_content_media`.

The challenge is that `lms_reference` fields (used on course entities to reference lessons) carry additional metadata beyond a plain entity reference: `mandatory`, `required_score`, `time_limit`, `auto_repeat_failed`, and `max_score`. The `default_content` module's built-in normalizer has no awareness of this extra data and would silently drop it during export/import. Additionally, environment-specific numeric entity IDs would appear in the exported YAML instead of stable UUIDs.

---

## The Constraint

**No modifications to any files in `web/modules/contrib/`.** All fixes and extensions are implemented in new custom modules only. Patching or forking contrib modules was explicitly ruled out.

---

## What We Built

### `lms_default_content` (custom, contrib-intended)

**Location:** `web/modules/custom/lms_default_content/`

A reusable module intended to eventually be contributed upstream. It extends `default_content`'s normalizer to handle LMS entity types correctly. It has no site-specific content — only the plumbing.

**How it overrides the normalizer without patching contrib:**

Drupal's service provider system allows any module to alter another module's service definitions at container compile time. `LmsDefaultContentServiceProvider::alter()` replaces the `default_content.content_entity_normalizer` service class with our own, guaranteeing the override regardless of module weight — no YAML service ID conflicts needed.

**What the custom normalizer (`LmsContentEntityNormalizer`) fixes:**

1. **Computed field exclusion** — The parent normalizer filters internal and read-only fields but not computed ones. The `start_link` field on course entities is a computed `BaseFieldDefinition` and would otherwise be written to the export YAML as a binary blob. Our override excludes any field whose storage definition is a `BaseFieldDefinition` with `isComputed() === true`.

2. **Unloaded entity reference resolution** — When the `default_content` normalizer encounters an entity reference whose entity wasn't lazy-loaded, it falls through to returning the raw numeric `target_id` (e.g. `target_id: 2`). Numeric IDs are environment-specific and break on import elsewhere. Our override explicitly loads the entity by `target_id` + `target_type` and emits the UUID instead. It also suppresses the raw numeric ID entirely if the entity cannot be loaded.

3. **`lms_reference` field serialization** — LMS reference fields store a serialized data blob alongside the entity reference. The parent normalizer has no awareness of this blob. On export (`normalizeTranslation`), our override detects fields of type `lms_reference` and emits a flat YAML structure:
   ```yaml
   lessons:
     - target_uuid: 7777d880-73af-47f7-ac0c-28013987d732
       target_type: lms_lesson
       mandatory: true
       auto_repeat_failed: false
       required_score: 50
       time_limit: 0
   ```
   On import (`setFieldValues`), it resolves the UUID back to a numeric `target_id` and reconstructs the data blob from the remaining keys.

---

### `greendale_default_content_lms` (site-specific)

**Location:** `web/modules/custom/greendale_default_content_lms/`

The site-specific content bundle for Greendale's LMS courses. Enabling this module imports all courses, lessons, and activities into a fresh environment.

**Content included:**

| Entity type   | Count |
|---------------|-------|
| Courses (group, bundle: lms_course) | 10 |
| Lessons (lms_lesson) | 26 |
| Activities (lms_activity) | 62 |

**Dependencies declared in `.info.yml`:**
- `greendale_default_content_media` — must install first so course image media entities exist before courses import
- `lms_default_content` — provides the LMS-aware normalizer

**How to re-export content:**

If courses, lessons, or activities are updated on a development environment and need to be re-bundled, run:

```bash
ddev drush dcer group --folder=modules/custom/greendale_default_content_lms/content
```

Omitting the entity ID exports all group entities and their full dependency chain (lessons → activities). Run from inside the DDEV web container or prefix with `ddev`.

---

### `greendale_default_content_media` (site-specific, pre-existing)

**Location:** `web/modules/custom/greendale_default_content_media/`

Pre-existing module for media entities. We extended its `content/` directory to also include:

- `content/media/` — 10 image media entities (course thumbnails)
- `content/file/` — 10 file entities + PNG image files

The file entities and images **must live in this module** (not in `greendale_default_content_lms`) so they are present in the database before media entities are imported. The `default_content` importer resolves references by UUID lookup — the referenced entity must already exist.

---

## Install Order

Enable modules in this order on a fresh environment:

```bash
drush en lms_default_content
drush en greendale_default_content_media
drush en greendale_default_content_lms
```

The `greendale_default_content_lms.info.yml` dependency declaration enforces that `greendale_default_content_media` and `lms_default_content` are installed first, but the explicit ordering above is clearest for manual runs.

---

## Decisions Log

| Decision | Rationale |
|----------|-----------|
| Use `default_content` ecosystem rather than `lms_yaml` | `lms_yaml` uses environment-specific numeric IDs; `default_content` uses UUIDs throughout. Also consistent with how we handle media. |
| Extend `ContentEntityNormalizer` rather than writing from scratch | The parent handles most field types correctly; we only needed to intercept specific cases. |
| Service provider `alter()` instead of declaring a competing service | `alter()` is a guaranteed override at container compile time. Declaring a new service with the same ID can have ordering ambiguity. |
| No patching of `web/modules/contrib/` | Patches break on module updates and create maintenance burden. The service provider pattern achieves the same result cleanly from a custom module. |
| `lms_default_content` as a separate contrib-intended module | The LMS-aware normalization logic is not Greendale-specific. Keeping it separate makes it easier to contribute upstream and reuse on other sites. |
| Move file entities into `greendale_default_content_media` | `default_content` imports entities in the order it finds them; media entities reference file entities, so files must exist first. Co-locating them in the same module guarantees this. |
| Export all courses with `drush dcer group` (no ID filter) | Simpler than maintaining a UUID list; exports the full dependency chain automatically. |
