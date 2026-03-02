<?php
/**
 * Consultant User Manager Tool
 *
 * Creates WordPress user accounts for active Consultant posts.
 * Queries the consultant post archive directly — no CSV upload needed.
 *
 * For each consultant with status "active_only":
 * - Creates a user with role "consultant"
 * - Username: first_name.last_name (lowercase, period-separated)
 * - Email from ACF "email" field on the consultant post
 * - Sets bi-directional ACF relationship fields
 * - Assigns "region" taxonomy terms to the user based on the consultant's
 *   hhs_region and state_of_residence ACF fields
 * - Suppresses new-user notification emails
 *
 * @since   3.2.0
 * @version 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RIT_Consultant_User_Manager {

    // =========================================================================
    //  Mapping Tables
    // =========================================================================

    /**
     * HHS Region ACF select value → region taxonomy parent slug.
     */
    private static $hhs_region_map = array(
        '1'                => 'region-1',
        '2'                => 'region-2',
        '3'                => 'region-3',
        '4'                => 'region-4',
        '5'                => 'region-5',
        '6'                => 'region-6',
        '7'                => 'region-7',
        '8'                => 'region-8',
        '9'                => 'region-9',
        '10'               => 'region-10',
        'tribal_east'      => 'tribal-east',
        'tribal_mountain'  => 'tribal-mountain-plains-lakes',
        'tribal_northwest' => 'tribal-northwest',
        'tribal_south'     => 'tribal-south',
        'tribal_southwest' => 'tribal-southwest',
    );

    /**
     * State ACF select value → array( 'parent' => region slug, 'child' => state slug ).
     */
    private static $state_map = array(
        'AL' => array( 'parent' => 'region-4',  'child' => 'alabama' ),
        'AK' => array( 'parent' => 'region-10', 'child' => 'alaska' ),
        'AZ' => array( 'parent' => 'region-9',  'child' => 'arizona' ),
        'AR' => array( 'parent' => 'region-6',  'child' => 'arkansas' ),
        'CA' => array( 'parent' => 'region-9',  'child' => 'california' ),
        'CO' => array( 'parent' => 'region-8',  'child' => 'colorado' ),
        'CT' => array( 'parent' => 'region-1',  'child' => 'connecticut' ),
        'DE' => array( 'parent' => 'region-3',  'child' => 'delaware' ),
        'FL' => array( 'parent' => 'region-4',  'child' => 'florida' ),
        'GA' => array( 'parent' => 'region-4',  'child' => 'georgia' ),
        'HI' => array( 'parent' => 'region-9',  'child' => 'hawaii' ),
        'ID' => array( 'parent' => 'region-10', 'child' => 'idaho' ),
        'IL' => array( 'parent' => 'region-5',  'child' => 'illinois' ),
        'IN' => array( 'parent' => 'region-5',  'child' => 'indiana' ),
        'IA' => array( 'parent' => 'region-7',  'child' => 'iowa' ),
        'KS' => array( 'parent' => 'region-7',  'child' => 'kansas' ),
        'KY' => array( 'parent' => 'region-4',  'child' => 'kentucky' ),
        'LA' => array( 'parent' => 'region-6',  'child' => 'louisiana' ),
        'ME' => array( 'parent' => 'region-1',  'child' => 'maine' ),
        'MD' => array( 'parent' => 'region-3',  'child' => 'maryland' ),
        'MA' => array( 'parent' => 'region-1',  'child' => 'massachusetts' ),
        'MI' => array( 'parent' => 'region-5',  'child' => 'michigan' ),
        'MN' => array( 'parent' => 'region-5',  'child' => 'minnesota' ),
        'MS' => array( 'parent' => 'region-4',  'child' => 'mississippi' ),
        'MO' => array( 'parent' => 'region-7',  'child' => 'missouri' ),
        'MT' => array( 'parent' => 'region-8',  'child' => 'montana' ),
        'NE' => array( 'parent' => 'region-7',  'child' => 'nebraska' ),
        'NV' => array( 'parent' => 'region-9',  'child' => 'nevada' ),
        'NH' => array( 'parent' => 'region-1',  'child' => 'new-hampshire' ),
        'NJ' => array( 'parent' => 'region-2',  'child' => 'new-jersey' ),
        'NM' => array( 'parent' => 'region-6',  'child' => 'new-mexico' ),
        'NY' => array( 'parent' => 'region-2',  'child' => 'new-york' ),
        'NC' => array( 'parent' => 'region-4',  'child' => 'north-carolina' ),
        'ND' => array( 'parent' => 'region-8',  'child' => 'north-dakota' ),
        'OH' => array( 'parent' => 'region-5',  'child' => 'ohio' ),
        'OK' => array( 'parent' => 'region-6',  'child' => 'oklahoma' ),
        'OR' => array( 'parent' => 'region-10', 'child' => 'oregon' ),
        'PA' => array( 'parent' => 'region-3',  'child' => 'pennsylvania' ),
        'PR' => array( 'parent' => 'region-2',  'child' => 'puerto-rico' ),
        'RI' => array( 'parent' => 'region-1',  'child' => 'rhode-island' ),
        'SC' => array( 'parent' => 'region-4',  'child' => 'south-carolina' ),
        'SD' => array( 'parent' => 'region-8',  'child' => 'south-dakota' ),
        'TN' => array( 'parent' => 'region-4',  'child' => 'tennessee' ),
        'TX' => array( 'parent' => 'region-6',  'child' => 'texas' ),
        'VI' => array( 'parent' => 'region-2',  'child' => 'u-s-virgin-islands' ),
        'UT' => array( 'parent' => 'region-8',  'child' => 'utah' ),
        'VT' => array( 'parent' => 'region-1',  'child' => 'vermont' ),
        'VA' => array( 'parent' => 'region-3',  'child' => 'virginia' ),
        'WA' => array( 'parent' => 'region-10', 'child' => 'washington' ),
        'DC' => array( 'parent' => 'region-3',  'child' => 'washington-d-c' ),
        'WV' => array( 'parent' => 'region-3',  'child' => 'west-virginia' ),
        'WI' => array( 'parent' => 'region-5',  'child' => 'wisconsin' ),
        'WY' => array( 'parent' => 'region-8',  'child' => 'wyoming' ),
    );

    // =========================================================================
    //  Admin UI
    // =========================================================================

    public static function render_page() {
        ?>
        <div class="wrap rit-wrap">
            <h1>Consultant User Manager</h1>

            <div class="card">
                <h2>Create User Accounts from Consultant Posts</h2>
                <p>Creates a WordPress user account for each <strong>Consultant</strong> post with status
                   <code>active_only</code>.<br>
                   Consultants without an email address are skipped.<br>
                   Existing users (matched by email) have their relationships and region updated but are not recreated.<br>
                   New user email notifications are <strong>suppressed</strong>.</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'rit_consultant_user_create', 'rit_nonce' ); ?>
                    <input type="hidden" name="action" value="rit_consultant_user_create" />

                    <table class="form-table">
                        <tr>
                            <th scope="row">Mode</th>
                            <td>
                                <label>
                                    <input type="radio" name="rit_mode" value="dry_run" checked />
                                    Dry Run (preview only — no users created)
                                </label><br>
                                <label>
                                    <input type="radio" name="rit_mode" value="live" />
                                    Live Import (creates user accounts)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rit_limit">Limit</label></th>
                            <td>
                                <input type="number" name="rit_limit" id="rit_limit" value="0" min="0" step="1" style="width:80px;" />
                                <p class="description">Max consultants to process. Set to <strong>0</strong> for no limit.</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( 'Create User Accounts', 'primary', 'submit', true ); ?>
                </form>
            </div>

            <?php self::render_results(); ?>
        </div>
        <?php
    }

    // =========================================================================
    //  Results
    // =========================================================================

    private static function render_results() {
        $results = get_transient( 'rit_user_create_results_' . get_current_user_id() );
        if ( ! $results ) {
            return;
        }
        delete_transient( 'rit_user_create_results_' . get_current_user_id() );

        if ( isset( $results['error'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $results['error'] ) . '</p></div>';
            return;
        }

        $is_dry = $results['mode'] === 'dry_run';
        $label  = $is_dry ? 'Dry Run Results (no users created)' : 'Import Results';
        ?>
        <div class="card rit-results">
            <h2><?php echo esc_html( $label ); ?></h2>

            <table class="rit-stats-table widefat striped" style="max-width:420px;">
                <tbody>
                    <tr><td>Active consultants found</td><td><?php echo (int) $results['total']; ?></td></tr>
                    <tr><td>Users created</td><td><?php echo (int) $results['created']; ?></td></tr>
                    <tr><td>Already existing (updated)</td><td><?php echo (int) $results['existing']; ?></td></tr>
                    <tr><td>Region terms assigned</td><td><?php echo (int) $results['regions_assigned']; ?></td></tr>
                    <tr><td>Skipped (no email)</td><td><?php echo (int) $results['skipped_no_email']; ?></td></tr>
                    <tr><td>Skipped (no name)</td><td><?php echo (int) $results['skipped_no_name']; ?></td></tr>
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
        check_admin_referer( 'rit_consultant_user_create', 'rit_nonce' );

        $mode  = isset( $_POST['rit_mode'] ) && $_POST['rit_mode'] === 'live' ? 'live' : 'dry_run';
        $limit = isset( $_POST['rit_limit'] ) ? intval( $_POST['rit_limit'] ) : 0;

        // Query all active consultant posts.
        $consultants = self::get_active_consultants();

        if ( $limit > 0 && $limit < count( $consultants ) ) {
            $consultants = array_slice( $consultants, 0, $limit );
        }

        $log              = array();
        $created          = 0;
        $existing         = 0;
        $skipped_no_email = 0;
        $skipped_no_name  = 0;
        $regions_assigned = 0;
        $errors           = 0;

        foreach ( $consultants as $consultant ) {
            $post_id    = $consultant['id'];
            $first_name = $consultant['first_name'];
            $last_name  = $consultant['last_name'];
            $email      = $consultant['email'];
            $post_title = $consultant['title'];
            $hhs_region = $consultant['hhs_region'];
            $state      = $consultant['state'];

            // Skip if no name.
            if ( $first_name === '' && $last_name === '' ) {
                $log[] = array( 'level' => 'skip', 'msg' => "Post #{$post_id} \"{$post_title}\": No first/last name. Skipping." );
                $skipped_no_name++;
                continue;
            }

            // Skip if no email.
            if ( $email === '' ) {
                $log[] = array( 'level' => 'skip', 'msg' => "Post #{$post_id} \"{$post_title}\": No email. Skipping." );
                $skipped_no_email++;
                continue;
            }

            // Resolve region term IDs for this consultant.
            $region_info = self::resolve_region_terms( $hhs_region, $state );

            // Check if a user with this email already exists.
            $existing_user = get_user_by( 'email', $email );

            if ( $existing_user ) {
                $region_log = '';
                if ( $mode === 'live' ) {
                    self::update_user_fields( $existing_user->ID, $post_id );
                    $existing_user->set_role( 'consultant' );
                    if ( ! empty( $region_info['term_ids'] ) ) {
                        wp_set_object_terms( $existing_user->ID, $region_info['term_ids'], 'region', false );
                        $regions_assigned++;
                        $region_log = ' Region: ' . $region_info['label'] . '.';
                    }
                } else {
                    if ( ! empty( $region_info['term_ids'] ) ) {
                        $regions_assigned++;
                        $region_log = ' Region: ' . $region_info['label'] . '.';
                    }
                }
                $verb = $mode === 'live' ? 'Updated' : 'Would update';
                $log[] = array( 'level' => 'skip', 'msg' => "Post #{$post_id} \"{$post_title}\": User already exists (#{$existing_user->ID}, {$email}). {$verb} relationships.{$region_log}" );
                $existing++;
                continue;
            }

            $username = self::generate_username( $first_name, $last_name );

            if ( $mode === 'live' ) {
                $user_id = self::create_user( $email, $first_name, $last_name, $username, $post_id );

                if ( is_wp_error( $user_id ) ) {
                    $log[] = array( 'level' => 'error', 'msg' => "Post #{$post_id} \"{$post_title}\": ERROR — " . $user_id->get_error_message() );
                    $errors++;
                    continue;
                }

                $region_log = '';
                if ( ! empty( $region_info['term_ids'] ) ) {
                    wp_set_object_terms( $user_id, $region_info['term_ids'], 'region', false );
                    $regions_assigned++;
                    $region_log = ', region: ' . $region_info['label'];
                }

                $log[] = array( 'level' => 'ok', 'msg' => "Post #{$post_id} \"{$post_title}\": Created user #{$user_id} ({$username}, {$email}{$region_log})" );
            } else {
                $region_log = '';
                if ( ! empty( $region_info['term_ids'] ) ) {
                    $regions_assigned++;
                    $region_log = ', region: ' . $region_info['label'];
                }
                $log[] = array( 'level' => 'ok', 'msg' => "Post #{$post_id} \"{$post_title}\": Would create user ({$username}, {$email}{$region_log})" );
            }

            $created++;
        }

        set_transient( 'rit_user_create_results_' . get_current_user_id(), array(
            'mode'             => $mode,
            'total'            => count( $consultants ),
            'created'          => $created,
            'existing'         => $existing,
            'regions_assigned' => $regions_assigned,
            'skipped_no_email' => $skipped_no_email,
            'skipped_no_name'  => $skipped_no_name,
            'errors'           => $errors,
            'log'              => $log,
        ), 600 );

        wp_safe_redirect( admin_url( 'admin.php?page=rit-consultant-user-manager' ) );
        exit;
    }

    // =========================================================================
    //  Query Active Consultants
    // =========================================================================

    private static function get_active_consultants() {
        $consultants = array();

        // Query all published consultants — filter by status in PHP
        // because ACF select fields may store values as serialized arrays.
        $query = new WP_Query( array(
            'post_type'      => 'consultant',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ) );

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post_id ) {
                $status_raw = get_field( 'status', $post_id );
                $status     = self::extract_select_value( $status_raw );

                if ( $status !== 'active_only' ) {
                    continue;
                }

                $consultants[] = array(
                    'id'         => $post_id,
                    'title'      => get_the_title( $post_id ),
                    'first_name' => trim( (string) get_field( 'first_name', $post_id ) ),
                    'last_name'  => trim( (string) get_field( 'last_name', $post_id ) ),
                    'email'      => strtolower( trim( (string) get_field( 'email', $post_id ) ) ),
                    'hhs_region' => self::extract_select_value( get_field( 'hhs_region', $post_id ) ),
                    'state'      => self::extract_select_value( get_field( 'state_of_residence', $post_id ) ),
                );
            }
        }

        return $consultants;
    }

    // =========================================================================
    //  Region Taxonomy Resolution
    // =========================================================================

    /**
     * Resolve the region taxonomy term IDs to assign to a user.
     *
     * Priority:
     * 1. If state_of_residence is set and mapped → assign child term (state) + parent (region).
     * 2. Else if hhs_region is set and mapped → assign parent term (region) only.
     * 3. Else → no terms.
     *
     * @param  string $hhs_region  ACF hhs_region field value.
     * @param  string $state       ACF state_of_residence field value.
     * @return array               'term_ids' => array of int, 'label' => string for logging.
     */
    private static function resolve_region_terms( $hhs_region, $state ) {
        $term_ids = array();
        $label    = '';

        // Try state first (gives us both child and parent).
        if ( $state !== '' && isset( self::$state_map[ $state ] ) ) {
            $mapping     = self::$state_map[ $state ];
            $parent_slug = $mapping['parent'];
            $child_slug  = $mapping['child'];

            $child_term  = get_term_by( 'slug', $child_slug, 'region' );
            $parent_term = get_term_by( 'slug', $parent_slug, 'region' );

            if ( $child_term && ! is_wp_error( $child_term ) ) {
                $term_ids[] = $child_term->term_id;
                $label      = $child_term->name;
            }

            if ( $parent_term && ! is_wp_error( $parent_term ) ) {
                if ( ! in_array( $parent_term->term_id, $term_ids, true ) ) {
                    $term_ids[] = $parent_term->term_id;
                }
                $label = $parent_term->name . ' → ' . $label;
            }

            if ( ! empty( $term_ids ) ) {
                return array( 'term_ids' => $term_ids, 'label' => $label );
            }
        }

        // Fall back to hhs_region (parent only).
        if ( $hhs_region !== '' && isset( self::$hhs_region_map[ $hhs_region ] ) ) {
            $parent_slug = self::$hhs_region_map[ $hhs_region ];
            $parent_term = get_term_by( 'slug', $parent_slug, 'region' );

            if ( $parent_term && ! is_wp_error( $parent_term ) ) {
                $term_ids[] = $parent_term->term_id;
                $label      = $parent_term->name . ' (region only)';
            }
        }

        return array( 'term_ids' => $term_ids, 'label' => $label );
    }

    // =========================================================================
    //  User Creation
    // =========================================================================

    private static function create_user( $email, $first_name, $last_name, $username, $consultant_id ) {
        $password = wp_generate_password( 24, true, true );

        // Suppress new user notification emails.
        add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
        add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ),
            'role'         => 'consultant',
        ) );

        remove_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
        remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        self::update_user_fields( $user_id, $consultant_id );

        return $user_id;
    }

    /**
     * Set the bi-directional relationship fields.
     */
    private static function update_user_fields( $user_id, $consultant_id ) {
        $user_acf_id = 'user_' . $user_id;

        // User → Consultant.
        update_field( 'user_to_consultant_relationship', array( $consultant_id ), $user_acf_id );

        // Consultant → User (append without overwriting).
        $existing = get_field( 'consultant_to_user_relationship', $consultant_id );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $existing_ids = array();
        foreach ( $existing as $entry ) {
            if ( is_object( $entry ) && isset( $entry->ID ) ) {
                $existing_ids[] = $entry->ID;
            } elseif ( is_numeric( $entry ) ) {
                $existing_ids[] = intval( $entry );
            }
        }

        if ( ! in_array( $user_id, $existing_ids, true ) ) {
            $existing_ids[] = $user_id;
            update_field( 'consultant_to_user_relationship', $existing_ids, $consultant_id );
        }
    }

    /**
     * Generate a username: first_name.last_name (lowercase, period-separated).
     * Strips non-alphanumeric chars and ensures uniqueness.
     */
    private static function generate_username( $first_name, $last_name ) {
        $first = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $first_name ) );
        $last  = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $last_name ) );

        if ( $first !== '' && $last !== '' ) {
            $base = $first . '.' . $last;
        } elseif ( $first !== '' ) {
            $base = $first;
        } elseif ( $last !== '' ) {
            $base = $last;
        } else {
            $base = 'consultant';
        }

        $username = $base;
        $counter  = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Extract the value from an ACF select field.
     *
     * ACF select fields can return:
     * - A string (when return format is "value")
     * - An array like ['value' => 'AL', 'label' => 'Alabama'] (when return format is "both")
     * - An array like ['AL'] (when multi-select with one selection)
     * - null/false/empty
     *
     * @param  mixed $field_value  Raw get_field() return value.
     * @return string              The select value as a string.
     */
    private static function extract_select_value( $field_value ) {
        if ( is_array( $field_value ) ) {
            // Format: ['value' => 'AL', 'label' => 'Alabama']
            if ( isset( $field_value['value'] ) ) {
                return trim( (string) $field_value['value'] );
            }
            // Format: ['AL'] (multi-select, take first)
            if ( isset( $field_value[0] ) ) {
                // Could be nested: [['value' => 'AL', 'label' => '...']]
                if ( is_array( $field_value[0] ) && isset( $field_value[0]['value'] ) ) {
                    return trim( (string) $field_value[0]['value'] );
                }
                return trim( (string) $field_value[0] );
            }
            return '';
        }
        if ( $field_value === null || $field_value === false ) {
            return '';
        }
        return trim( (string) $field_value );
    }

    private static function redirect_with_error( $message ) {
        set_transient( 'rit_user_create_results_' . get_current_user_id(), array( 'error' => $message ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=rit-consultant-user-manager' ) );
        exit;
    }
}
