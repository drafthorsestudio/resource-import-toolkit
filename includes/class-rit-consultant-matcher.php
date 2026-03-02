<?php
/**
 * Consultant Matcher Tool
 *
 * Matches CSV Author Email / Author Name against Consultant custom posts
 * and generates matched, unmatched, and compiled CSV exports.
 *
 * v3.5.0 changes:
 * - Skips rows that already have a Consultant ID (preserves pipe-delimited IDs)
 * - Handles pipe-delimited Author Names: splits and matches each individually
 * - Handles pipe-delimited Author Emails: pairs with corresponding names
 * - Outputs matched IDs as pipe-delimited for multi-author rows
 * - Adds last-name validation to fuzzy matching to reduce false positives
 * - Added "Dr" prefix stripping and additional credentials (FAAFP, RSPS, LCAS, CCS, LP)
 *
 * @since   3.0.0
 * @version 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RIT_Consultant_Matcher {

    // =========================================================================
    //  Admin UI
    // =========================================================================

    public static function render_page() {
        ?>
        <div class="wrap rit-wrap">
            <h1>Consultant Matcher</h1>

            <div class="card">
                <h2>Upload CSV</h2>
                <p>Upload a CSV file containing <code>Author Email</code> and <code>Author Name</code> columns.
                   The plugin will attempt to match each row against existing <strong>Consultant</strong> posts.</p>
                <p>Rows that already have a <code>Consultant ID</code> will be preserved as-is (including pipe-delimited multi-author IDs).<br>
                   Pipe-delimited author names are split and matched individually.</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'rit_consultant_match', 'rit_nonce' ); ?>
                    <input type="hidden" name="action" value="rit_consultant_match" />

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csv_file">CSV File</label></th>
                            <td><input type="file" name="csv_file" id="csv_file" accept=".csv" required /></td>
                        </tr>
                    </table>

                    <?php submit_button( 'Process CSV', 'primary', 'submit', true ); ?>
                </form>
            </div>

            <?php self::render_results(); ?>
        </div>
        <?php
    }

    private static function render_results() {
        $results = get_transient( 'rit_matcher_results_' . get_current_user_id() );
        if ( ! $results ) {
            return;
        }
        delete_transient( 'rit_matcher_results_' . get_current_user_id() );

        if ( isset( $results['error'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $results['error'] ) . '</p></div>';
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'] . '/rit-exports/';
        ?>
        <div class="card rit-results">
            <h2>Results</h2>

            <table class="rit-stats-table widefat striped" style="max-width:420px;">
                <tbody>
                    <tr><td>Total rows in CSV</td><td><?php echo (int) $results['total']; ?></td></tr>
                    <tr><td>Preserved (already had ID)</td><td><?php echo (int) $results['preserved']; ?></td></tr>
                    <tr><td>Exact name matches</td><td><?php echo (int) $results['exact_name']; ?></td></tr>
                    <tr><td>Fuzzy name matches</td><td><?php echo (int) $results['fuzzy_name']; ?></td></tr>
                    <tr><td>Exact email matches</td><td><?php echo (int) $results['exact_email']; ?></td></tr>
                    <tr><td>Fuzzy email matches</td><td><?php echo (int) $results['fuzzy_email']; ?></td></tr>
                    <tr><td>Partially matched (multi-author)</td><td><?php echo (int) $results['partial']; ?></td></tr>
                    <tr><td>Unmatched rows</td><td><?php echo (int) $results['unmatched']; ?></td></tr>
                </tbody>
            </table>

            <p style="margin-top:15px;">
                <span class="dashicons dashicons-download"></span>
                <a href="<?php echo esc_url( $base_url . $results['compiled_file'] ); ?>">Download Compiled CSV (all rows — ready for review)</a>
            </p>

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
        check_admin_referer( 'rit_consultant_match', 'rit_nonce' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            self::redirect_with_error( 'File upload failed. Please try again.' );
            return;
        }

        $rows = self::parse_csv( $_FILES['csv_file']['tmp_name'] );
        if ( is_wp_error( $rows ) ) {
            self::redirect_with_error( $rows->get_error_message() );
            return;
        }

        $consultants = self::load_consultants();

        $log   = array();
        $stats = array(
            'exact_name'  => 0,
            'fuzzy_name'  => 0,
            'exact_email' => 0,
            'fuzzy_email' => 0,
            'preserved'   => 0,
            'partial'     => 0,
            'unmatched'   => 0,
        );

        $output_rows = array();

        foreach ( $rows as $i => $row ) {
            $row_num      = $i + 2;
            $author_name  = isset( $row['Author Name'] ) ? trim( $row['Author Name'] ) : '';
            $author_email = isset( $row['Author Email'] ) ? trim( $row['Author Email'] ) : '';
            $existing_id  = isset( $row['Consultant ID'] ) ? trim( $row['Consultant ID'] ) : '';

            // Skip rows that already have a Consultant ID.
            if ( $existing_id !== '' ) {
                $row['Match Type'] = isset( $row['Match Type'] ) ? $row['Match Type'] : 'preserved';
                $log[] = array( 'level' => 'ok', 'msg' => "Row {$row_num}: \"{$author_name}\" — already has ID ({$existing_id}). Preserved." );
                $stats['preserved']++;
                $output_rows[] = $row;
                continue;
            }

            // Split pipe-delimited authors.
            $names  = array_map( 'trim', explode( '|', $author_name ) );
            $emails = array_map( 'trim', explode( '|', $author_email ) );

            // Pad emails to match names length.
            while ( count( $emails ) < count( $names ) ) {
                $emails[] = '';
            }

            $matched_ids   = array();
            $match_types   = array();
            $author_log    = array();
            $all_matched   = true;

            foreach ( $names as $idx => $name ) {
                $email = isset( $emails[ $idx ] ) ? $emails[ $idx ] : '';

                if ( $name === '' && $email === '' ) {
                    $matched_ids[]  = '';
                    $match_types[]  = '';
                    $all_matched    = false;
                    continue;
                }

                $match = self::find_consultant_match( $email, $name, $consultants );

                if ( $match ) {
                    $matched_ids[]  = $match['id'];
                    $match_types[]  = $match['type'];
                    $stats[ $match['type'] ]++;

                    $matched_title = get_the_title( $match['id'] );
                    $author_log[]  = "\"{$name}\" → #{$match['id']} \"{$matched_title}\" ({$match['type']})";
                } else {
                    $matched_ids[] = '';
                    $match_types[] = '';
                    $all_matched   = false;
                    $author_log[]  = "\"{$name}\" — no match";
                }
            }

            // Build pipe-delimited output.
            $row['Consultant ID'] = implode( '|', $matched_ids );
            $row['Match Type']    = implode( '|', $match_types );

            // Determine overall row status.
            $has_any_match = (bool) array_filter( $matched_ids );

            if ( $has_any_match && $all_matched ) {
                $log_msg = "Row {$row_num}: " . implode( '; ', $author_log );
                $log[]   = array( 'level' => 'ok', 'msg' => $log_msg );
            } elseif ( $has_any_match ) {
                $log_msg = "Row {$row_num}: PARTIAL — " . implode( '; ', $author_log );
                $log[]   = array( 'level' => 'skip', 'msg' => $log_msg );
                $stats['partial']++;
            } else {
                $log_msg = "Row {$row_num}: " . implode( '; ', $author_log );
                $log[]   = array( 'level' => 'error', 'msg' => $log_msg );
                $stats['unmatched']++;
            }

            $output_rows[] = $row;
        }

        // Write compiled output CSV.
        $timestamp     = date( 'Y-m-d_His' );
        $compiled_file = "compiled-{$timestamp}.csv";
        self::write_output_csv( $compiled_file, $output_rows );

        set_transient( 'rit_matcher_results_' . get_current_user_id(), array(
            'total'         => count( $rows ),
            'preserved'     => $stats['preserved'],
            'exact_name'    => $stats['exact_name'],
            'fuzzy_name'    => $stats['fuzzy_name'],
            'exact_email'   => $stats['exact_email'],
            'fuzzy_email'   => $stats['fuzzy_email'],
            'partial'       => $stats['partial'],
            'unmatched'     => $stats['unmatched'],
            'compiled_file' => $compiled_file,
            'log'           => $log,
        ), 600 );

        wp_safe_redirect( admin_url( 'admin.php?page=rit-consultant-matcher' ) );
        exit;
    }

    // =========================================================================
    //  Matching Logic
    // =========================================================================

    private static function load_consultants() {
        $consultants = array();

        $query = new WP_Query( array(
            'post_type'      => 'consultant',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ) );

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post_id ) {
                $title = get_the_title( $post_id );
                $consultants[] = array(
                    'id'               => $post_id,
                    'title'            => $title,
                    'normalized_title' => self::normalize_name( $title ),
                    'email'            => strtolower( trim( (string) get_field( 'email', $post_id ) ) ),
                );
            }
        }

        return $consultants;
    }

    /**
     * Find a matching consultant post.
     *
     * 1. Exact name match (normalized).
     * 2. Fuzzy name match (≥85% similarity OR ≤2 Levenshtein) WITH last-name validation.
     * 3. Exact email match.
     * 4. Fuzzy email match (≥85% similarity OR ≤3 Levenshtein).
     */
    private static function find_consultant_match( $author_email, $author_name, $consultants ) {
        $email_lower     = strtolower( trim( $author_email ) );
        $name_normalized = self::normalize_name( $author_name );

        // Extract last name for fuzzy validation.
        $author_last = self::extract_last_name( $name_normalized );

        // 1. Exact name match.
        if ( $name_normalized !== '' ) {
            foreach ( $consultants as $c ) {
                if ( $c['normalized_title'] === $name_normalized ) {
                    return array( 'id' => $c['id'], 'type' => 'exact_name' );
                }
            }
        }

        // 2. Fuzzy name match with last-name validation.
        if ( $name_normalized !== '' && $author_last !== '' ) {
            $best_score = 0;
            $best_match = null;

            foreach ( $consultants as $c ) {
                if ( $c['normalized_title'] === '' ) {
                    continue;
                }

                similar_text( $name_normalized, $c['normalized_title'], $pct );
                $lev = levenshtein( $name_normalized, $c['normalized_title'] );

                if ( ( $pct >= 85 || $lev <= 2 ) && $pct > $best_score ) {
                    // Validate: last names must also fuzzy-match.
                    $c_last = self::extract_last_name( $c['normalized_title'] );
                    if ( $c_last !== '' ) {
                        similar_text( $author_last, $c_last, $last_pct );
                        $last_lev = levenshtein( $author_last, $c_last );
                        if ( $last_pct < 80 && $last_lev > 2 ) {
                            continue; // Last names are too different — skip.
                        }
                    }

                    $best_score = $pct;
                    $best_match = $c;
                }
            }

            if ( $best_match ) {
                return array( 'id' => $best_match['id'], 'type' => 'fuzzy_name' );
            }
        }

        // 3. Exact email match.
        if ( $email_lower !== '' ) {
            foreach ( $consultants as $c ) {
                if ( $c['email'] !== '' && $email_lower === $c['email'] ) {
                    return array( 'id' => $c['id'], 'type' => 'exact_email' );
                }
            }
        }

        // 4. Fuzzy email match — require same domain.
        if ( $email_lower !== '' ) {
            $author_domain = strstr( $email_lower, '@' );
            $best_score    = 0;
            $best_match    = null;

            foreach ( $consultants as $c ) {
                if ( $c['email'] === '' ) {
                    continue;
                }

                // Only fuzzy-match emails with the same domain.
                $c_domain = strstr( $c['email'], '@' );
                if ( $author_domain !== $c_domain ) {
                    continue;
                }

                similar_text( $email_lower, $c['email'], $pct );
                $lev = levenshtein( $email_lower, $c['email'] );

                if ( ( $pct >= 85 || $lev <= 3 ) && $pct > $best_score ) {
                    $best_score = $pct;
                    $best_match = $c;
                }
            }

            if ( $best_match ) {
                return array( 'id' => $best_match['id'], 'type' => 'fuzzy_email' );
            }
        }

        return null;
    }

    /**
     * Extract the last name (last word) from a normalized name string.
     */
    private static function extract_last_name( $normalized_name ) {
        $parts = explode( ' ', trim( $normalized_name ) );
        return count( $parts ) > 0 ? end( $parts ) : '';
    }

    /**
     * Normalize a name: strip credentials, honorifics, handle "Last, First", lowercase.
     */
    private static function normalize_name( $name ) {
        $name = trim( $name );
        if ( $name === '' ) {
            return '';
        }

        // Strip "Dr." / "Dr" prefix.
        $name = preg_replace( '/^\s*Dr\.?\s+/i', '', $name );

        // Strip "Illustrator:" and similar prefixes.
        $name = preg_replace( '/^\s*\w+:\s*/', '', $name );

        $credentials = array(
            'PhD', 'PharmD', 'PsyD', 'EdD', 'DrPH', 'ScD', 'DMin', 'DBA', 'JD', 'DDS', 'DMD', 'DO', 'DPM', 'DC',
            'MD', 'MS', 'MSW', 'MSN', 'MSc', 'MA', 'MBA', 'MPA', 'MPH', 'MPP', 'MDiv', 'MEd', 'MFA', 'MHS',
            'BSN', 'BS', 'BA', 'BSW',
            'RN', 'LPN', 'NP', 'CNS', 'CRNA', 'CNM', 'APRN', 'FNP',
            'LCSW', 'LMSW', 'LMFT', 'LPC', 'LCPC', 'LCMHC', 'LMHC', 'LPCC', 'LSW',
            'LCAS', 'CCS', 'LP', 'RSPS', 'LICSW',
            'BCBA', 'CPA', 'PE', 'RA', 'AIA', 'FACHE', 'FAAN', 'FACP', 'FACS', 'FAAFP', 'FAAP',
            'PA-C', 'PA', 'OT', 'PT', 'DPT', 'SLP', 'CCC-SLP',
            'CADC', 'CASAC', 'CAP', 'CRC', 'CARN', 'NCAC',
            'Jr', 'Sr', 'II', 'III', 'IV',
            'Esq', 'Ret',
        );

        // Remove anything in brackets or parentheses.
        $name = preg_replace( '/\[.*?\]|\(.*?\)/', '', $name );

        // Remove curly/smart quotes.
        $name = str_replace(
            array( "\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99" ),
            '', $name
        );

        if ( strpos( $name, ',' ) !== false ) {
            $parts        = explode( ',', $name );
            $cred_pattern = '/^(' . implode( '|', array_map( 'preg_quote', $credentials ) ) . ')$/i';

            $name_parts       = array();
            $found_name_parts = 0;

            foreach ( $parts as $part ) {
                $tokens          = preg_split( '/\s+/', trim( $part ), -1, PREG_SPLIT_NO_EMPTY );
                $non_cred_tokens = array();

                foreach ( $tokens as $token ) {
                    $clean_token = str_replace( '.', '', $token );
                    if ( ! preg_match( $cred_pattern, $clean_token ) ) {
                        $non_cred_tokens[] = $token;
                    }
                }

                if ( ! empty( $non_cred_tokens ) ) {
                    $name_parts[] = implode( ' ', $non_cred_tokens );
                    $found_name_parts++;
                }
            }

            if ( $found_name_parts >= 2 ) {
                $lastname  = array_shift( $name_parts );
                $firstname = implode( ' ', $name_parts );
                $name      = $firstname . ' ' . $lastname;
            } elseif ( $found_name_parts === 1 ) {
                $name = $name_parts[0];
            }
        } else {
            $cred_pattern = '/^(' . implode( '|', array_map( 'preg_quote', $credentials ) ) . ')$/i';
            $tokens       = preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY );
            $clean_tokens = array();

            foreach ( $tokens as $token ) {
                $clean_token = str_replace( '.', '', $token );
                if ( ! preg_match( $cred_pattern, $clean_token ) ) {
                    $clean_tokens[] = $token;
                }
            }

            $name = implode( ' ', $clean_tokens );
        }

        $name = strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );

        return $name;
    }

    // =========================================================================
    //  CSV I/O
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

        if ( ! in_array( 'Author Email', $headers, true ) || ! in_array( 'Author Name', $headers, true ) ) {
            fclose( $handle );
            return new WP_Error( 'csv_columns', 'CSV must contain "Author Email" and "Author Name" columns.' );
        }

        // Ensure Consultant ID and Match Type columns exist.
        if ( ! in_array( 'Consultant ID', $headers, true ) ) {
            $headers[] = 'Consultant ID';
        }
        if ( ! in_array( 'Match Type', $headers, true ) ) {
            $headers[] = 'Match Type';
        }

        $col_count = count( $headers );

        $rows = array();
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            // Pad short rows with empty values for added columns.
            while ( count( $data ) < $col_count ) {
                $data[] = '';
            }
            if ( count( $data ) === $col_count ) {
                $rows[] = array_combine( $headers, $data );
            }
        }

        fclose( $handle );
        return $rows;
    }

    private static function write_output_csv( $filename, $data_rows ) {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/rit-exports/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
        }

        $handle = fopen( $dir . $filename, 'w' );
        if ( ! empty( $data_rows ) ) {
            fputcsv( $handle, array_keys( $data_rows[0] ) );
            foreach ( $data_rows as $row ) {
                fputcsv( $handle, array_values( $row ) );
            }
        }
        fclose( $handle );
    }

    private static function redirect_with_error( $message ) {
        set_transient( 'rit_matcher_results_' . get_current_user_id(), array( 'error' => $message ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=rit-consultant-matcher' ) );
        exit;
    }
}
