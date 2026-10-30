<?php
/**
 * Plugin Name:       Admin Activity Log
 * Plugin URI:        https://github.com/Azanumer/wp-admin-activity-log
 * Description:       Logs admin actions — post publish/update/delete, plugin activation, theme switches, user and role changes, comment moderation — to a database table with a filterable Tools viewer and automatic retention cleanup.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       waal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAAL_VERSION', '1.0.0' );
define( 'WAAL_TABLE', 'waal_events' );

/** Full table name for the events log. */
function waal_table() {
    global $wpdb;
    return $wpdb->prefix . WAAL_TABLE;
}

/** Create the log table. Runs on activation. */
function waal_activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $sql = 'CREATE TABLE ' . waal_table() . ' (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_login varchar(60) NOT NULL DEFAULT "",
        action varchar(60) NOT NULL DEFAULT "",
        object_type varchar(60) NOT NULL DEFAULT "",
        object_id bigint(20) unsigned NOT NULL DEFAULT 0,
        object_label varchar(255) NOT NULL DEFAULT "",
        ip varchar(45) NOT NULL DEFAULT "",
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY action (action),
        KEY created_at (created_at)
    ) ' . $charset . ';';
    dbDelta( $sql );
    if ( ! wp_next_scheduled( 'waal_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'waal_cleanup' );
    }
}
register_activation_hook( __FILE__, 'waal_activate' );

/** Drop the table and cron job on uninstall (handled in uninstall.php too). */
function waal_deactivate() {
    wp_clear_scheduled_hook( 'waal_cleanup' );
}
register_deactivation_hook( __FILE__, 'waal_deactivate' );

/** Retention in days. Filterable: add_filter( 'waal_retention_days', fn() => 30 ); */
function waal_retention_days() {
    return (int) apply_filters( 'waal_retention_days', (int) get_option( 'waal_retention_days', 90 ) );
}

/**
 * Write one event. Ignored actions (filter waal_ignored_actions) and
 * logging during the cleanup itself are skipped so the log never
 * records its own housekeeping.
 */
function waal_log( $action, $object_type = '', $object_id = 0, $object_label = '' ) {
    if ( doing_action( 'waal_cleanup' ) ) {
        return;
    }
    $ignored = (array) apply_filters( 'waal_ignored_actions', array() );
    if ( in_array( $action, $ignored, true ) ) {
        return;
    }
    global $wpdb;
    $user = wp_get_current_user();
    $wpdb->insert(
        waal_table(),
        array(
            'user_id'      => $user ? (int) $user->ID : 0,
            'user_login'   => $user ? $user->user_login : 'system',
            'action'       => sanitize_key( $action ),
            'object_type'  => sanitize_key( $object_type ),
            'object_id'    => (int) $object_id,
            'object_label' => sanitize_text_field( wp_strip_all_tags( (string) $object_label ) ),
            'ip'           => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            'created_at'   => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
    );
}

/** Delete events older than the retention window. Runs daily. */
function waal_cleanup() {
    global $wpdb;
    $days = max( 1, waal_retention_days() );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $days is cast to int above.
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . waal_table() . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $days ) );
}
add_action( 'waal_cleanup', 'waal_cleanup' );

/* ------------------------------------------------------------------ */
/* Event sources                                                       */
/* ------------------------------------------------------------------ */

/** Post publish / update / trash, via status transitions (skips autosaves and revisions). */
function waal_on_post_transition( $new_status, $old_status, $post ) {
    if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
        return;
    }
    if ( 'trash' === $new_status ) {
        waal_log( 'post_trashed', $post->post_type, $post->ID, $post->post_title );
    } elseif ( 'publish' === $new_status && 'publish' !== $old_status ) {
        waal_log( 'post_published', $post->post_type, $post->ID, $post->post_title );
    } elseif ( 'publish' === $new_status ) {
        waal_log( 'post_updated', $post->post_type, $post->ID, $post->post_title );
    }
}
add_action( 'transition_post_status', 'waal_on_post_transition', 10, 3 );

/** Permanent post deletion. */
function waal_on_post_delete( $post_id, $post ) {
    waal_log( 'post_deleted', $post->post_type, $post_id, $post->post_title );
}
add_action( 'before_delete_post', 'waal_on_post_delete', 10, 2 );

/** Plugin activation / deactivation. */
function waal_on_plugin_toggle( $plugin, $network_wide ) {
    $doing = doing_action( 'activated_plugin' ) ? 'plugin_activated' : 'plugin_deactivated';
    waal_log( $doing, 'plugin', 0, $plugin );
}
add_action( 'activated_plugin', 'waal_on_plugin_toggle', 10, 2 );
add_action( 'deactivated_plugin', 'waal_on_plugin_toggle', 10, 2 );

/** Theme switch. */
function waal_on_theme_switch( $new_name ) {
    waal_log( 'theme_switched', 'theme', 0, $new_name );
}
add_action( 'switch_theme', 'waal_on_theme_switch' );

/** User registration, deletion and role changes. */
function waal_on_user_register( $user_id ) {
    $user = get_userdata( $user_id );
    waal_log( 'user_created', 'user', $user_id, $user ? $user->user_login : '' );
}
add_action( 'user_register', 'waal_on_user_register' );

function waal_on_user_delete( $user_id ) {
    waal_log( 'user_deleted', 'user', $user_id, '' );
}
add_action( 'delete_user', 'waal_on_user_delete' );

