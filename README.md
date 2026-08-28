# Commerce_FeaturedColors

A configurable product that comes in eight colours still has to show *one* of them on category pages, in search results and in the grid. This module records which — per store — and keeps that choice consistent with the catalogue.

Set it on the product form, in bulk by CSV, or through the API.

---

## Installation

```bash
composer require commerce/module-featured-colors
bin/magento module:enable Commerce_FeaturedColors
bin/magento setup:upgrade
```

---

## Configuration

**Stores → Configuration → Catalog → Featured Colours**

| Setting | Default | Notes |
| --- | --- | --- |
| Enabled | Yes | |
| Colour attribute | `color` | Any select/swatch attribute |
| Repoint base image | **No** | See below |

"Repoint base image" makes applying a featured colour also set the configurable's own `image`, `small_image` and `thumbnail` to the chosen child's image. It is **off** by default because it overwrites catalogue data. The previous version did it unconditionally, and wrote a full CDN URL into an attribute that holds a media-gallery relative path — so the media gallery, the image helper, the grid thumbnail and catalogue export all failed to resolve it.

---

## Importing

**System → Import → Featured Colours**

```csv
sku,color,store_id
CONFIG-SCRUB-TOP-01,Ceil Blue,0
CONFIG-SCRUB-TOP-02,Wine,0
```

`store_id` is optional and defaults to `0` (default scope). Behaviours: Add/Update, Replace, Delete. A delete row needs only the SKU.

Failures are reported against **the row that caused them**, naming the reason — unknown SKU, colour not an option, or no enabled child in that colour.

---

## Data model

The module owns `commerce_featured_color`: one row per product per store, with `product_id`, `store_id`, `child_product_id`, `color_option_id`, `color_label` and `image_path` as real, indexed, foreign-keyed columns.

Owning the table is the point. Featured colours held as a JSON document inside
another module's table cannot be queried, indexed, constrained or reported on,
and they disappear when that module does.

If you are migrating from an arrangement like that, point the migration patch at
the table the data currently lives in:

```xml
<type name="Commerce\FeaturedColors\Setup\Patch\Data\MigrateLegacyFeaturedColors">
    <arguments>
        <argument name="legacyTable" xsi:type="string">your_legacy_table</argument>
    </arguments>
</type>
```

The patch expects the label, image and child id packed into a JSON column named
`default`; a different shape needs its own patch.

---

## How the import behaves

- **Colour options load once per run, not once per row.** A catalogue with 300
  colours and a 5,000-row file would otherwise mean 5,000 option loads and about
  1.5 million string comparisons before any work happens. Lookups are hash
  lookups.
- **One batched query resolves every parent/colour pair**, and returns the
  child's image path in the same pass. Resolving them individually costs a
  filtered product-collection load per row — twice, because Magento validates
  before it imports.
- **Writes are one `INSERT … ON DUPLICATE KEY` per bunch, inside a
  transaction**, with the reindex event dispatched once after the commit. Row-at-
  a-time writes with an event inside the loop leave the table half-written on a
  mid-import failure, and observers have already acted on rows that are about to
  be rolled back.
- **Errors are reported against the CSV line that caused them.** `Assignment`
  carries its own `rowNumber` rather than being identified by its position in a
  list of failures.
- **A Delete row needs only a SKU.** Demanding a colour in every behaviour fails
  every delete row in the file.
- **Grid and form values are store-scoped.** Without scoping, a product with rows
  for several stores appears once per store in the grid, and the form shows
  whichever store's row the database returned first.
- **Base images are written with `Product\Action::updateAttributes()`**, which
  writes the batch without loading anything.

---

## Gotchas

- **"Repoint base image" is off by default and is destructive when on.** It overwrites `image`, `small_image` and `thumbnail` on the configurable. There is no undo, and the previous values are not recorded.
- **The admin grid join is default-scope only** (`store_id = 0`). A colour set only for a specific store view will not appear in the grid, by design — joining every store's row is what produces duplicate grid rows.
- **The legacy migration patch is a no-op until `legacyTable` is set** in `di.xml`. A fresh install wants it left empty; an upgrade wants it pointed at the old JSON-blob table.
- **The image path carried over from a legacy migration is deliberately dropped.** The old value was a full CDN URL rather than a media path, so it is left null and recomputed from the child product on the next apply.

---

## Tests

```bash
M2_VENDOR=/path/to/magento/vendor php ../dev/run-tests.php -c ../dev/phpunit.xml
```

The suite runs against a real Magento installation without being installed into it. `dev/bootstrap.php` builds a PSR-4-only autoloader from that installation's composer map, which is also why it works where the host's own `vendor/autoload.php` is broken.

---

## Rebranding

```bash
php ../bin/rebrand Acme
```
