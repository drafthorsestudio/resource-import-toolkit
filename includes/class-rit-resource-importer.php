<?php
/**
 * Resource Importer Tool
 *
 * Imports resource posts from a CSV file, mapping fields to ACF
 * and linking Consultant authors via relationship field.
 * Non-consultant authors are stored in separate ACF text/email fields.
 *
 * Uploaded CSVs are persisted so a dry run can be followed by a live
 * import without re-uploading.
 *
 * @since   1.0.0
 * @version 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RIT_Resource_Importer {

    /**
     * Valid resource_type slugs.
     */
    private static $valid_resource_types = array(
        'assessment', 'audio', 'issue_brief', 'manual', 'online_training',
        'presentation', 'report', 'toolkit', 'trainer_tools',
        'training_curriculum', 'webinar', 'website', 'other',
    );

    /**
     * Valid training_level slugs.
     */
    private static $valid_training_levels = array( '101', '202', 'advanced' );

    // =========================================================================
    //  Admin UI
    // =========================================================================

    public static function render_page() {
        $stored_file = get_transient( 'rit_stored_csv_' . get_current_user_id() );
        ?>
        <div class="wrap rit-wrap">
            <h1>Resource Importer</h1>

            <div class="card">
                <h2>Upload Resource CSV</h2>
                <p>Upload the compiled or matched resources CSV to import each row as a new <strong>Resource</strong> post.<br>
                   Rows whose <code>ResourceID</code> already exists will be skipped (duplicate protection).</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'rit_resource_import', 'rit_nonce' ); ?>
                    <input type="hidden" name="action" value="rit_resource_import" />

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
                                    Dry Run (preview only — nothing is created)
                                </label><br>
                                <label>
                                    <input type="radio" name="rit_mode" value="live" />
                                    Live Import (creates posts)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rit_limit">Row Limit</label></th>
                            <td>
                                <input type="number" name="rit_limit" id="rit_limit" value="0" min="0" step="1" style="width:80px;" />
                                <p class="description">Max rows to import. Set to <strong>0</strong> for no limit (import all).</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( 'Import Resources', 'primary', 'submit', true ); ?>
                </form>
            </div>

            <?php self::render_results(); ?>
        </div>
        <?php
    }

    private static function render_results() {
        $results = get_transient( 'rit_importer_results_' . get_current_user_id() );
        if ( ! $results ) {
            return;
        }
        delete_transient( 'rit_importer_results_' . get_current_user_id() );

        if ( isset( $results['error'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $results['error'] ) . '</p></div>';
            return;
        }

        $is_dry = $results['mode'] === 'dry_run';
        $label  = $is_dry ? 'Dry Run Results (no posts created)' : 'Import Results';
        ?>
        <div class="card rit-results">
            <h2><?php echo esc_html( $label ); ?></h2>

            <table class="rit-stats-table widefat striped" style="max-width:420px;">
                <tbody>
                    <tr><td>Total rows in CSV</td><td><?php echo (int) $results['total']; ?></td></tr>
                    <tr><td>New posts created</td><td><?php echo (int) $results['imported']; ?></td></tr>
                    <tr><td>Existing posts updated</td><td><?php echo (int) $results['updated']; ?></td></tr>
                    <tr><td>— as Consultant</td><td><?php echo (int) $results['author_consultant']; ?></td></tr>
                    <tr><td>— as Individual/Org</td><td><?php echo (int) $results['author_individual']; ?></td></tr>
                    <tr><td>Skipped (invalid format)</td><td><?php echo (int) $results['skipped_format']; ?></td></tr>
                    <tr><td>Errors</td><td><?php echo (int) $results['errors']; ?></td></tr>
                </tbody>
            </table>

            <?php if ( ! empty( $results['log'] ) ) : ?>
                <h3>Log</h3>
                <div class="rit-log">
                    <?php foreach ( $results['log'] as $entry ) : ?>
                        <div class="<?php echo esc_attr( $entry['level'] ); ?>">
                            <?php echo esc_html( $entry['msg'] ); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    //  Form Handler
    // =========================================================================

    public static function handle_form() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'rit_resource_import', 'rit_nonce' );

        $mode  = isset( $_POST['rit_mode'] ) && $_POST['rit_mode'] === 'live' ? 'live' : 'dry_run';
        $limit = isset( $_POST['rit_limit'] ) ? intval( $_POST['rit_limit'] ) : 0;

        // Determine which CSV to use: new upload or previously stored file.
        $csv_path = self::resolve_csv_path();

        if ( is_wp_error( $csv_path ) ) {
            self::redirect_with_error( $csv_path->get_error_message() );
            return;
        }

        $rows = self::parse_csv( $csv_path );

        if ( is_wp_error( $rows ) ) {
            self::redirect_with_error( $rows->get_error_message() );
            return;
        }

        $existing_ids = self::get_existing_original_ids();

        $log               = array();
        $imported           = 0;
        $updated            = 0;
        $author_consultant  = 0;
        $author_individual  = 0;
        $skipped_format     = 0;
        $errors             = 0;

        foreach ( $rows as $i => $row ) {
            $row_num     = $i + 2;
            $resource_id = isset( $row['ResourceID'] ) ? trim( $row['ResourceID'] ) : '';
            $title       = isset( $row['Title'] ) ? trim( $row['Title'] ) : '';

            if ( $title === '' ) {
                $log[]  = array( 'level' => 'skip', 'msg' => "Row {$row_num}: Skipped — no title." );
                $errors++;
                continue;
            }

            $format_raw    = isset( $row['Format'] ) ? trim( $row['Format'] ) : '';
            $resource_type = self::map_resource_type( $format_raw );

            if ( $format_raw !== '' && $resource_type === false ) {
                $log[]  = array( 'level' => 'skip', 'msg' => "Row {$row_num}: Skipped — Format \"{$format_raw}\" has no matching Resource Type — \"{$title}\"" );
                $skipped_format++;
                continue;
            }

            $import        = self::build_import_data( $row, $resource_type );
            $is_consultant = $import['author_type'] === 'consultant';

            // Build a descriptive author label for logging.
            if ( $is_consultant && ! empty( $import['consultant_ids'] ) && $import['organization_or_individual_name'] !== '' ) {
                $author_label = count( $import['consultant_ids'] ) . ' consultant(s) + non-consultant co-author(s)';
            } elseif ( $is_consultant && count( $import['consultant_ids'] ) > 1 ) {
                $author_label = count( $import['consultant_ids'] ) . ' consultants';
            } elseif ( $is_consultant ) {
                $author_label = 'consultant';
            } else {
                $author_label = 'individual/org';
            }

            // Check if this resource already exists.
            $existing_post_id = ( $resource_id !== '' && isset( $existing_ids[ $resource_id ] ) )
                ? $existing_ids[ $resource_id ]
                : null;

            if ( $existing_post_id ) {
                // UPDATE existing post.
                if ( $mode === 'live' ) {
                    $result = self::update_resource_post( $existing_post_id, $import );

                    if ( is_wp_error( $result ) ) {
                        $log[] = array( 'level' => 'error', 'msg' => "Row {$row_num}: ERROR updating post #{$existing_post_id} — \"{$title}\" — " . $result->get_error_message() );
                        $errors++;
                        continue;
                    }

                    $log[] = array( 'level' => 'ok', 'msg' => "Row {$row_num}: Updated post #{$existing_post_id} — \"{$title}\" ({$author_label})" );
                } else {
                    $log[] = array( 'level' => 'ok', 'msg' => "Row {$row_num}: Would update post #{$existing_post_id} — \"{$title}\" (Author: {$author_label})" );
                }

                $updated++;

            } else {
                // CREATE new post.
                if ( $mode === 'live' ) {
                    $result = self::create_resource_post( $import );

                    if ( is_wp_error( $result ) ) {
                        $log[] = array( 'level' => 'error', 'msg' => "Row {$row_num}: ERROR creating \"{$title}\" — " . $result->get_error_message() );
                        $errors++;
                        continue;
                    }

                    $log[] = array( 'level' => 'ok', 'msg' => "Row {$row_num}: Created post #{$result} — \"{$title}\" ({$author_label})" );

                    if ( $resource_id !== '' ) {
                        $existing_ids[ $resource_id ] = $result;
                    }
                } else {
                    $log[] = array( 'level' => 'ok', 'msg' => "Row {$row_num}: Would import — \"{$title}\" (Format: {$format_raw}, Author: {$author_label})" );
                }

                $imported++;
            }

            if ( $is_consultant ) {
                $author_consultant++;
            } else {
                $author_individual++;
            }

            $processed = $imported + $updated;
            if ( $limit > 0 && $processed >= $limit ) {
                $log[] = array( 'level' => 'skip', 'msg' => "Reached row limit of {$limit}. Stopping." );
                break;
            }
        }

        set_transient( 'rit_importer_results_' . get_current_user_id(), array(
            'mode'              => $mode,
            'total'             => count( $rows ),
            'imported'          => $imported,
            'updated'           => $updated,
            'author_consultant' => $author_consultant,
            'author_individual' => $author_individual,
            'skipped_format'    => $skipped_format,
            'errors'            => $errors,
            'log'               => $log,
        ), 600 );

        wp_safe_redirect( admin_url( 'admin.php?page=rit-resource-importer' ) );
        exit;
    }

    // =========================================================================
    //  CSV Persistence
    // =========================================================================

    /**
     * Resolve the CSV file path. If a new file was uploaded, save it to the
     * exports directory and store the path in a transient. If no file was
     * uploaded, fall back to the previously stored file.
     *
     * @return string|WP_Error  File path or error.
     */
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

            $stored_name = 'upload-' . get_current_user_id() . '-' . date( 'Ymd-His' ) . '.csv';
            $stored_path = $dir . $stored_name;

            if ( ! move_uploaded_file( $_FILES['csv_file']['tmp_name'], $stored_path ) ) {
                return new WP_Error( 'csv_save', 'Could not save the uploaded file.' );
            }

            // Store for 24 hours.
            set_transient( 'rit_stored_csv_' . get_current_user_id(), $stored_path, DAY_IN_SECONDS );

            return $stored_path;
        }

        // No new upload — try the stored file.
        $stored_path = get_transient( 'rit_stored_csv_' . get_current_user_id() );

        if ( $stored_path && file_exists( $stored_path ) ) {
            return $stored_path;
        }

        return new WP_Error( 'csv_missing', 'No CSV file uploaded and no previously uploaded file available.' );
    }

    // =========================================================================
    //  Data Mapping
    // =========================================================================

    private static function map_resource_type( $format ) {
        if ( $format === '' ) {
            return '';
        }

        $slug = strtolower( trim( $format ) );
        $slug = str_replace( ' ', '_', $slug );

        if ( in_array( $slug, self::$valid_resource_types, true ) ) {
            return $slug;
        }

        return false;
    }

    private static function map_training_level( $level ) {
        $level = trim( $level );
        if ( $level === '' ) {
            return '';
        }

        $slug = strtolower( str_replace( ' ', '_', $level ) );
        if ( in_array( $slug, self::$valid_training_levels, true ) ) {
            return $slug;
        }

        if ( strpos( $level, '/' ) !== false ) {
            $parts = explode( '/', $level );
            $first = strtolower( trim( $parts[0] ) );
            if ( in_array( $first, self::$valid_training_levels, true ) ) {
                return $first;
            }
        }

        return '';
    }

    private static function convert_date( $date ) {
        $date = trim( $date );
        if ( $date === '' ) {
            return '';
        }

        $ts = strtotime( $date );
        if ( $ts === false ) {
            return '';
        }

        return date( 'Ymd', $ts );
    }

    /**
     * Build a structured array of all data needed to create one resource post.
     *
     * If Consultant ID is present → author_type = consultant, populate material_author.
     * Author logic (pipe-delimited):
     *
     * 1. No Consultant ID          → author_type = individual_organization,
     *                                  Author Name → organization_or_individual_name,
     *                                  Author Email → organization_or_individual_email.
     * 2. Single Consultant ID       → author_type = consultant,
     *                                  material_author = [id].
     * 3. Single ID + trailing pipes → author_type = consultant,
     *                                  material_author = [first id],
     *                                  remaining names/emails → org/individual fields (comma-separated).
     * 4. Multiple IDs (all filled)  → author_type = consultant,
     *                                  material_author = [all ids].
     */
    private static function build_import_data( $row, $resource_type ) {
        $external_link    = isset( $row['External Resource Link'] ) ? trim( $row['External Resource Link'] ) : '';
        $consultant_id_raw = isset( $row['Consultant ID'] ) ? trim( $row['Consultant ID'] ) : '';
        $author_name_raw  = isset( $row['Author Name'] ) ? trim( $row['Author Name'] ) : '';
        $author_email_raw = isset( $row['Author Email'] ) ? trim( $row['Author Email'] ) : '';

        // Parse pipe-delimited values.
        $id_parts    = $consultant_id_raw !== '' ? array_map( 'trim', explode( '|', $consultant_id_raw ) ) : array();
        $name_parts  = $author_name_raw !== '' ? array_map( 'trim', explode( '|', $author_name_raw ) ) : array();
        $email_parts = $author_email_raw !== '' ? array_map( 'trim', explode( '|', $author_email_raw ) ) : array();

        // Separate real consultant IDs from empty pipe slots.
        $real_ids = array_filter( $id_parts, function( $v ) { return $v !== ''; } );

        $author_type        = 'individual_organization';
        $consultant_ids     = array();
        $non_con_names      = '';
        $non_con_emails     = '';

        if ( empty( $real_ids ) ) {
            // Scenario 1: No consultant IDs at all.
            $non_con_names  = $author_name_raw;
            $non_con_emails = $author_email_raw;

        } elseif ( count( $real_ids ) === 1 && count( $id_parts ) === 1 ) {
            // Scenario 2: Single consultant ID, no pipes.
            $author_type    = 'consultant';
            $consultant_ids = array_values( $real_ids );

        } elseif ( count( $real_ids ) === 1 && count( $id_parts ) > 1 ) {
            // Scenario 3: Single ID + trailing pipe(s) = mixed authors.
            $author_type    = 'consultant';
            $consultant_ids = array_values( $real_ids );

            // First name/email goes to the consultant; the rest are non-consultant.
            $remaining_names  = array_slice( $name_parts, 1 );
            $remaining_emails = array_slice( $email_parts, 1 );

            $non_con_names  = implode( ', ', array_filter( $remaining_names, function( $v ) { return $v !== ''; } ) );
            $non_con_emails = implode( ', ', array_filter( $remaining_emails, function( $v ) { return $v !== ''; } ) );

        } else {
            // Scenario 4: Multiple real consultant IDs — all are consultants.
            $author_type    = 'consultant';
            $consultant_ids = array_values( $real_ids );
        }

        return array(
            'post_title'                       => isset( $row['Title'] ) ? trim( $row['Title'] ) : '',
            'resource_original_id'             => isset( $row['ResourceID'] ) ? trim( $row['ResourceID'] ) : '',
            'resource_status'                  => 'waiting',
            'resource_description'             => isset( $row['Description'] ) ? $row['Description'] : '',
            'resource_type'                    => $resource_type,
            'author_type'                      => $author_type,
            'consultant_ids'                   => $consultant_ids,
            'organization_or_individual_name'  => $non_con_names,
            'organization_or_individual_email' => $non_con_emails,
            'training_level'                   => self::map_training_level( isset( $row['Training Level'] ) ? $row['Training Level'] : '' ),
            'added_by_name'                    => isset( $row['Added By Name'] ) ? trim( $row['Added By Name'] ) : '',
            'added_by_email'                   => isset( $row['Added By Email'] ) ? trim( $row['Added By Email'] ) : '',
            'date_added'                       => self::convert_date( isset( $row['Date Added'] ) ? $row['Date Added'] : '' ),
            'external_link'                    => $external_link,
        );
    }

    // =========================================================================
    //  Post Creation
    // =========================================================================

    private static function create_resource_post( $data ) {
        $post_id = wp_insert_post( array(
            'post_type'   => 'resource',
            'post_title'  => $data['post_title'],
            'post_status' => 'publish',
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Simple ACF fields.
        update_field( 'resource_original_id', $data['resource_original_id'], $post_id );
        update_field( 'resource_status', $data['resource_status'], $post_id );
        update_field( 'resource_description', $data['resource_description'], $post_id );
        update_field( 'author_type', $data['author_type'], $post_id );
        update_field( 'added_by_name', $data['added_by_name'], $post_id );
        update_field( 'added_by_email', $data['added_by_email'], $post_id );

        if ( $data['resource_type'] !== '' ) {
            update_field( 'resource_type', $data['resource_type'], $post_id );
        }

        if ( $data['training_level'] !== '' ) {
            update_field( 'training_level', $data['training_level'], $post_id );
        }

        if ( $data['date_added'] !== '' ) {
            update_field( 'date_added', $data['date_added'], $post_id );
        }

        // Author handling.
        if ( $data['author_type'] === 'consultant' && ! empty( $data['consultant_ids'] ) ) {
            // Validate each consultant ID and build the relationship array.
            $valid_ids = array();
            foreach ( $data['consultant_ids'] as $cid ) {
                $cid = intval( $cid );
                if ( $cid > 0 && get_post_type( $cid ) === 'consultant' ) {
                    $valid_ids[] = $cid;
                }
            }
            if ( ! empty( $valid_ids ) ) {
                update_field( 'material_author', $valid_ids, $post_id );
            }
            // Non-consultant co-authors (mixed scenario).
            if ( $data['organization_or_individual_name'] !== '' ) {
                update_field( 'organization_or_individual_name', $data['organization_or_individual_name'], $post_id );
            }
            if ( $data['organization_or_individual_email'] !== '' ) {
                update_field( 'organization_or_individual_email', $data['organization_or_individual_email'], $post_id );
            }
        } elseif ( $data['author_type'] === 'individual_organization' ) {
            if ( $data['organization_or_individual_name'] !== '' ) {
                update_field( 'organization_or_individual_name', $data['organization_or_individual_name'], $post_id );
            }
            if ( $data['organization_or_individual_email'] !== '' ) {
                update_field( 'organization_or_individual_email', $data['organization_or_individual_email'], $post_id );
            }
        }

        // Resource Links (repeater) — only if external link is present.
        if ( $data['external_link'] !== '' ) {
            $repeater_row = array(
                'resource_link_label'    => 'Open External Resource.',
                'resource_external_link' => $data['external_link'],
                'resource_internal_file' => '',
            );
            update_field( 'resource_links', array( $repeater_row ), $post_id );
        }

        return $post_id;
    }

    /**
     * Update an existing resource post with all fields from the CSV row.
     *
     * @param  int           $post_id  Existing post ID.
     * @param  array         $data     Structured import data from build_import_data().
     * @return int|WP_Error             Post ID on success, WP_Error on failure.
     */
    private static function update_resource_post( $post_id, $data ) {
        // Update post title.
        $result = wp_update_post( array(
            'ID'         => $post_id,
            'post_title' => $data['post_title'],
        ), true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Simple ACF fields.
        update_field( 'resource_original_id', $data['resource_original_id'], $post_id );
        update_field( 'resource_status', $data['resource_status'], $post_id );
        update_field( 'resource_description', $data['resource_description'], $post_id );
        update_field( 'author_type', $data['author_type'], $post_id );
        update_field( 'added_by_name', $data['added_by_name'], $post_id );
        update_field( 'added_by_email', $data['added_by_email'], $post_id );

        if ( $data['resource_type'] !== '' ) {
            update_field( 'resource_type', $data['resource_type'], $post_id );
        }

        if ( $data['training_level'] !== '' ) {
            update_field( 'training_level', $data['training_level'], $post_id );
        }

        if ( $data['date_added'] !== '' ) {
            update_field( 'date_added', $data['date_added'], $post_id );
        }

        // Author handling.
        if ( $data['author_type'] === 'consultant' && ! empty( $data['consultant_ids'] ) ) {
            $valid_ids = array();
            foreach ( $data['consultant_ids'] as $cid ) {
                $cid = intval( $cid );
                if ( $cid > 0 && get_post_type( $cid ) === 'consultant' ) {
                    $valid_ids[] = $cid;
                }
            }
            if ( ! empty( $valid_ids ) ) {
                update_field( 'material_author', $valid_ids, $post_id );
            }
            // Non-consultant co-authors (mixed scenario) or clear if none.
            update_field( 'organization_or_individual_name', $data['organization_or_individual_name'], $post_id );
            update_field( 'organization_or_individual_email', $data['organization_or_individual_email'], $post_id );
        } elseif ( $data['author_type'] === 'individual_organization' ) {
            if ( $data['organization_or_individual_name'] !== '' ) {
                update_field( 'organization_or_individual_name', $data['organization_or_individual_name'], $post_id );
            }
            if ( $data['organization_or_individual_email'] !== '' ) {
                update_field( 'organization_or_individual_email', $data['organization_or_individual_email'], $post_id );
            }
            // Clear consultant relationship.
            update_field( 'material_author', array(), $post_id );
        }

        // Resource Links (repeater) — only if external link is present.
        if ( $data['external_link'] !== '' ) {
            $repeater_row = array(
                'resource_link_label'    => 'Open External Resource.',
                'resource_external_link' => $data['external_link'],
                'resource_internal_file' => '',
            );
            update_field( 'resource_links', array( $repeater_row ), $post_id );
        }

        return $post_id;
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private static function parse_csv( $filepath ) {
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_read', 'Could not read the uploaded CSV file.' );
        }

        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', 'The CSV file appears to be empty.' );
        }

        $headers = array_map( function ( $h ) {
            return trim( $h, "\xEF\xBB\xBF \t\n\r\0\x0B" );
        }, $headers );

        $required = array( 'Title', 'ResourceID' );
        foreach ( $required as $col ) {
            if ( ! in_array( $col, $headers, true ) ) {
                fclose( $handle );
                return new WP_Error( 'csv_columns', "CSV must contain a \"{$col}\" column." );
            }
        }

        $rows = array();
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === count( $headers ) ) {
                $rows[] = array_combine( $headers, $data );
            }
        }

        fclose( $handle );
        return $rows;
    }

    /**
     * Get all existing resource_original_id values mapped to their post IDs.
     *
     * @return array  Associative array of original_id => post_id.
     */
    private static function get_existing_original_ids() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT pm.meta_value AS original_id, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'resource_original_id'
             AND pm.meta_value != ''
             AND p.post_type = 'resource'
             AND p.post_status != 'trash'",
            OBJECT
        );

        $map = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $map[ $row->original_id ] = (int) $row->post_id;
            }
        }

        return $map;
    }

    private static function redirect_with_error( $message ) {
        set_transient( 'rit_importer_results_' . get_current_user_id(), array( 'error' => $message ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=rit-resource-importer' ) );
        exit;
    }
}