function waal_on_role_change( $user_id, $role ) {
    $user = get_userdata( $user_id );
    waal_log( 'user_role_changed', 'user', $user_id, $user ? $user->user_login . ' -> ' . $role : $role );
}
add_action( 'set_user_role', 'waal_on_role_change', 10, 2 );

/** Comment moderation transitions (approve / spam / trash). */
function waal_on_comment_transition( $new_status, $old_status, $comment ) {
    if ( $new_status === $old_status ) {
        return;
    }
    $map = array(
        'approved' => 'comment_approved',
        'spam'     => 'comment_spammed',
        'trash'    => 'comment_trashed',
    );
    if ( isset( $map[ $new_status ] ) ) {
        waal_log( $map[ $new_status ], 'comment', $comment->comment_ID, get_the_title( $comment->comment_post_ID ) );
    }
}
add_action( 'transition_comment_status', 'waal_on_comment_transition', 10, 3 );

/**
 * Settings changes. Skips transients, our own option, and cron storage so
 * routine WordPress housekeeping does not flood the log.
 */
function waal_on_option_update( $option ) {
    if ( 0 === strpos( $option, '_transient_' ) || 'cron' === $option || 'waal_retention_days' === $option ) {
        return;
    }
    waal_log( 'option_updated', 'option', 0, $option );
}
add_action( 'updated_option', 'waal_on_option_update' );

/* ------------------------------------------------------------------ */
/* Tools viewer                                                        */
/* ------------------------------------------------------------------ */

function waal_admin_menu() {
    add_management_page(
        'Activity Log',
        'Activity Log',
        'manage_options',
        'waal-activity-log',
        'waal_render_page'
    );
}
add_action( 'admin_menu', 'waal_admin_menu' );

function waal_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'waal' ) );
    }
    global $wpdb;

    // Clear the whole log (nonce-protected, POST only).
    if ( isset( $_POST['waal_clear'] ) && check_admin_referer( 'waal_clear_log' ) ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
        $wpdb->query( 'TRUNCATE TABLE ' . waal_table() );
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Activity log cleared.', 'waal' ) . '</p></div>';
    }
    // Retention setting.
    if ( isset( $_POST['waal_retention'] ) && check_admin_referer( 'waal_save_retention' ) ) {
        update_option( 'waal_retention_days', max( 1, (int) $_POST['waal_retention'] ) );
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Retention saved.', 'waal' ) . '</p></div>';
    }

    $filter_action = isset( $_GET['waal_action'] ) ? sanitize_key( wp_unslash( $_GET['waal_action'] ) ) : '';
    $page          = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $per_page      = 50;
    $offset        = ( $page - 1 ) * $per_page;

    $where  = '';
    $params = array();
    if ( '' !== $filter_action ) {
        $where    = ' WHERE action = %s';
        $params[] = $filter_action;
    }
    if ( '' !== $filter_action ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from a fixed fragment.
        $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . waal_table() . $where, $params ) );
    } else {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table only.
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . waal_table() );
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from a fixed fragment.
    $rows = $wpdb->get_results(
        $wpdb->prepare( 'SELECT * FROM ' . waal_table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $params, array( $per_page, $offset ) ) )
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table only.
    $actions = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . waal_table() . ' ORDER BY action' );
    $pages   = max( 1, (int) ceil( $total / $per_page ) );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Activity Log', 'waal' ); ?></h1>
        <form method="get">
            <input type="hidden" name="page" value="waal-activity-log" />
            <label><?php esc_html_e( 'Action:', 'waal' ); ?>
                <select name="waal_action">
                    <option value=""><?php esc_html_e( 'All actions', 'waal' ); ?></option>
                    <?php foreach ( $actions as $a ) : ?>
                        <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $filter_action, $a ); ?>><?php echo esc_html( $a ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php submit_button( __( 'Filter', 'waal' ), 'secondary', '', false ); ?>
        </form>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Time', 'waal' ); ?></th><th><?php esc_html_e( 'User', 'waal' ); ?></th><th><?php esc_html_e( 'Action', 'waal' ); ?></th><th><?php esc_html_e( 'Object', 'waal' ); ?></th><th><?php esc_html_e( 'IP', 'waal' ); ?></th></tr></thead>
            <tbody>
            <?php if ( $rows ) : foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                    <td><?php echo esc_html( $row->user_login ); ?></td>
                    <td><?php echo esc_html( $row->action ); ?></td>
                    <td><?php echo esc_html( $row->object_type . ( $row->object_id ? ' #' . $row->object_id : '' ) . ' ' . $row->object_label ); ?></td>
                    <td><?php echo esc_html( $row->ip ); ?></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No events logged yet.', 'waal' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ( $pages > 1 ) : ?>
            <p>
            <?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
                <a href="<?php echo esc_url( add_query_arg( array( 'paged' => $i, 'waal_action' => $filter_action ) ) ); ?>"><?php echo esc_html( $i ); ?></a>
            <?php endfor; ?>
            </p>
        <?php endif; ?>
        <hr />
        <form method="post">
            <?php wp_nonce_field( 'waal_save_retention' ); ?>
            <label><?php esc_html_e( 'Retention (days):', 'waal' ); ?>
                <input type="number" name="waal_retention" min="1" value="<?php echo esc_attr( waal_retention_days() ); ?>" />
            </label>
            <?php submit_button( __( 'Save retention', 'waal' ), 'secondary' ); ?>
        </form>
        <form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Clear the entire activity log?', 'waal' ); ?>');">
            <?php wp_nonce_field( 'waal_clear_log' ); ?>
            <?php submit_button( __( 'Clear log', 'waal' ), 'delete', 'waal_clear' ); ?>
        </form>
    </div>
    <?php
}
