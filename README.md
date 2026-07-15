# LMS Default Content

Extends Drupal's [default_content](https://www.drupal.org/project/default_content) module to correctly export and import content for sites using the [Drupal LMS module](https://www.drupal.org/project/lms).

---

## The problem this module solves

The `default_content` module is a widely-used approach for bundling site content as UUID-based YAML files that can be imported by enabling a module. This makes it straightforward to ship default content — media, taxonomy terms, nodes — alongside a site installation profile or recipe.

However, `default_content`'s built-in normalizer has no awareness of the custom field types introduced by the LMS module. Specifically, `lms_reference` fields (used on course entities to reference lessons) store a serialized data blob alongside the entity reference containing LMS metadata: `mandatory`, `required_score`, `time_limit`, `auto_repeat_failed`, and `max_score`. Without this module:

- The metadata blob is **silently dropped** during export — courses import without their lesson configuration
- Entity references may export as **raw numeric IDs** rather than stable UUIDs, breaking imports on any environment other than the one they were exported from
- Computed fields (such as `start_link` on course entities) are written to the YAML as binary blobs, producing invalid export files

`lms_default_content` fixes all three problems without patching or forking `default_content` or the LMS module.

---

## How it works

### Overriding the normalizer without patching contrib

Drupal's service provider system allows any module to alter another module's registered services at container compile time. `LmsDefaultContentServiceProvider::alter()` replaces the `default_content.content_entity_normalizer` service class with an LMS-aware subclass — a guaranteed override regardless of module weight, with no YAML service ID conflicts.

### What the normalizer fixes

**1. Computed field exclusion**

The parent normalizer filters internal and read-only fields but not computed `BaseFieldDefinition` fields. This override excludes any field where `isComputed() === true`, preventing binary blobs from appearing in exported YAML.

**2. Unloaded entity reference resolution**

When the parent normalizer encounters an entity reference whose entity was not lazy-loaded, it falls through to returning the raw numeric `target_id`. Numeric IDs are environment-specific and break on import. This override explicitly loads the entity by `target_id` + `target_type` and emits the UUID instead, suppressing the numeric ID entirely if the entity cannot be loaded.

**3. `lms_reference` field serialization**

On export, `lms_reference` items are written as a flat YAML structure with the entity reference and all metadata keys at the same level:

```yaml
lessons:
  - target_uuid: 7777d880-73af-47f7-ac0c-28013987d732
    target_type: lms_lesson
    mandatory: true
    auto_repeat_failed: false
    required_score: 50
    time_limit: 0
```

On import, the UUID is resolved back to a numeric `target_id` and the remaining keys are reconstructed into the data blob.

---

## Usage

### Requirements

- Drupal 10.3+ or 11
- [default_content](https://www.drupal.org/project/default_content) 2.x
- [lms](https://www.drupal.org/project/lms) 1.1+

### Installation

```bash
composer require ten7/lms_default_content
drush en lms_default_content
```

Enable this module **before** any module that bundles LMS default content.

### Creating a default content module for LMS content

The pattern is the same as any `default_content`-based module. Create a custom module with a `content/` directory, export your LMS entities into it, and declare `lms_default_content` as a dependency in your `.info.yml`:

```yaml
dependencies:
  - lms_default_content:lms_default_content
```

To export all courses and their full dependency chain (lessons → activities):

```bash
drush dcer group --folder=modules/custom/your_module/content
```

A few things to keep in mind when structuring your content module:

- **File entities must exist before media entities.** If your courses reference media with image files, the file entities and image files need to be importable before the media entities that reference them. The simplest approach is to put them in a separate module that installs first.
- **Media entities must exist before courses.** Declare the media module as a dependency of your content module so the install order is enforced automatically.
- Enable `lms_default_content` first, then your content modules in dependency order.

---

## Decisions log

| Decision | Rationale |
|----------|-----------|
| Extend `ContentEntityNormalizer` rather than writing from scratch | The parent handles most field types correctly; only specific cases needed interception. |
| Service provider `alter()` instead of a competing service declaration | Guaranteed override at container compile time with no ordering ambiguity. |
| No patching of contrib modules | Patches break on module updates. The service provider pattern achieves the same result cleanly. |
| Separate module rather than a patch to `default_content` | Keeps the fix isolated and independently versioned; intended for eventual upstream contribution. |
