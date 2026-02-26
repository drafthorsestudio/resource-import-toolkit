=== ORN Resource Import Toolkit ===
Contributors: custom
Tags: import, csv, resources, migration, acf
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPLv2 or later

A four-tool WordPress admin toolkit for migrating resource data from a legacy system into a custom Resource post type with ACF fields.

== Description ==

Resource Import Toolkit provides a sequential workflow for importing resources from a legacy intranet system into WordPress. Each tool in the toolkit handles one stage of the migration and is accessible from the **Resource Toolkit** admin menu.

The toolkit assumes:

* A **Resource** custom post type
* A **Consultant** custom post type with an ACF email field
* ACF field groups on the Resource post type (see Field Reference below)
* A hierarchical **resource-category** custom taxonomy (up to 4 levels deep)

= The Four Tools =

**1. Consultant Matcher**
Upload a CSV containing Author Name and Author Email columns. The tool matches each row against existing Consultant posts using a multi-pass strategy:

* Exact name match → Fuzzy name match (≥85% similarity or ≤2 edit distance) → Exact email match → Fuzzy email match
* Name normalization strips 50+ credential patterns (MD, PhD, LCSW, etc.) and handles "Lastname, Firstname" format
* Multi-author rows (containing "and", "&", or semicolons) are skipped
* Outputs three CSVs: matched, unmatched, and compiled (all rows with Consultant ID filled where found)

**2. Resource Importer**
Upload the compiled CSV from the Consultant Matcher (or any CSV following the mapping spec). The tool creates or updates Resource posts with:

* Post title, description, resource type, training level, date added
* Consultant relationship linking (when Consultant ID is present)
* Individual/Organization author fields (when no Consultant ID)
* External resource links via a repeater field
* Duplicate detection via `resource_original_id` — existing posts are updated rather than skipped

**3. Attachment Importer**
Upload a CSV mapping Resource IDs to remote file URLs. The tool:

* Downloads each file into the WordPress media library
* Appends it to the resource's `resource_links` repeater field alongside any existing external links
* Deduplicates by label — safe to re-run without creating duplicate repeater rows
* Processes in AJAX batches of 3 with automatic retry to handle large files and avoid PHP timeouts
* Includes a **Cleanup Utility** to remove empty repeater rows (dry run / live modes)

**4. Taxonomy & Audience Assigner**
Upload a CSV with resource categories and target audience data. The tool:

* Assigns up to 3 hierarchical `resource-category` taxonomy term selections (4 levels deep each)
* Resolves terms by walking the taxonomy tree under the correct parent, handling duplicate term names
* Sets `target_audience` and `secondary_target_audience` ACF checkbox fields using smart comma-splitting for compound labels
* **Interactive mismatch resolution** — if a term or audience value doesn't match, processing pauses and prompts for the correct selection; choices are remembered for the rest of the run
* Sets `resource_status` to "active" on each successfully processed resource

= Shared Features =

* **Dry Run / Live modes** on every tool — preview changes before committing
* **Row limits** — process a subset of rows for testing
* **CSV persistence** — uploaded CSVs are saved so you can switch from dry run to live without re-uploading
* **Color-coded logs** — green (success), yellow (skipped), red (error)
* **AJAX batch processing** on tools 3 and 4 to avoid PHP memory and timeout limits

== ACF Field Reference ==

Fields on the Resource post type used by this toolkit:

| ACF Field Name                        | Type         | Tool(s)       |
|---------------------------------------|--------------|---------------|
| resource_original_id                  | text         | 2, 3, 4       |
| resource_status                       | select       | 2, 4          |
| resource_description                  | wysiwyg      | 2             |
| resource_type                         | select       | 2             |
| author_type                           | radio        | 2             |
| material_author                       | relationship | 2             |
| organization_or_individual_name       | text         | 2             |
| organization_or_individual_email      | email        | 2             |
| training_level                        | select       | 2             |
| added_by_name                         | text         | 2             |
| added_by_email                        | email        | 2             |
| date_added                            | date_picker  | 2             |
| resource_links                        | repeater     | 2, 3          |
| resource_links > resource_link_label  | text         | 2, 3          |
| resource_links > resource_external_link | url        | 2             |
| resource_links > resource_internal_file | file       | 3             |
| target_audience                       | checkbox     | 4             |
| secondary_target_audience             | checkbox     | 4             |

