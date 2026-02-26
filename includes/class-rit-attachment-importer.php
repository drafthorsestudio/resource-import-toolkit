<?php
/**
 * Attachment Importer Tool (Batch AJAX — Low Memory)
 *
 * Imports file attachments from a CSV, downloads them into the WordPress
 * media library, and appends them to the resource_links repeater field
 * on existing Resource posts (matched by resource_original_id).
 *
 * Uses AJAX batch processing (5 rows per request). Each batch reads only
 * its slice of the CSV from disk and does a targeted DB query — nothing
 * heavy is stored in transients.
 *
 * @since   2.0.0
 * @version 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RIT_Attachment_Importer {

    const BATCH_SIZE = 3;

    /**
     * Register AJAX handlers. Called from the main plugin constructor.
     */
    public static function register_ajax() {
        add_action( 'wp_ajax_rit_attach_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_rit_attach_batch', array( __CLASS__, 'ajax_batch' ) );
        add_action( 'wp_ajax_rit_cleanup_repeater', array( __CLASS__, 'ajax_cleanup_repeater' ) );
    }

    // =========================================================================
    //  Admin UI
    // =========================================================================

    public static function render_page() {
        $stored_file = get_transient( 'rit_stored_attach_csv_' . get_current_user_id() );
        ?>
        <div class="wrap rit-wrap">
            <h1>Attachment Importer</h1>

            <div class="card" id="rit-attach-form-card">
                <h2>Upload Attachments CSV</h2>
                <p>Upload a CSV with <code>Resource ID</code>, <code>Resource Internal File</code>, and <code>Resource Link Label</code> columns.<br>
                   Files will be downloaded into the media library and appended to each resource's <strong>Resource Links</strong> repeater.</p>

                <form id="rit-attach-form" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'rit_attachment_import', 'rit_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csv_file">CSV File</label></th>
                            <td>
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" <?php echo $stored_file ? '' : 'required'; ?> />
                                <?php if ( $stored_file ) : ?>
                                    <p class="description">
                                        <strong>Previously uploaded file available:</strong> <?php echo esc_html( basename( $stored_file ) ); ?><br>
                                        Leave empty to re-use it, or upload a new file to replace it.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mode</th>
                            <td>
                                <label>
                                    <input type="radio" name="rit_mode" value="dry_run" checked />
                                    Dry Run (preview only — nothing is downloaded or saved)
                                </label><br>
                                <label>
                                    <input type="radio" name="rit_mode" value="live" />
                                    Live Import (downloads files and updates posts)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rit_limit">Row Limit</label></th>
                            <td>
                                <input type="number" name="rit_limit" id="rit_limit" value="0" min="0" step="1" style="width:80px;" />
                                <p class="description">Max rows to process. Set to <strong>0</strong> for no limit.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" id="rit-attach-submit" class="button button-primary">Import Attachments</button>
                    </p>
                </form>
            </div>

            <!-- Progress UI (hidden until started) -->
            <div class="card" id="rit-attach-progress" style="display:none;">
                <h2>Processing…</h2>
                <div style="background:#e0e0e0; border-radius:4px; overflow:hidden; height:24px; margin:10px 0;">
                    <div id="rit-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s; border-radius:4px;"></div>
                </div>
                <p id="rit-progress-text">Starting…</p>
            </div>

            <!-- Results (hidden until done) -->
            <div class="card rit-results" id="rit-attach-results" style="display:none;">
                <h2 id="rit-results-title">Results</h2>
                <table class="rit-stats-table widefat striped" style="max-width:420px;">
                    <tbody>
                        <tr><td>Total rows in CSV</td><td id="rit-stat-total">0</td></tr>
                        <tr><td>Files attached</td><td id="rit-stat-attached">0</td></tr>
                        <tr><td>Skipped (already in repeater)</td><td id="rit-stat-skipped-dup">0</td></tr>
                        <tr><td>Resources not found</td><td id="rit-stat-not-found">0</td></tr>
                        <tr><td>Download errors</td><td id="rit-stat-dl-errors">0</td></tr>
                        <tr><td>Other errors</td><td id="rit-stat-errors">0</td></tr>
                    </tbody>
                </table>
                <h3>Log</h3>
                <div class="rit-log" id="rit-attach-log"></div>
            </div>
        </div>

        <!-- Cleanup Utility -->
        <div class="wrap rit-wrap" style="margin-top:30px;">
            <div class="card" id="rit-cleanup-card">
                <h2>Cleanup: Remove Empty Repeater Rows</h2>
                <p>Scans all Resource posts and removes any <code>resource_links</code> repeater rows where both the external link and internal file are empty.</p>
                <p>
                    <label><input type="radio" name="rit_cleanup_mode" value="dry_run" checked /> Dry Run (preview only)</label><br>
                    <label><input type="radio" name="rit_cleanup_mode" value="live" /> Live (remove empty rows)</label>
                </p>
                <p class="submit">
                    <button type="button" id="rit-cleanup-btn" class="button button-secondary">Run Cleanup</button>
                </p>
                <div id="rit-cleanup-results" style="display:none;">
                    <p id="rit-cleanup-text"></p>
                    <div class="rit-log" id="rit-cleanup-log" style="max-height:200px;"></div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var form       = document.getElementById('rit-attach-form');
            var submitBtn  = document.getElementById('rit-attach-submit');
            var formCard   = document.getElementById('rit-attach-form-card');
            var progressEl = document.getElementById('rit-attach-progress');
            var progressBar = document.getElementById('rit-progress-bar');
            var progressText = document.getElementById('rit-progress-text');
            var resultsEl  = document.getElementById('rit-attach-results');
            var logEl      = document.getElementById('rit-attach-log');
            var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            var stats = { total:0, attached:0, skipped_dup:0, not_found:0, download_errors:0, errors:0 };
            var jobId = null;
            var MAX_RETRIES = 2;

            function finishProcessing(titleText) {
                progressEl.style.display = 'none';
                formCard.style.display = '';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Import Attachments';
                document.getElementById('rit-results-title').textContent = titleText;
            }

            form.addEventListener('submit', function(e){
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Uploading…';

                var fd = new FormData(form);
                fd.append('action', 'rit_attach_start');

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data.success) {
                        alert('Error: ' + (data.data || 'Unknown error'));
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Import Attachments';
                        return;
                    }

                    jobId = data.data.job_id;
                    stats.total = data.data.total;

                    document.getElementById('rit-attach-form-card').style.display = 'none';
                    progressEl.style.display = '';
                    resultsEl.style.display = '';
                    logEl.innerHTML = '';

                    document.getElementById('rit-stat-total').textContent = stats.total;

                    processBatch(0, 0);
                })
                .catch(function(err){
                    alert('Request failed: ' + err);
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Import Attachments';
                });
            });

            function processBatch(offset, retries) {
                retries = retries || 0;
                var fd = new FormData();
                fd.append('action', 'rit_attach_batch');
                fd.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'rit_attach_batch' ) ); ?>);
                fd.append('job_id', jobId);
                fd.append('offset', offset);

                progressText.textContent = 'Processing rows ' + (offset+1) + '–' + Math.min(offset + <?php echo self::BATCH_SIZE; ?>, stats.total) + ' of ' + stats.total + '…';

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(function(data){
                    if (!data.success) {
                        progressText.textContent = 'Error: ' + (data.data || 'Unknown error');
                        return;
                    }

                    var d = data.data;

                    stats.attached += d.attached;
                    stats.skipped_dup += d.skipped_dup;
                    stats.not_found += d.not_found;
                    stats.download_errors += d.download_errors;
                    stats.errors += d.errors;

                    document.getElementById('rit-stat-attached').textContent = stats.attached;
                    document.getElementById('rit-stat-skipped-dup').textContent = stats.skipped_dup;
                    document.getElementById('rit-stat-not-found').textContent = stats.not_found;
                    document.getElementById('rit-stat-dl-errors').textContent = stats.download_errors;
                    document.getElementById('rit-stat-errors').textContent = stats.errors;

                    d.log.forEach(function(entry){
                        var div = document.createElement('div');
                        div.className = entry.level;
                        div.textContent = entry.msg;
                        logEl.appendChild(div);
                    });
                    logEl.scrollTop = logEl.scrollHeight;

                    var pct = Math.min(100, Math.round((d.next_offset / stats.total) * 100));
                    progressBar.style.width = pct + '%';

                    if (d.done) {
                        var mode = d.mode || 'live';
                        finishProcessing(mode === 'dry_run' ? 'Dry Run Results (no files downloaded)' : 'Import Results');
                    } else {
                        processBatch(d.next_offset, 0);
                    }
                })
                .catch(function(err){
                    if (retries < MAX_RETRIES) {
                        var div = document.createElement('div');
                        div.className = 'skip';
                        div.textContent = 'Batch at offset ' + offset + ' failed (' + err + '). Retrying (' + (retries+1) + '/' + MAX_RETRIES + ')…';
                        logEl.appendChild(div);
                        logEl.scrollTop = logEl.scrollHeight;
                        setTimeout(function(){ processBatch(offset, retries + 1); }, 3000);
                    } else {
                        var div = document.createElement('div');
                        div.className = 'error';
                        div.textContent = 'Batch at offset ' + offset + ' failed after ' + MAX_RETRIES + ' retries. Skipping to next batch.';
                        logEl.appendChild(div);
                        logEl.scrollTop = logEl.scrollHeight;
                        stats.errors += <?php echo self::BATCH_SIZE; ?>;
                        document.getElementById('rit-stat-errors').textContent = stats.errors;
                        var nextOffset = offset + <?php echo self::BATCH_SIZE; ?>;
                        if (nextOffset >= stats.total) {
                            finishProcessing('Import Results (completed with errors)');
                        } else {
                            processBatch(nextOffset, 0);
                        }
                    }
                });
            }

            // --- Cleanup utility ---
            var cleanupBtn = document.getElementById('rit-cleanup-btn');
            var cleanupResults = document.getElementById('rit-cleanup-results');
            var cleanupText = document.getElementById('rit-cleanup-text');
            var cleanupLog = document.getElementById('rit-cleanup-log');

            cleanupBtn.addEventListener('click', function(){
                var cleanupMode = document.querySelector('input[name="rit_cleanup_mode"]:checked').value;
                var confirmMsg = cleanupMode === 'live'
                    ? 'This will remove empty repeater rows from all Resource posts. Continue?'
                    : 'This will scan all Resource posts and show what would be removed. No changes will be made.';
                if (!confirm(confirmMsg)) return;
                cleanupBtn.disabled = true;
                cleanupBtn.textContent = 'Running…';
                cleanupResults.style.display = '';
                cleanupLog.innerHTML = '';
                cleanupText.textContent = 'Processing…';

                var fd = new FormData();
                fd.append('action', 'rit_cleanup_repeater');
                fd.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'rit_cleanup_repeater' ) ); ?>);
                fd.append('cleanup_mode', cleanupMode);

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    cleanupBtn.disabled = false;
                    cleanupBtn.textContent = 'Run Cleanup';
                    if (!data.success) {
                        cleanupText.textContent = 'Error: ' + (data.data || 'Unknown error');
                        return;
                    }
                    var d = data.data;
                    var modeLabel = d.mode === 'dry_run' ? ' (dry run — no changes made)' : '';
                    cleanupText.textContent = 'Done' + modeLabel + '. Scanned ' + d.scanned + ' resources, ' + (d.mode === 'dry_run' ? 'would clean' : 'cleaned') + ' ' + d.cleaned + ' posts, ' + (d.mode === 'dry_run' ? 'would remove' : 'removed') + ' ' + d.removed + ' empty rows.';
                    d.log.forEach(function(entry){
                        var div = document.createElement('div');
                        div.className = entry.level;
                        div.textContent = entry.msg;
                        cleanupLog.appendChild(div);
                    });
                })
                .catch(function(err){
                    cleanupBtn.disabled = false;
                    cleanupBtn.textContent = 'Run Cleanup';
                    cleanupText.textContent = 'Request failed: ' + err;
                });
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    //  AJAX: Start Job
    // =========================================================================

    /**
     * Handle the initial form submission: save CSV, count rows, return job ID.
     * Only stores the CSV path, mode, and total — no row data in the transient.
     */
    public static function ajax_start() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( 'rit_attachment_import', 'rit_nonce' );

        $mode  = isset( $_POST['rit_mode'] ) && $_POST['rit_mode'] === 'live' ? 'live' : 'dry_run';
        $limit = isset( $_POST['rit_limit'] ) ? intval( $_POST['rit_limit'] ) : 0;

        $csv_path = self::resolve_csv_path();

        if ( is_wp_error( $csv_path ) ) {
            wp_send_json_error( $csv_path->get_error_message() );
        }

        // Quick row count — just count lines without loading data.
        $total = self::count_csv_rows( $csv_path );

        if ( is_wp_error( $total ) ) {
            wp_send_json_error( $total->get_error_message() );
        }

        if ( $limit > 0 && $limit < $total ) {
            $total = $limit;
        }

        // Store only lightweight metadata.
        $job_id = 'rit_job_' . get_current_user_id() . '_' . time();

        set_transient( $job_id, array(
            'csv_path' => $csv_path,
            'mode'     => $mode,
            'total'    => $total,
        ), HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'job_id' => $job_id,
            'total'  => $total,
        ) );
    }

    // =========================================================================
    //  AJAX: Process Batch
    // =========================================================================

    /**
     * Process a batch of rows. Reads only the needed slice from the CSV file
     * and does a targeted DB lookup for just the Resource IDs in this batch.
     */
    public static function ajax_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( 'rit_attach_batch' );

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( $_POST['job_id'] ) : '';
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

        $job = get_transient( $job_id );
        if ( ! $job ) {
            wp_send_json_error( 'Job expired or not found. Please re-upload the CSV.' );
        }

        $csv_path = $job['csv_path'];
        $mode     = $job['mode'];
        $total    = $job['total'];

        // Read only this batch's rows from the CSV.
        $batch = self::read_csv_slice( $csv_path, $offset, self::BATCH_SIZE );

        if ( is_wp_error( $batch ) ) {
            wp_send_json_error( $batch->get_error_message() );
        }

        if ( $mode === 'live' ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $log             = array();
        $attached        = 0;
        $not_found       = 0;
        $download_errors = 0;
        $skipped_dup     = 0;
        $errors          = 0;

        // Collect unique Resource IDs in this batch for a targeted DB lookup.
        $batch_rids = array();
        foreach ( $batch as $row ) {
            $rid = isset( $row['Resource ID'] ) ? trim( $row['Resource ID'] ) : '';
            if ( $rid !== '' ) {
                $batch_rids[] = $rid;
            }
        }
        $batch_rids = array_unique( $batch_rids );
        $id_map     = ! empty( $batch_rids ) ? self::lookup_resource_ids( $batch_rids ) : array();

        // Group this batch by Resource ID for efficient repeater appending.
        $grouped = array();
        foreach ( $batch as $row ) {
            $rid = isset( $row['Resource ID'] ) ? trim( $row['Resource ID'] ) : '';
            if ( $rid === '' ) {
                $log[] = array( 'level' => 'skip', 'msg' => 'Row with empty Resource ID. Skipping.' );
                $errors++;
                continue;
            }
            $grouped[ $rid ][] = $row;
        }

        foreach ( $grouped as $resource_id => $file_rows ) {
            if ( ! isset( $id_map[ $resource_id ] ) ) {
                $log[] = array( 'level' => 'error', 'msg' => "Resource ID {$resource_id}: No matching resource post found. Skipping " . count( $file_rows ) . " file(s)." );
                $not_found += count( $file_rows );
                continue;
            }

            $post_id = $id_map[ $resource_id ];

            // Load existing repeater rows for deduplication and appending.
            $existing_rows = get_field( 'resource_links', $post_id );
            if ( ! is_array( $existing_rows ) ) {
                $existing_rows = array();
            }

            // Build a set of existing labels for duplicate detection.
            $existing_labels = array();
            foreach ( $existing_rows as $er ) {
                $lbl = isset( $er['resource_link_label'] ) ? trim( $er['resource_link_label'] ) : '';
                if ( $lbl !== '' ) {
                    $existing_labels[ $lbl ] = true;
                }
            }

            $new_rows = array();

            foreach ( $file_rows as $file_row ) {
                $file_url = isset( $file_row['Resource Internal File'] ) ? trim( $file_row['Resource Internal File'] ) : '';
                $label    = isset( $file_row['Resource Link Label'] ) ? trim( $file_row['Resource Link Label'] ) : '';

                if ( $file_url === '' ) {
                    $log[] = array( 'level' => 'skip', 'msg' => "Resource ID {$resource_id}: Empty file URL. Skipping." );
                    $errors++;
                    continue;
                }

                // Deduplication: skip if this label already exists in the repeater.
                if ( $label !== '' && isset( $existing_labels[ $label ] ) ) {
                    $log[] = array( 'level' => 'skip', 'msg' => "Resource ID {$resource_id}: \"{$label}\" already in repeater. Skipping." );
                    $skipped_dup++;
                    continue;
                }

                if ( $mode === 'live' ) {
                    $attachment_id = self::sideload_file( $file_url, $post_id );

                    if ( is_wp_error( $attachment_id ) ) {
                        $log[] = array( 'level' => 'error', 'msg' => "Resource ID {$resource_id}: Download failed for \"{$label}\" — " . $attachment_id->get_error_message() );
                        $download_errors++;
                        continue;
                    }

                    $new_rows[] = array(
                        'resource_link_label'    => $label,
                        'resource_external_link' => '',
                        'resource_internal_file' => $attachment_id,
                    );

                    // Track the label so subsequent rows in same batch don't duplicate.
                    $existing_labels[ $label ] = true;

                    $log[] = array( 'level' => 'ok', 'msg' => "Resource ID {$resource_id}: Attached \"{$label}\" (attachment #{$attachment_id}) to post #{$post_id}" );
                } else {
                    // Dry run — still check for duplicates.
                    $filename = basename( urldecode( $file_url ) );
                    $log[] = array( 'level' => 'ok', 'msg' => "Resource ID {$resource_id}: Would download \"{$filename}\" and attach as \"{$label}\" to post #{$post_id}" );
                }

                $attached++;
            }

            if ( $mode === 'live' && ! empty( $new_rows ) ) {
                $merged = array_merge( $existing_rows, $new_rows );
                update_field( 'resource_links', $merged, $post_id );
            }
        }

        $next_offset = $offset + self::BATCH_SIZE;
        $done        = $next_offset >= $total;

        wp_send_json_success( array(
            'attached'        => $attached,
            'not_found'       => $not_found,
            'download_errors' => $download_errors,
            'skipped_dup'     => $skipped_dup,
            'errors'          => $errors,
            'log'             => $log,
            'next_offset'     => $next_offset,
            'done'            => $done,
            'mode'            => $mode,
        ) );
    }

    // =========================================================================
    //  AJAX: Cleanup Empty Repeater Rows
    // =========================================================================

    /**
     * Loop through all resource posts and remove any resource_links repeater
     * rows where both resource_external_link and resource_internal_file are empty.
     */
    public static function ajax_cleanup_repeater() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( 'rit_cleanup_repeater' );

        $mode = isset( $_POST['cleanup_mode'] ) && $_POST['cleanup_mode'] === 'live' ? 'live' : 'dry_run';

        $query = new WP_Query( array(
            'post_type'      => 'resource',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ) );

        $log     = array();
        $scanned = 0;
        $cleaned = 0;
        $removed = 0;

        if ( ! empty( $query->posts ) ) {
            $debug_logged = 0;
            $debug_max    = 3;

            foreach ( $query->posts as $post_id ) {
                $scanned++;

                $rows = get_field( 'resource_links', $post_id );
                if ( ! is_array( $rows ) || empty( $rows ) ) {
                    continue;
                }

                $filtered  = array();
                $dropped   = 0;

                foreach ( $rows as $row ) {
                    $has_content = false;

                    // Check external link.
                    if ( isset( $row['resource_external_link'] ) ) {
                        $ext = $row['resource_external_link'];
                        if ( is_string( $ext ) && trim( $ext ) !== '' ) {
                            $has_content = true;
                        }
                    }

                    // Check internal file — could be: URL string, attachment ID (int), array with 'id', false, '', or 0.
                    if ( ! $has_content && isset( $row['resource_internal_file'] ) ) {
                        $file = $row['resource_internal_file'];
                        if ( is_array( $file ) && ! empty( $file['id'] ) ) {
                            $has_content = true;
                        } elseif ( is_numeric( $file ) && intval( $file ) > 0 ) {
                            $has_content = true;
                        } elseif ( is_string( $file ) && trim( $file ) !== '' ) {
                            $has_content = true;
                        }
                    }

                    if ( $has_content ) {
                        $filtered[] = $row;
                    } else {
                        $dropped++;
                    }
                }

                // Debug: log row-by-row details for posts that have a mix of kept/dropped rows.
                if ( $debug_logged < $debug_max && $dropped > 0 ) {
                    $title = get_the_title( $post_id );
                    $log[] = array( 'level' => 'skip', 'msg' => "DEBUG post #{$post_id} \"{$title}\" — " . count( $rows ) . " total rows:" );
                    foreach ( $rows as $di => $drow ) {
                        $parts = array();
                        foreach ( $drow as $key => $val ) {
                            if ( is_array( $val ) ) {
                                $parts[] = "{$key}=array(" . count( $val ) . ")";
                            } elseif ( is_string( $val ) && strlen( $val ) > 60 ) {
                                $parts[] = "{$key}=\"" . substr( $val, 0, 60 ) . "…\"";
                            } else {
                                $parts[] = "{$key}=" . var_export( $val, true );
                            }
                        }
                        $verdict = isset( $filtered[ $di ] ) ? 'KEEP' : 'DROP';
                        // Recalculate verdict accurately.
                        $row_has = false;
                        $r_ext  = isset( $drow['resource_external_link'] ) ? $drow['resource_external_link'] : '';
                        $r_file = isset( $drow['resource_internal_file'] ) ? $drow['resource_internal_file'] : '';
                        if ( is_string( $r_ext ) && trim( $r_ext ) !== '' ) { $row_has = true; }
                        if ( ! $row_has && is_array( $r_file ) && ! empty( $r_file['id'] ) ) { $row_has = true; }
                        if ( ! $row_has && is_numeric( $r_file ) && intval( $r_file ) > 0 ) { $row_has = true; }
                        if ( ! $row_has && is_string( $r_file ) && trim( $r_file ) !== '' ) { $row_has = true; }
                        $verdict = $row_has ? 'KEEP' : 'DROP';

                        $log[] = array( 'level' => 'skip', 'msg' => "  row {$di} [{$verdict}]: " . implode( ', ', $parts ) );
                    }
                    $debug_logged++;
                }

                if ( $dropped > 0 ) {
                    if ( $mode === 'live' ) {
                        update_field( 'resource_links', $filtered, $post_id );
                    }
                    $removed += $dropped;
                    $cleaned++;
                    $title = get_the_title( $post_id );
                    $remaining = count( $filtered );
                    $verb = $mode === 'dry_run' ? 'Would remove' : 'Removed';
                    $log[] = array( 'level' => 'ok', 'msg' => "Post #{$post_id} \"{$title}\": {$verb} {$dropped} empty row(s), {$remaining} would remain." );
                }
            }
        }

        wp_send_json_success( array(
            'mode'    => $mode,
            'scanned' => $scanned,
            'cleaned' => $cleaned,
            'removed' => $removed,
            'log'     => $log,
        ) );
    }

    // =========================================================================
    //  File Sideloading
    // =========================================================================

    private static function sideload_file( $url, $post_id ) {
        $url = self::sanitize_url( $url );

        $tmp = download_url( $url, 120 );

        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $filename   = self::extract_filename( $url );
        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
        }

        return $attachment_id;
    }

    private static function sanitize_url( $url ) {
        $parts    = explode( '/', $url );
        $filename = array_pop( $parts );
        $filename = rawurlencode( rawurldecode( $filename ) );
        $parts[]  = $filename;
        return implode( '/', $parts );
    }

    private static function extract_filename( $url ) {
        $path     = wp_parse_url( $url, PHP_URL_PATH );
        $filename = basename( $path );
        $filename = urldecode( $filename );
        $filename = sanitize_file_name( $filename );
        return $filename;
    }

    // =========================================================================
    //  CSV Persistence
    // =========================================================================

    private static function resolve_csv_path() {
        $has_new_upload = ! empty( $_FILES['csv_file']['tmp_name'] )
                          && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK;

        if ( $has_new_upload ) {
            $upload_dir = wp_upload_dir();
            $dir        = $upload_dir['basedir'] . '/rit-exports/';

            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
                file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
            }

            $stored_name = 'attach-upload-' . get_current_user_id() . '-' . date( 'Ymd-His' ) . '.csv';
            $stored_path = $dir . $stored_name;

            if ( ! move_uploaded_file( $_FILES['csv_file']['tmp_name'], $stored_path ) ) {
                return new WP_Error( 'csv_save', 'Could not save the uploaded file.' );
            }

            set_transient( 'rit_stored_attach_csv_' . get_current_user_id(), $stored_path, DAY_IN_SECONDS );

            return $stored_path;
        }

        $stored_path = get_transient( 'rit_stored_attach_csv_' . get_current_user_id() );

        if ( $stored_path && file_exists( $stored_path ) ) {
            return $stored_path;
        }

        return new WP_Error( 'csv_missing', 'No CSV file uploaded and no previously uploaded file available.' );
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Count data rows in a CSV (excludes header). Lightweight — never loads all data.
     */
    private static function count_csv_rows( $filepath ) {
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_read', 'Could not read the CSV file.' );
        }

        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', 'The CSV file appears to be empty.' );
        }

        $headers = array_map( function ( $h ) {
            return trim( $h, "\xEF\xBB\xBF \t\n\r\0\x0B" );
        }, $headers );

        $required = array( 'Resource ID', 'Resource Internal File', 'Resource Link Label' );
        foreach ( $required as $col ) {
            if ( ! in_array( $col, $headers, true ) ) {
                fclose( $handle );
                return new WP_Error( 'csv_columns', "CSV must contain a \"{$col}\" column." );
            }
        }

        $count = 0;
        $col_count = count( $headers );
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === $col_count ) {
                $count++;
            }
        }

        fclose( $handle );
        return $count;
    }

    /**
     * Read a specific slice of rows from a CSV file.
     *
     * @param  string $filepath  Path to CSV.
     * @param  int    $offset    Zero-based row offset (after header).
     * @param  int    $count     Number of rows to read.
     * @return array|WP_Error    Array of associative rows or error.
     */
    private static function read_csv_slice( $filepath, $offset, $count ) {
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_read', 'Could not read the CSV file.' );
        }

        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', 'The CSV file appears to be empty.' );
        }

        $headers   = array_map( function ( $h ) {
            return trim( $h, "\xEF\xBB\xBF \t\n\r\0\x0B" );
        }, $headers );
        $col_count = count( $headers );

        // Skip to the offset.
        $current = 0;
        while ( $current < $offset && ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === $col_count ) {
                $current++;
            }
        }

        // Read the batch.
        $rows = array();
        $read = 0;
        while ( $read < $count && ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === $col_count ) {
                $rows[] = array_combine( $headers, $data );
                $read++;
            }
        }

        fclose( $handle );
        return $rows;
    }

    /**
     * Look up post IDs for a specific set of resource_original_id values.
     * Only queries for the IDs we need — not the full table.
     *
     * @param  array $original_ids  Array of original ID strings.
     * @return array                Associative array of original_id => post_id.
     */
    private static function lookup_resource_ids( $original_ids ) {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $original_ids ), '%s' ) );

        $query = $wpdb->prepare(
            "SELECT pm.meta_value AS original_id, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'resource_original_id'
             AND pm.meta_value IN ({$placeholders})
             AND p.post_type = 'resource'
             AND p.post_status != 'trash'",
            $original_ids
        );

        $results = $wpdb->get_results( $query, OBJECT );

        $map = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $map[ $row->original_id ] = (int) $row->post_id;
            }
        }

        return $map;
    }

    private static function redirect_with_error( $message ) {
        set_transient( 'rit_attach_results_' . get_current_user_id(), array( 'error' => $message ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=rit-attachment-importer' ) );
        exit;
    }
}
