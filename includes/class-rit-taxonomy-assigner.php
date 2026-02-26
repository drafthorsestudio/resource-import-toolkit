<?php
/**
 * Taxonomy & Audience Assigner Tool (Batch AJAX)
 *
 * Assigns resource-category taxonomy terms (up to 3 hierarchical selections,
 * 4 levels deep) and target_audience / secondary_target_audience ACF checkbox
 * fields to existing Resource posts matched by resource_original_id.
 *
 * Features:
 * - AJAX batch processing (10 rows per request) to avoid timeouts.
 * - Interactive mismatch resolution: unknown terms/audiences pause processing
 *   and prompt the user to pick the correct match.
 * - Mismatch resolutions are remembered for the rest of the run.
 *
 * @since   3.0.0
 * @version 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RIT_Taxonomy_Assigner {

    const BATCH_SIZE = 10;

    // ACF checkbox value => label for both target_audience and secondary_target_audience.
    const AUDIENCE_CHOICES = array(
        'addiction_specialists'                                                  => 'Addiction Specialists',
        'administrators_in_community_health_organization'                        => 'Administrators in Community Health Organization',
        'community_health_workers'                                               => 'Community Health Workers',
        'counselors_mental_health_workers_social_workers'                        => 'Counselors/Mental Health Workers/Social Workers',
        'dentists'                                                               => 'Dentists',
        'education-related_professionals'                                        => 'Education-Related Professionals',
        'emts_firefighters_non-police_first_responders'                          => 'EMTs/Firefighters/Non-Police First Responders',
        'faith-based_professionals'                                              => 'Faith-Based Professionals',
        'family_parents_caregivers_of_people_experiencing_substance_use_disorder' => 'Family, Parents, Caregivers of People Experiencing Substance Use Disorder',
        'general_population'                                                     => 'General Population',
        'health_care_administrators'                                             => 'Health Care Administrators',
        'justice-related_professionals'                                          => 'Justice-Related Professionals',
        'local_government_staff'                                                 => 'Local Government Staff',
        'nurses_nurse_practitioners'                                             => 'Nurses/Nurse Practitioners',
        'peer_specialists'                                                       => 'Peer Specialists',
        'physicians'                                                             => 'Physicians',
        'physician_assistants'                                                   => 'Physician Assistants',
        'prevention_professionals'                                               => 'Prevention Professionals',
        'psychologists'                                                          => 'Psychologists',
        'students'                                                               => 'Students',
        'volunteers'                                                             => 'Volunteers',
    );

    public static function register_ajax() {
        add_action( 'wp_ajax_rit_tax_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_rit_tax_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    // =========================================================================
    //  Admin UI
    // =========================================================================

    public static function render_page() {
        $stored_file = get_transient( 'rit_stored_tax_csv_' . get_current_user_id() );
        ?>
        <div class="wrap rit-wrap" style="max-width:900px;">
            <h1>Taxonomy &amp; Audience Assigner</h1>

            <div class="card" id="rit-tax-form-card">
                <h2>Upload CSV</h2>
                <p>Upload a CSV with <code>Resource ID</code>, audience columns, and up to 3 sets of
                   <code>Resource Category</code> columns (Main / Sub / Sub Sub / Sub Sub Sub).</p>

                <form id="rit-tax-form" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'rit_tax_assign', 'rit_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csv_file">CSV File</label></th>
                            <td>
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" <?php echo $stored_file ? '' : 'required'; ?> />
                                <?php if ( $stored_file ) : ?>
                                    <p class="description">
                                        <strong>Previously uploaded:</strong> <?php echo esc_html( basename( $stored_file ) ); ?><br>
                                        Leave empty to re-use, or upload a new file.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mode</th>
                            <td>
                                <label><input type="radio" name="rit_mode" value="dry_run" checked /> Dry Run</label><br>
                                <label><input type="radio" name="rit_mode" value="live" /> Live Import</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rit_limit">Row Limit</label></th>
                            <td>
                                <input type="number" name="rit_limit" id="rit_limit" value="0" min="0" step="1" style="width:80px;" />
                                <p class="description">0 = no limit.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" id="rit-tax-submit" class="button button-primary">Assign Taxonomy &amp; Audiences</button>
                    </p>
                </form>
            </div>

            <!-- Progress -->
            <div class="card" id="rit-tax-progress" style="display:none;">
                <h2>Processing…</h2>
                <div style="background:#e0e0e0;border-radius:4px;overflow:hidden;height:24px;margin:10px 0;">
                    <div id="rit-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;border-radius:4px;"></div>
                </div>
                <p id="rit-progress-text">Starting…</p>
            </div>

            <!-- Mismatch Resolution Dialog -->
            <div class="card" id="rit-mismatch-dialog" style="display:none;border-left:4px solid #dba617;">
                <h2>⚠ Unrecognized Term</h2>
                <p id="rit-mismatch-context"></p>
                <p><strong>CSV value:</strong> <code id="rit-mismatch-value"></code></p>
                <p>
                    <label for="rit-mismatch-select">Select the correct match:</label><br>
                    <select id="rit-mismatch-select" style="min-width:400px;max-width:100%;"></select>
                </p>
                <p>
                    <button type="button" id="rit-mismatch-ok" class="button button-primary">Use Selection</button>
                    <button type="button" id="rit-mismatch-skip" class="button">Skip This Term</button>
                </p>
            </div>

            <!-- Results -->
            <div class="card rit-results" id="rit-tax-results" style="display:none;">
                <h2 id="rit-results-title">Results</h2>
                <table class="rit-stats-table widefat striped" style="max-width:420px;">
                    <tbody>
                        <tr><td>Total rows in CSV</td><td id="rit-stat-total">0</td></tr>
                        <tr><td>Resources updated</td><td id="rit-stat-updated">0</td></tr>
                        <tr><td>Resources not found</td><td id="rit-stat-not-found">0</td></tr>
                        <tr><td>Terms assigned</td><td id="rit-stat-terms">0</td></tr>
                        <tr><td>Terms skipped (mismatch)</td><td id="rit-stat-term-skipped">0</td></tr>
                        <tr><td>Errors</td><td id="rit-stat-errors">0</td></tr>
                    </tbody>
                </table>
                <h3>Log</h3>
                <div class="rit-log" id="rit-tax-log"></div>
            </div>
        </div>

        <script>
        (function(){
            var form        = document.getElementById('rit-tax-form');
            var submitBtn   = document.getElementById('rit-tax-submit');
            var formCard    = document.getElementById('rit-tax-form-card');
            var progressEl  = document.getElementById('rit-tax-progress');
            var progressBar = document.getElementById('rit-progress-bar');
            var progressText = document.getElementById('rit-progress-text');
            var resultsEl   = document.getElementById('rit-tax-results');
            var logEl       = document.getElementById('rit-tax-log');
            var dialogEl    = document.getElementById('rit-mismatch-dialog');
            var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            var stats = { total:0, updated:0, not_found:0, terms:0, term_skipped:0, errors:0 };
            var jobId = null;
            var MAX_RETRIES = 2;

            // User-resolved mappings: { "type:csv_value" => "resolved_value_or_SKIP" }
            var userMappings = {};

            // Pending mismatch callback.
            var mismatchCallback = null;

            function finishProcessing(titleText) {
                progressEl.style.display = 'none';
                formCard.style.display = '';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Assign Taxonomy & Audiences';
                document.getElementById('rit-results-title').textContent = titleText;
            }

            form.addEventListener('submit', function(e){
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Uploading…';

                var fd = new FormData(form);
                fd.append('action', 'rit_tax_start');

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data.success) {
                        alert('Error: ' + (data.data || 'Unknown error'));
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Assign Taxonomy & Audiences';
                        return;
                    }
                    jobId = data.data.job_id;
                    stats.total = data.data.total;

                    document.getElementById('rit-tax-form-card').style.display = 'none';
                    progressEl.style.display = '';
                    resultsEl.style.display = '';
                    logEl.innerHTML = '';
                    document.getElementById('rit-stat-total').textContent = stats.total;

                    processBatch(0, 0);
                })
                .catch(function(err){
                    alert('Request failed: ' + err);
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Assign Taxonomy & Audiences';
                });
            });

            function processBatch(offset, retries) {
                progressText.textContent = 'Processing rows ' + (offset+1) + '–' + Math.min(offset + <?php echo self::BATCH_SIZE; ?>, stats.total) + ' of ' + stats.total + '…';

                var fd = new FormData();
                fd.append('action', 'rit_tax_batch');
                fd.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'rit_tax_batch' ) ); ?>);
                fd.append('job_id', jobId);
                fd.append('offset', offset);
                fd.append('user_mappings', JSON.stringify(userMappings));

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(data){
                    if (!data.success) {
                        progressText.textContent = 'Error: ' + (data.data || 'Unknown error');
                        return;
                    }
                    var d = data.data;

                    // Check if there's a mismatch to resolve.
                    if (d.mismatch) {
                        appendLog(d.log);
                        updateStats(d);
                        showMismatchDialog(d.mismatch, offset);
                        return;
                    }

                    appendLog(d.log);
                    updateStats(d);

                    var pct = Math.min(100, Math.round((d.next_offset / stats.total) * 100));
                    progressBar.style.width = pct + '%';

                    if (d.done) {
                        var mode = d.mode || 'live';
                        finishProcessing(mode === 'dry_run' ? 'Dry Run Results' : 'Import Results');
                    } else {
                        processBatch(d.next_offset, 0);
                    }
                })
                .catch(function(err){
                    if (retries < MAX_RETRIES) {
                        addLogEntry('skip', 'Batch at offset ' + offset + ' failed (' + err + '). Retrying…');
                        setTimeout(function(){ processBatch(offset, retries + 1); }, 3000);
                    } else {
                        addLogEntry('error', 'Batch at offset ' + offset + ' failed after retries. Skipping.');
                        stats.errors += <?php echo self::BATCH_SIZE; ?>;
                        updateStatsUI();
                        var nextOffset = offset + <?php echo self::BATCH_SIZE; ?>;
                        if (nextOffset >= stats.total) {
                            finishProcessing('Import Results (completed with errors)');
                        } else {
                            processBatch(nextOffset, 0);
                        }
                    }
                });
            }

            function showMismatchDialog(mismatch, currentOffset) {
                dialogEl.style.display = '';
                progressEl.style.display = 'none';

                document.getElementById('rit-mismatch-context').textContent = mismatch.context;
                document.getElementById('rit-mismatch-value').textContent = mismatch.csv_value;

                var select = document.getElementById('rit-mismatch-select');
                select.innerHTML = '';
                mismatch.options.forEach(function(opt){
                    var o = document.createElement('option');
                    o.value = opt.value;
                    o.textContent = opt.label;
                    select.appendChild(o);
                });

                var onResolve = function(resolution) {
                    document.getElementById('rit-mismatch-ok').removeEventListener('click', okHandler);
                    document.getElementById('rit-mismatch-skip').removeEventListener('click', skipHandler);
                    dialogEl.style.display = 'none';
                    progressEl.style.display = '';

                    userMappings[mismatch.mapping_key] = resolution;

                    if (resolution === '__SKIP__') {
                        addLogEntry('skip', 'User skipped: "' + mismatch.csv_value + '"');
                    } else {
                        addLogEntry('ok', 'User mapped: "' + mismatch.csv_value + '" → "' + resolution + '"');
                    }

                    // Re-run from the same offset with the new mapping.
                    processBatch(currentOffset, 0);
                };

                var okHandler = function(){ onResolve(select.value); };
                var skipHandler = function(){ onResolve('__SKIP__'); };

                document.getElementById('rit-mismatch-ok').addEventListener('click', okHandler);
                document.getElementById('rit-mismatch-skip').addEventListener('click', skipHandler);
            }

            function appendLog(entries) {
                if (!entries) return;
                entries.forEach(function(entry){
                    addLogEntry(entry.level, entry.msg);
                });
            }

            function addLogEntry(level, msg) {
                var div = document.createElement('div');
                div.className = level;
                div.textContent = msg;
                logEl.appendChild(div);
                logEl.scrollTop = logEl.scrollHeight;
            }

            function updateStats(d) {
                stats.updated += (d.updated || 0);
                stats.not_found += (d.not_found || 0);
                stats.terms += (d.terms_assigned || 0);
                stats.term_skipped += (d.terms_skipped || 0);
                stats.errors += (d.errors || 0);
                updateStatsUI();
            }

            function updateStatsUI() {
                document.getElementById('rit-stat-updated').textContent = stats.updated;
                document.getElementById('rit-stat-not-found').textContent = stats.not_found;
                document.getElementById('rit-stat-terms').textContent = stats.terms;
                document.getElementById('rit-stat-term-skipped').textContent = stats.term_skipped;
                document.getElementById('rit-stat-errors').textContent = stats.errors;
            }
        })();
        </script>
        <?php
    }

    // =========================================================================
    //  AJAX: Start Job
    // =========================================================================

    public static function ajax_start() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( 'rit_tax_assign', 'rit_nonce' );

        $mode  = isset( $_POST['rit_mode'] ) && $_POST['rit_mode'] === 'live' ? 'live' : 'dry_run';
        $limit = isset( $_POST['rit_limit'] ) ? intval( $_POST['rit_limit'] ) : 0;

        $csv_path = self::resolve_csv_path();
        if ( is_wp_error( $csv_path ) ) {
            wp_send_json_error( $csv_path->get_error_message() );
        }

        $total = self::count_csv_rows( $csv_path );
        if ( is_wp_error( $total ) ) {
            wp_send_json_error( $total->get_error_message() );
        }

        if ( $limit > 0 && $limit < $total ) {
            $total = $limit;
        }

        $job_id = 'rit_tax_' . get_current_user_id() . '_' . time();
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

    public static function ajax_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( 'rit_tax_batch' );

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( $_POST['job_id'] ) : '';
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

        $job = get_transient( $job_id );
        if ( ! $job ) {
            wp_send_json_error( 'Job expired. Please re-upload.' );
        }

        $user_mappings = array();
        if ( ! empty( $_POST['user_mappings'] ) ) {
            $decoded = json_decode( stripslashes( $_POST['user_mappings'] ), true );
            if ( is_array( $decoded ) ) {
                $user_mappings = $decoded;
            }
        }

        $csv_path = $job['csv_path'];
        $mode     = $job['mode'];
        $total    = $job['total'];

        $batch = self::read_csv_slice( $csv_path, $offset, self::BATCH_SIZE );
        if ( is_wp_error( $batch ) ) {
            wp_send_json_error( $batch->get_error_message() );
        }

        // Collect Resource IDs for lookup.
        $batch_rids = array();
        foreach ( $batch as $row ) {
            $rid = isset( $row['Resource ID'] ) ? trim( $row['Resource ID'] ) : '';
            if ( $rid !== '' ) {
                $batch_rids[] = $rid;
            }
        }
        $id_map = ! empty( $batch_rids ) ? self::lookup_resource_ids( array_unique( $batch_rids ) ) : array();

        // Load taxonomy term tree.
        $term_tree = self::build_term_tree();

        // Build audience label-to-value map.
        $audience_map = array_flip( self::AUDIENCE_CHOICES );

        $log            = array();
        $updated        = 0;
        $not_found      = 0;
        $terms_assigned = 0;
        $terms_skipped  = 0;
        $errors         = 0;

        foreach ( $batch as $i => $row ) {
            $row_num     = $offset + $i + 2;
            $resource_id = isset( $row['Resource ID'] ) ? trim( $row['Resource ID'] ) : '';

            if ( $resource_id === '' ) {
                $log[] = array( 'level' => 'skip', 'msg' => "Row {$row_num}: No Resource ID." );
                $errors++;
                continue;
            }

            if ( ! isset( $id_map[ $resource_id ] ) ) {
                $log[] = array( 'level' => 'error', 'msg' => "Row {$row_num}: Resource ID {$resource_id} not found." );
                $not_found++;
                continue;
            }

            $post_id = $id_map[ $resource_id ];
            $title   = get_the_title( $post_id );

            // -----------------------------------------------------------------
            //  1. Resolve taxonomy terms.
            // -----------------------------------------------------------------
            $term_ids = array();

            for ( $cat_num = 1; $cat_num <= 3; $cat_num++ ) {
                $levels = array();
                foreach ( array( 'Main Category', 'Sub Category', 'Sub Sub Category', 'Sub Sub Sub Category' ) as $depth_label ) {
                    $col = "Resource Category {$cat_num} - {$depth_label}";
                    $val = isset( $row[ $col ] ) ? trim( $row[ $col ] ) : '';
                    if ( $val === '' ) {
                        break;
                    }
                    $levels[] = $val;
                }

                if ( empty( $levels ) ) {
                    continue;
                }

                // Walk the hierarchy to find the deepest term.
                $parent_id = 0;
                $resolved  = true;
                $last_term_id = null;

                for ( $depth = 0; $depth < count( $levels ); $depth++ ) {
                    $csv_val   = $levels[ $depth ];
                    $map_key   = 'tax:' . $parent_id . ':' . $csv_val;

                    // Check user mappings first.
                    if ( isset( $user_mappings[ $map_key ] ) ) {
                        if ( $user_mappings[ $map_key ] === '__SKIP__' ) {
                            $terms_skipped++;
                            $resolved = false;
                            break;
                        }
                        $last_term_id = intval( $user_mappings[ $map_key ] );
                        $parent_id    = $last_term_id;
                        continue;
                    }

                    // Try to find the term under the current parent.
                    $found_id = self::find_term_in_tree( $term_tree, $csv_val, $parent_id );

                    if ( $found_id === false ) {
                        // Mismatch — return partial results and ask the user.
                        $siblings = self::get_sibling_options( $term_tree, $parent_id );

                        wp_send_json_success( array(
                            'mismatch' => array(
                                'mapping_key' => $map_key,
                                'csv_value'   => $csv_val,
                                'context'     => "Row {$row_num}, Resource ID {$resource_id} \"{$title}\" — Category {$cat_num}, Level " . ( $depth + 1 ),
                                'options'     => $siblings,
                            ),
                            'log'             => $log,
                            'updated'         => $updated,
                            'not_found'       => $not_found,
                            'terms_assigned'  => $terms_assigned,
                            'terms_skipped'   => $terms_skipped,
                            'errors'          => $errors,
                            'next_offset'     => $offset,
                            'done'            => false,
                            'mode'            => $mode,
                        ) );
                        return; // Stop processing — JS will re-call with the resolution.
                    }

                    $last_term_id = $found_id;
                    $parent_id    = $found_id;
                }

                if ( $resolved && $last_term_id ) {
                    // Add the deepest term — WP will include ancestors automatically.
                    $term_ids[] = $last_term_id;
                    $terms_assigned++;
                }
            }

            // -----------------------------------------------------------------
            //  2. Resolve audience values.
            // -----------------------------------------------------------------
            $primary_values   = self::resolve_audience_values(
                isset( $row['target_audience'] ) ? $row['target_audience'] : '',
                $audience_map, $user_mappings, $log, $row_num, 'target_audience'
            );

            if ( isset( $primary_values['mismatch'] ) ) {
                wp_send_json_success( array(
                    'mismatch'       => $primary_values['mismatch'],
                    'log'            => $log,
                    'updated'        => $updated,
                    'not_found'      => $not_found,
                    'terms_assigned' => $terms_assigned,
                    'terms_skipped'  => $terms_skipped,
                    'errors'         => $errors,
                    'next_offset'    => $offset,
                    'done'           => false,
                    'mode'           => $mode,
                ) );
                return;
            }

            $secondary_values = self::resolve_audience_values(
                isset( $row['secondary_target_audience'] ) ? $row['secondary_target_audience'] : '',
                $audience_map, $user_mappings, $log, $row_num, 'secondary_target_audience'
            );

            if ( isset( $secondary_values['mismatch'] ) ) {
                wp_send_json_success( array(
                    'mismatch'       => $secondary_values['mismatch'],
                    'log'            => $log,
                    'updated'        => $updated,
                    'not_found'      => $not_found,
                    'terms_assigned' => $terms_assigned,
                    'terms_skipped'  => $terms_skipped,
                    'errors'         => $errors,
                    'next_offset'    => $offset,
                    'done'           => false,
                    'mode'           => $mode,
                ) );
                return;
            }

            // -----------------------------------------------------------------
            //  3. Apply changes.
            // -----------------------------------------------------------------
            if ( $mode === 'live' ) {
                if ( ! empty( $term_ids ) ) {
                    wp_set_object_terms( $post_id, $term_ids, 'resource-category', false );
                }
                if ( $primary_values['values'] !== null ) {
                    update_field( 'target_audience', $primary_values['values'], $post_id );
                }
                if ( $secondary_values['values'] !== null ) {
                    update_field( 'secondary_target_audience', $secondary_values['values'], $post_id );
                }
                // Mark resource as active now that audiences and categories are assigned.
                update_field( 'resource_status', 'active', $post_id );
            }

            $parts = array();
            if ( ! empty( $term_ids ) ) {
                $parts[] = count( $term_ids ) . ' category term(s)';
            }
            if ( ! empty( $primary_values['values'] ) ) {
                $parts[] = count( $primary_values['values'] ) . ' primary audience(s)';
            }
            if ( ! empty( $secondary_values['values'] ) ) {
                $parts[] = count( $secondary_values['values'] ) . ' secondary audience(s)';
            }
            $summary = ! empty( $parts ) ? implode( ', ', $parts ) . ', status → active' : 'no changes';

            $verb = $mode === 'live' ? 'Updated' : 'Would update';
            $log[] = array( 'level' => 'ok', 'msg' => "Row {$row_num}: {$verb} post #{$post_id} \"{$title}\" — {$summary}" );
            $updated++;
        }

        $next_offset = $offset + self::BATCH_SIZE;
        $done        = $next_offset >= $total;

        wp_send_json_success( array(
            'log'            => $log,
            'updated'        => $updated,
            'not_found'      => $not_found,
            'terms_assigned' => $terms_assigned,
            'terms_skipped'  => $terms_skipped,
            'errors'         => $errors,
            'next_offset'    => $next_offset,
            'done'           => $done,
            'mode'           => $mode,
        ) );
    }

    // =========================================================================
    //  Audience Resolution
    // =========================================================================

    /**
     * Parse a comma-separated audience string, resolving each label to its ACF value.
     * Returns array with 'values' key, or 'mismatch' key if one can't be resolved.
     */
    private static function resolve_audience_values( $raw, $label_to_value, &$user_mappings, &$log, $row_num, $field_name ) {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return array( 'values' => null );
        }

        $all_labels = array_keys( $label_to_value );

        // Smart split: try to reassemble known compound labels with commas.
        $parts = array_map( 'trim', explode( ',', $raw ) );
        $labels = array();
        $idx = 0;
        while ( $idx < count( $parts ) ) {
            $matched = false;
            for ( $lookahead = min( 6, count( $parts ) - $idx ); $lookahead > 0; $lookahead-- ) {
                $candidate = implode( ', ', array_slice( $parts, $idx, $lookahead ) );
                if ( isset( $label_to_value[ $candidate ] ) ) {
                    $labels[] = $candidate;
                    $idx += $lookahead;
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                $labels[] = $parts[ $idx ];
                $idx++;
            }
        }

        $values = array();

        foreach ( $labels as $label ) {
            $map_key = 'aud:' . $field_name . ':' . $label;

            // Check user mappings.
            if ( isset( $user_mappings[ $map_key ] ) ) {
                if ( $user_mappings[ $map_key ] !== '__SKIP__' ) {
                    $values[] = $user_mappings[ $map_key ];
                }
                continue;
            }

            if ( isset( $label_to_value[ $label ] ) ) {
                $values[] = $label_to_value[ $label ];
                continue;
            }

            // Mismatch — build options from all audience choices.
            $options = array();
            foreach ( self::AUDIENCE_CHOICES as $val => $lbl ) {
                $options[] = array( 'value' => $val, 'label' => $lbl );
            }

            return array(
                'mismatch' => array(
                    'mapping_key' => $map_key,
                    'csv_value'   => $label,
                    'context'     => "Row {$row_num} — {$field_name}",
                    'options'     => $options,
                ),
            );
        }

        return array( 'values' => $values );
    }

    // =========================================================================
    //  Taxonomy Helpers
    // =========================================================================

    /**
     * Build a tree structure of all resource-category terms.
     * Returns array of: term_id => [ 'name' => ..., 'parent' => ..., 'children' => [...] ]
     */
    private static function build_term_tree() {
        $terms = get_terms( array(
            'taxonomy'   => 'resource-category',
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        $tree = array();
        foreach ( $terms as $term ) {
            $tree[ $term->term_id ] = array(
                'name'   => $term->name,
                'parent' => $term->parent,
            );
        }

        return $tree;
    }

    /**
     * Find a term by name under a specific parent.
     *
     * @param  array  $tree       Term tree from build_term_tree().
     * @param  string $name       Term name to find.
     * @param  int    $parent_id  Parent term ID (0 for top-level).
     * @return int|false           Term ID or false if not found.
     */
    private static function find_term_in_tree( $tree, $name, $parent_id ) {
        $name_lower = strtolower( trim( $name ) );

        foreach ( $tree as $term_id => $data ) {
            if ( (int) $data['parent'] === (int) $parent_id && strtolower( trim( $data['name'] ) ) === $name_lower ) {
                return $term_id;
            }
        }

        return false;
    }

    /**
     * Get all terms that are children of a given parent, formatted for the dropdown.
     *
     * @param  array $tree       Term tree.
     * @param  int   $parent_id  Parent term ID (0 for top-level).
     * @return array              Array of [ 'value' => term_id, 'label' => name ].
     */
    private static function get_sibling_options( $tree, $parent_id ) {
        $options = array();

        foreach ( $tree as $term_id => $data ) {
            if ( (int) $data['parent'] === (int) $parent_id ) {
                $options[] = array(
                    'value' => (string) $term_id,
                    'label' => $data['name'],
                );
            }
        }

        usort( $options, function( $a, $b ) {
            return strcasecmp( $a['label'], $b['label'] );
        } );

        return $options;
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

            $stored_name = 'tax-upload-' . get_current_user_id() . '-' . date( 'Ymd-His' ) . '.csv';
            $stored_path = $dir . $stored_name;

            if ( ! move_uploaded_file( $_FILES['csv_file']['tmp_name'], $stored_path ) ) {
                return new WP_Error( 'csv_save', 'Could not save the uploaded file.' );
            }

            set_transient( 'rit_stored_tax_csv_' . get_current_user_id(), $stored_path, DAY_IN_SECONDS );

            return $stored_path;
        }

        $stored_path = get_transient( 'rit_stored_tax_csv_' . get_current_user_id() );

        if ( $stored_path && file_exists( $stored_path ) ) {
            return $stored_path;
        }

        return new WP_Error( 'csv_missing', 'No CSV file uploaded and no previously uploaded file available.' );
    }

    // =========================================================================
    //  CSV Helpers
    // =========================================================================

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

        $count     = 0;
        $col_count = count( $headers );
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === $col_count ) {
                $count++;
            }
        }

        fclose( $handle );
        return $count;
    }

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

        $current = 0;
        while ( $current < $offset && ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === $col_count ) {
                $current++;
            }
        }

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
}