== CSV Format Reference ==

= Tool 1: Consultant Matcher =
Required columns: `Author Email`, `Author Name`

= Tool 2: Resource Importer =
Columns: `Title`, `ResourceID`, `Description`, `Format`, `Consultant ID`, `Author Name`, `Author Email`, `External Resource Link`, `Training Level`, `Added By Name`, `Added By Email`, `Date Added`

= Tool 3: Attachment Importer =
Required columns: `Resource ID`, `Resource Internal File` (URL), `Resource Link Label`

= Tool 4: Taxonomy & Audience Assigner =
Columns: `Resource ID`, `target_audience`, `secondary_target_audience`, plus up to 3 sets of:
`Resource Category N - Main Category`, `Resource Category N - Sub Category`, `Resource Category N - Sub Sub Category`, `Resource Category N - Sub Sub Sub Category` (where N = 1, 2, or 3)

== Installation ==

1. Upload the `resource-import-toolkit` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Navigate to **Resource Toolkit** in the admin sidebar
4. Run the tools in order: Consultant Matcher → Resource Importer → Attachment Importer → Taxonomy Assigner

== Changelog ==

= 3.1.0 =
* Resource Importer: pipe-delimited multi-author support in Consultant ID and Author Name fields
* Four author scenarios: no consultant, single consultant, mixed (consultant + guest co-authors), all consultants
* material_author relationship field now accepts multiple consultant post IDs
* Mixed rows populate both material_author and organization_or_individual_name/email fields
* Non-consultant co-author names and emails stored comma-separated in org/individual fields
* Improved log labels: shows consultant count and mixed status per row

= 3.0.0 =
* Added Tool 4: Taxonomy & Audience Assigner with interactive mismatch resolution
* Taxonomy assigner sets resource_status to "active" on successful processing
* Fixed dry run UI — form card and submit button now reappear after processing completes
* Added dry run / live mode to the repeater cleanup utility
* Enhanced debug logging in cleanup utility for diagnosing field value formats
* Version headers added to all class files
* Added this readme

= 2.1.0 =
* Attachment Importer: deduplication by label — prevents duplicate repeater rows on re-runs
* Attachment Importer: batch size reduced from 5 to 3 for large file reliability
* Attachment Importer: retry logic (up to 2 retries per batch, then skip to next)
* Attachment Importer: added "Skipped (already in repeater)" stat to results
* Added Cleanup Utility to remove empty repeater rows

= 2.0.0 =
* Added Tool 3: Attachment Importer with AJAX batch processing
* Rewrote Attachment Importer for low memory — reads CSV in slices, targeted DB queries per batch
* Live progress bar and streaming log output
* CSV persistence for all tools (dry-run-to-live workflow without re-uploading)

= 1.2.0 =
* Resource Importer: duplicate ResourceIDs now trigger an update instead of a skip
* Update applies all fields including author type, consultant relationship, and external links
* Results table shows "New posts created" and "Existing posts updated" separately

= 1.1.0 =
* Corrected ACF field names: organization_or_individual_name, organization_or_individual_email
* Corrected author_type values: consultant / individual_organization
* Added consultant vs non-consultant author logic per mapping instructions

= 1.0.0 =
* Initial combined plugin with submenu structure
* Tool 1: Consultant Matcher with fuzzy name/email matching and credential stripping
* Tool 2: Resource Importer with full ACF field mapping, format validation, and dry run support
* Unified export directory (wp-content/uploads/rit-exports/)
* Shared admin styles across all toolkit pages
