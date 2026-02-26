<?php
/**
 * Consultant Matcher Tool
 *
 * Matches CSV Author Email / Author Name against Consultant custom posts
 * and generates matched, unmatched, and compiled CSV exports.
 *
 * @since   1.0.0
 * @version 3.0.0
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
                    <tr><td>Total rows processed</td><td><?php echo (int) $results['total']; ?></td></tr>
                    <tr><td>Exact name matches</td><td><?php echo (int) $results['exact_name']; ?></td></tr>
                    <tr><td>Fuzzy name matches</td><td><?php echo (int) $results['fuzzy_name']; ?></td></tr>
                    <tr><td>Exact email matches</td><td><?php echo (int) $results['exact_email']; ?></td></tr>
                    <tr><td>Fuzzy email matches</td><td><?php echo (int) $results['fuzzy_email']; ?></td></tr>
                    <tr><td>Skipped (multi-author)</td><td><?php echo (int) $results['skipped']; ?></td></tr>
                    <tr><td>Unmatched rows</td><td><?php echo (int) $results['unmatched']; ?></td></tr>
                </tbody>
            </table>

            <p style="margin-top:15px;">
                <span class="dashicons dashicons-download"></span>
                <a href="<?php echo esc_url( $base_url . $results['matched_file'] ); ?>">Download Matched CSV</a>
            </p>
            <p>
                <span class="dashicons dashicons-download"></span>
                <a href="<?php echo esc_url( $base_url . $results['unmatched_file'] ); ?>">Download Unmatched CSV</a>
            </p>
            <p>
                <span class="dashicons dashicons-download"></span>
                <a href="<?php echo esc_url( $base_url . $results['compiled_file'] ); ?>">Download Compiled CSV (all rows — ready for Resource Importer)</a>
            </p>
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

        $matched   = array();
        $unmatched = array();
        $skipped   = array();

        $stats = array(
            'exact_name'  => 0,
            'fuzzy_name'  => 0,
            'exact_email' => 0,
            'fuzzy_email' => 0,
        );

        foreach ( $rows as $row ) {
            $author_email = isset( $row['Author Email'] ) ? trim( $row['Author Email'] ) : '';
            $author_name  = isset( $row['Author Name'] ) ? trim( $row['Author Name'] ) : '';

            if ( self::is_multi_author( $author_name ) ) {
                $row['Consultant ID'] = '';
                $row['Match Type']    = 'skipped_multi_author';
                $skipped[]            = $row;
                continue;
            }

            $match = self::find_consultant_match( $author_email, $author_name, $consultants );

            if ( $match ) {
                $row['Consultant ID'] = $match['id'];
                $row['Match Type']    = $match['type'];
                $matched[]            = $row;
                $stats[ $match['type'] ]++;
            } else {
                $row['Consultant ID'] = '';
                $row['Match Type']    = '';
                $unmatched[]          = $row;
            }
        }

        $timestamp       = date( 'Y-m-d_His' );
        $matched_file    = "matched-{$timestamp}.csv";
        $unmatched_file  = "unmatched-{$timestamp}.csv";
        $compiled_file   = "compiled-{$timestamp}.csv";

        $all_processed = array_merge( $matched, $unmatched, $skipped );

        self::write_full_csv( $matched_file, $matched, $rows );
        self::write_unmatched_csv( $unmatched_file, array_merge( $unmatched, $skipped ) );
        self::write_full_csv( $compiled_file, $all_processed, $rows );

        set_transient( 'rit_matcher_results_' . get_current_user_id(), array(
            'total'          => count( $rows ),
            'exact_name'     => $stats['exact_name'],
            'fuzzy_name'     => $stats['fuzzy_name'],
            'exact_email'    => $stats['exact_email'],
            'fuzzy_email'    => $stats['fuzzy_email'],
            'skipped'        => count( $skipped ),
            'unmatched'      => count( $unmatched ),
            'matched_file'   => $matched_file,
            'unmatched_file' => $unmatched_file,
            'compiled_file'  => $compiled_file,
        ), 300 );

        wp_safe_redirect( admin_url( 'admin.php?page=rit-consultant-matcher' ) );
        exit;
    }

    // =========================================================================
    //  Matching Logic
    // =========================================================================

    private static function is_multi_author( $name ) {
        if ( preg_match( '/\b(and)\b|[&;]/i', $name ) ) {
            return true;
        }
        return false;
    }

    private static function normalize_name( $name ) {
        $name = trim( $name );
        if ( $name === '' ) {
            return '';
        }

        $credentials = array(
            'PhD', 'PharmD', 'PsyD', 'EdD', 'DrPH', 'ScD', 'DMin', 'DBA', 'JD', 'DDS', 'DMD', 'DO', 'DPM', 'DC',
            'MD', 'MS', 'MSW', 'MSN', 'MSc', 'MA', 'MBA', 'MPA', 'MPH', 'MPP', 'MDiv', 'MEd', 'MFA', 'MHS',
            'BSN', 'BS', 'BA', 'BSW',
            'RN', 'LPN', 'NP', 'CNS', 'CRNA', 'CNM', 'APRN', 'FNP',
            'LCSW', 'LMSW', 'LMFT', 'LPC', 'LCPC', 'LCMHC', 'LMHC', 'LPCC', 'LSW',
            'BCBA', 'CPA', 'PE', 'RA', 'AIA', 'FACHE', 'FAAN', 'FACP', 'FACS',
            'PA-C', 'PA', 'OT', 'PT', 'DPT', 'SLP', 'CCC-SLP',
            'CADC', 'CASAC', 'CAP', 'CRC', 'CARN', 'NCAC',
            'Jr', 'Sr', 'II', 'III', 'IV',
            'Esq', 'Ret',
        );

        $name = preg_replace( '/\[.*?\]|\(.*?\)/', '', $name );

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

    private static function find_consultant_match( $author_email, $author_name, $consultants ) {
        $email_lower     = strtolower( trim( $author_email ) );
        $name_normalized = self::normalize_name( $author_name );

        // 1. Exact name match.
        if ( $name_normalized !== '' ) {
            foreach ( $consultants as $c ) {
                if ( $c['normalized_title'] === $name_normalized ) {
                    return array( 'id' => $c['id'], 'type' => 'exact_name' );
                }
            }
        }

        // 2. Fuzzy name match.
        if ( $name_normalized !== '' ) {
            $best_score = 0;
            $best_match = null;

            foreach ( $consultants as $c ) {
                if ( $c['normalized_title'] === '' ) {
                    continue;
                }
                similar_text( $name_normalized, $c['normalized_title'], $pct );
                $lev = levenshtein( $name_normalized, $c['normalized_title'] );

                if ( ( $pct >= 85 || $lev <= 2 ) && $pct > $best_score ) {
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

        // 4. Fuzzy email match.
        if ( $email_lower !== '' ) {
            $best_score = 0;
            $best_match = null;

            foreach ( $consultants as $c ) {
                if ( $c['email'] === '' ) {
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

        $rows = array();
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            if ( count( $data ) === count( $headers ) ) {
                $rows[] = array_combine( $headers, $data );
            }
        }

        fclose( $handle );
        return $rows;
    }

    private static function get_export_dir() {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/rit-exports/';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
        }

        return $dir;
    }

    private static function write_full_csv( $filename, $data_rows, $all_rows ) {
        $dir    = self::get_export_dir();
        $handle = fopen( $dir . $filename, 'w' );

        if ( ! empty( $data_rows ) ) {
            $headers = array_keys( $data_rows[0] );
        } elseif ( ! empty( $all_rows ) ) {
            $headers = array_merge( array_keys( $all_rows[0] ), array( 'Consultant ID', 'Match Type' ) );
        } else {
            $headers = array( 'Author Email', 'Author Name', 'Consultant ID', 'Match Type' );
        }

        fputcsv( $handle, $headers );

        foreach ( $data_rows as $row ) {
            fputcsv( $handle, array_values( $row ) );
        }

        fclose( $handle );
    }

    private static function write_unmatched_csv( $filename, $unmatched_rows ) {
        $dir    = self::get_export_dir();
        $handle = fopen( $dir . $filename, 'w' );

        fputcsv( $handle, array( 'Author Name', 'Author Email' ) );

        foreach ( $unmatched_rows as $row ) {
            $name  = isset( $row['Author Name'] ) ? $row['Author Name'] : '';
            $email = isset( $row['Author Email'] ) ? $row['Author Email'] : '';
            fputcsv( $handle, array( $name, $email ) );
        }

        fclose( $handle );
    }

    private static function redirect_with_error( $message ) {
        set_transient( 'rit_matcher_results_' . get_current_user_id(), array( 'error' => $message ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=rit-consultant-matcher' ) );
        exit;
    }
}
