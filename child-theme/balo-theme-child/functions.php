<?php
if (!defined('ABSPATH')) exit;
/**
 * AJAX: mark booking completion (service / mentorship)
 * Step 1: current user confirms
 * Step 2: when both sides have confirmed, status becomes "completed".
 */
// ============================================
// BALO OVERSEER TRACKING - THEME
// ============================================

// Track when specific pages load
add_action('template_redirect', 'balo_track_page_loads');

function balo_track_page_loads() {
    if (!function_exists('bso_checkpoint')) return;
    
    // Track My Account page
    if (is_page('my-account')) {
        bso_checkpoint('page', 'My Account page loaded', [
            'user_id' => get_current_user_id(),
            'url' => $_SERVER['REQUEST_URI']
        ]);
    }
    
    // Track Dashboard page
    if (is_page('dashboard') || is_page('instructor-dashboard')) {
        bso_checkpoint('page', 'Dashboard page loaded', [
            'user_id' => get_current_user_id(),
            'url' => $_SERVER['REQUEST_URI']
        ]);
    }
}

// Track button clicks (if using custom JavaScript)
add_action('wp_footer', 'balo_add_tracking_js');

function balo_add_tracking_js() {
    if (!is_user_logged_in()) return;
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Track "Activate Dashboard" button clicks
        $('.activate-dashboard-btn').on('click', function() {
            console.log('[Balo Tracking] Activate Dashboard button clicked');
            // The AJAX handler will track the rest
        });
        
        // Track "Block User" button clicks
        $('.block-user-btn').on('click', function() {
            console.log('[Balo Tracking] Block User button clicked');
            // The AJAX handler will track the rest
        });
    });
    </script>
    <?php
}
/* -----------------------------------------------------------
 * Balo Device Fingerprint – Enqueued Script
 * ----------------------------------------------------------- */
add_action('wp_enqueue_scripts', function() {

    // Register custom inline JS
    wp_register_script('balo-device-fingerprint', false, [], false, true);

    $js = <<<JS
document.addEventListener("DOMContentLoaded", () => {

    function computeHash(text) {
        const encoder = new TextEncoder().encode(text);
        return crypto.subtle.digest("SHA-256", encoder).then(buf =>
            Array.from(new Uint8Array(buf))
                .map(b => b.toString(16).padStart(2,"0")).join("")
        );
    }

    const fpRaw = [
        navigator.userAgent,
        screen.width + "x" + screen.height,
        Intl.DateTimeFormat().resolvedOptions().timeZone,
        navigator.language,
        navigator.hardwareConcurrency || 0
    ].join("|");

    computeHash(fpRaw).then(hash => {

        // Create a hidden input for fingerprint
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "balo_fp";
        input.value = hash;

        // Append to body for AJAX access
        document.body.appendChild(input);

        // Attach fingerprint to ALL forms on submit
        document.querySelectorAll("form").forEach(form => {
            form.addEventListener("submit", () => {
                // Ensure latest fingerprint is added
                if (!form.querySelector("input[name='balo_fp']")) {
                    form.appendChild(input.cloneNode(true));
                }
            });
        });

    });
});
JS;

    wp_add_inline_script('balo-device-fingerprint', $js);
    wp_enqueue_script('balo-device-fingerprint');
});

// Soft-delete (hide) a dispute from the current user's list
add_action('wp_ajax_balo_hide_dispute', function () {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in'], 403);
    }

    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'balo_hide_dispute')) {
        wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $uid       = get_current_user_id();
    $booking_id = isset($_POST['booking_id']) ? sanitize_text_field($_POST['booking_id']) : '';

    if (!$booking_id) {
        wp_send_json_error(['message' => 'Missing booking id'], 400);
    }

    $hidden = get_user_meta($uid, 'balo_hidden_disputes', true);
    if (!is_array($hidden)) $hidden = [];

    if (!in_array($booking_id, $hidden, true)) {
        $hidden[] = $booking_id;
        update_user_meta($uid, 'balo_hidden_disputes', $hidden);
    }

    wp_send_json_success(['message' => 'Hidden']);
});

add_action('wp_ajax_balo_complete_booking', 'balo_complete_booking');

function balo_complete_booking() {
    // Security: nonce & logged-in check
    check_ajax_referer('balo_complete_booking');
    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message' => 'You must be logged in to complete a booking.'
        ]);
    }

    $uid        = get_current_user_id();
    $kind       = isset($_POST['kind']) ? sanitize_text_field($_POST['kind']) : '';
    $booking_id = isset($_POST['booking_id']) ? sanitize_text_field($_POST['booking_id']) : '';

    if (!$booking_id || !in_array($kind, ['service', 'mentorship'], true)) {
        wp_send_json_error([
            'message' => 'Invalid booking data.'
        ]);
    }

    // Decide which meta key to use
    $meta_key = ($kind === 'service') ? 'service_bookings' : 'mentorship_bookings';

    $bookings = get_user_meta($uid, $meta_key, true);
    if (!is_array($bookings) || empty($bookings)) {
        wp_send_json_error([
            'message' => 'No bookings found for this account.'
        ]);
    }

    // Find the booking by array key OR by stored booking_id field
    $b_key = null;

    if (isset($bookings[$booking_id])) {
        $b_key = $booking_id;
    } else {
        foreach ($bookings as $k => $row) {
            if (!empty($row['booking_id']) && (string) $row['booking_id'] === (string) $booking_id) {
                $b_key = $k;
                break;
            }
        }
    }

    if ($b_key === null || empty($bookings[$b_key]) || !is_array($bookings[$b_key])) {
        wp_send_json_error([
            'message' => 'Could not locate this booking on your account.'
        ]);
    }

    $booking = $bookings[$b_key];
    $now     = time();

    // Work out if you are provider or client (if those ids exist)
    $role = 'unknown';
    if (!empty($booking['provider_id']) && (int) $booking['provider_id'] === $uid) {
        $role = 'provider';
    } elseif (!empty($booking['client_id']) && (int) $booking['client_id'] === $uid) {
        $role = 'client';
    }

    // Normalise flags
    $provider_confirmed = !empty($booking['provider_confirmed']);
    $client_confirmed   = !empty($booking['client_confirmed']);

    // If already fully completed, don’t let them spam it
    if (!empty($booking['status']) && $booking['status'] === 'completed') {
        wp_send_json_success([
            'stage'   => 'already_completed',
            'status'  => 'completed',
            'message' => 'This booking is already marked as completed.'
        ]);
    }

    // Set the right flag based on who this user is
    if ($role === 'provider') {
        if ($provider_confirmed) {
            wp_send_json_error([
                'message' => 'You have already confirmed this booking.'
            ]);
        }
        $booking['provider_confirmed'] = $now;
        $provider_confirmed = true;
    } elseif ($role === 'client') {
        if ($client_confirmed) {
            wp_send_json_error([
                'message' => 'You have already confirmed this booking.'
            ]);
        }
        $booking['client_confirmed'] = $now;
        $client_confirmed = true;
    } else {
        // Fallback if we don’t have provider/client ids stored yet:
        if ($provider_confirmed) {
            wp_send_json_error([
                'message' => 'You have already confirmed this booking.'
            ]);
        }
        $booking['provider_confirmed'] = $now;
        $provider_confirmed = true;
    }

    // Decide if both sides have confirmed
    if ($provider_confirmed && $client_confirmed) {
        $booking['status'] = 'completed';

        // 🔐 OPTIONAL: trigger escrow release if your helper exists
        if (function_exists('balo_escrow_release')) {
            try {
                // You can adjust the signature of this helper to match your real one
                balo_escrow_release($booking_id, $kind, $booking);
            } catch (Throwable $e) {
                // Optional: log error but don’t break the user flow
                error_log('Balo escrow release error: ' . $e->getMessage());
            }
        }

        $bookings[$b_key] = $booking;
        update_user_meta($uid, $meta_key, $bookings);

        wp_send_json_success([
            'stage'   => 'fully_completed',
            'status'  => 'completed',
            'message' => 'Booking marked as fully completed. Escrow release will follow.'
        ]);
    }

    // Only *this* user has confirmed so far
    $booking['status']   = 'awaiting_other';
    $bookings[$b_key]    = $booking;
    update_user_meta($uid, $meta_key, $bookings);

    wp_send_json_success([
        'stage'   => 'self_confirmed',
        'status'  => 'awaiting_other',
        'message' => 'You have confirmed – waiting for the other side.'
    ]);
}

add_action('wp_ajax_balo_delete_single_notification', function () {

    if (!is_user_logged_in()) {
        wp_send_json_error(['msg' => 'Not logged in']);
    }

    $uid = get_current_user_id();
    $nid = sanitize_text_field($_POST['notification_id'] ?? '');

    if (!$nid) {
        wp_send_json_error(['msg' => 'Missing notification ID']);
    }

    error_log("DELETE CALLED: uid=$uid nid=$nid");

    // Load both storage formats
    $notes     = get_user_meta($uid, 'balo_notifications', true);
    $notes_alt = get_user_meta($uid, 'balo_my_notifications', true);

    if (!is_array($notes))     $notes     = [];
    if (!is_array($notes_alt)) $notes_alt = [];

    error_log("NOTES BEFORE MAIN: " . print_r($notes, true));
    error_log("NOTES BEFORE ALT: " . print_r($notes_alt, true));

    /* ==========================================================
       MAIN DELETE LOGIC — removes notifications by matching:
       - array key equals the ID
       - OR note['id'] equals the ID
       ========================================================== */

    // ---- MAIN STORAGE ----
    $changed_main = false;
    foreach ($notes as $key => $note) {
        $note_id = $note['id'] ?? $key;

        if ((string)$note_id === (string)$nid) {
            unset($notes[$key]);
            $changed_main = true;
        }
    }
    if ($changed_main) {
        update_user_meta($uid, 'balo_notifications', $notes);
    }

    // ---- LEGACY STORAGE ----
    $changed_alt = false;
    foreach ($notes_alt as $key => $note) {
        $note_id = $note['id'] ?? $key;

        if ((string)$note_id === (string)$nid) {
            unset($notes_alt[$key]);
            $changed_alt = true;
        }
    }
    if ($changed_alt) {
        update_user_meta($uid, 'balo_my_notifications', $notes_alt);
    }

    /* ---------------------------------------------------------
       RECALCULATE unread
       --------------------------------------------------------- */
    $unread = 0;
    foreach ($notes as $n) {
        if (empty($n['read'])) {
            $unread++;
        }
    }

    update_user_meta($uid, 'balo_unread_count', $unread);

    // Debug logs
    error_log("NOTES AFTER MAIN: " . print_r($notes, true));
    error_log("NOTES AFTER ALT: " . print_r($notes_alt, true));
    error_log("UNREAD AFTER: $unread");

    wp_send_json_success([
        'msg'    => 'Notification removed',
        'unread' => $unread,
    ]);
});


/**
 * ----------------------------------------------------
 * MEDIA SIZES, MIME TYPES, OAUTH SAFETY
 * ----------------------------------------------------
 */

/**
 * Stop theme output during Google OAuth callback so headers don’t break.
 */
add_action('template_redirect', function () {
    if (isset($_GET['code']) && !is_user_logged_in()) {
        remove_all_actions('wp_head');
        remove_all_actions('wp_body_open');
        remove_all_actions('get_header');
    }
});

/**
 * Avatar size.
 */
add_action('after_setup_theme', function () {
    add_image_size('db-avatar', 512, 512, true);
});

/**
 * Extra mime types for uploads.
 */
add_filter('upload_mimes', function ($mimes) {
    $mimes['heic'] = 'image/heic';
    $mimes['heif'] = 'image/heif';
    $mimes['webp'] = 'image/webp';
    $mimes['mov']  = 'video/quicktime';
    $mimes['mp4']  = 'video/mp4';
    return $mimes;
});

// 🔒 Hide the WordPress admin bar on the front-end
add_filter('show_admin_bar', function ($show) {
    // Keep it visible in wp-admin, hide it on the public site
    if (is_admin()) {
        return $show;
    }
    return false;
});

/* ============================================================
   BALO COOKIE CONSENT — CSS + JS + BANNER
   (Front page only, logged-out only)
   ============================================================ */
add_action('wp_enqueue_scripts', function () {
    if (is_user_logged_in()) return;
    if (!is_front_page()) return;

    $css_path = get_stylesheet_directory() . '/assets/css/balo-cookie.css';
    if (file_exists($css_path)) {
        wp_enqueue_style(
            'balo-cookie-css',
            get_stylesheet_directory_uri() . '/assets/css/balo-cookie.css',
            [],
            filemtime($css_path)
        );
    }

    $js_path = get_stylesheet_directory() . '/assets/js/balo-cookie.js';
    if (file_exists($js_path)) {
        wp_enqueue_script(
            'balo-cookie-js',
            get_stylesheet_directory_uri() . '/assets/js/balo-cookie.js',
            [],
            filemtime($js_path),
            true
        );
    }
});

add_action('wp_footer', function () {
    if (is_user_logged_in()) return;
    if (!is_front_page()) return; ?>
    <div id="balo-cookie-banner" class="balo-cookie-hidden">
        <div class="balo-cookie-box">
            <h3>🍪 We Use Cookies</h3>
            <p>
                We use cookies to enhance your browsing experience, show personalised content,
                and analyse site traffic. By clicking “Accept”, you agree to our use of cookies.
                You may change your preferences or reject certain cookies.
            </p>

            <div class="balo-cookie-buttons">
                <button id="balo-cookie-accept" class="balo-cookie-btn accept">Accept All</button>
                <button id="balo-cookie-reject" class="balo-cookie-btn reject">Reject All</button>
                <button id="balo-cookie-custom" class="balo-cookie-btn custom">Customise</button>
            </div>
        </div>

        <div id="balo-cookie-modal" class="balo-cookie-modal">
            <div class="balo-cookie-modal-content">
                <h3>🍪 Cookie Preferences</h3>

                <label class="balo-cookie-toggle">
                    <input type="checkbox" id="cookie-essential" checked disabled>
                    <span>Essential Cookies (Always Active)</span>
                </label>

                <label class="balo-cookie-toggle">
                    <input type="checkbox" id="cookie-analytics">
                    <span>Analytics & Performance</span>
                </label>

                <label class="balo-cookie-toggle">
                    <input type="checkbox" id="cookie-marketing">
                    <span>Marketing & Personalisation</span>
                </label>

                <div class="balo-cookie-modal-buttons">
                    <button id="balo-save-custom" class="balo-cookie-btn accept">Save</button>
                    <button id="balo-close-custom" class="balo-cookie-btn reject">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php
});
// Only declare if not already provided by Hub / MU plugin
if ( ! function_exists('balo_get_plan_status') ) {
    /**
     * Normalised subscription status for a user + plan.
     *
     * Supported data sources (in order):
     *  1) New simple meta: service_plan_due / mentorship_plan_due
     *  2) Hub meta:       balo_plan_service / balo_plan_mentorship
     *  3) Legacy flags:   service_subscription / mentorship_subscription
     *  4) Legacy table:   wp_balo_bookings
     */
    function balo_get_plan_status(int $user_id, string $plan): array {
        global $wpdb;

        $user_id = (int) $user_id;
        if (!$user_id) {
            return [
                'active'   => false,
                'state'    => 'inactive',
                'last_paid'=> null,
                'due_at'   => null,
                'reason'   => 'No user',
            ];
        }

        $plan = ($plan === 'mentorship') ? 'mentorship' : 'service';

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 24 * 60 * 60);
        }

        $now         = current_time('timestamp');
        $window_days = (int) apply_filters(
            'balo_plan_window_days',
            defined('BALO_PLAN_WINDOW_DAYS') ? BALO_PLAN_WINDOW_DAYS : 31
        );
        $grace_days  = (int) apply_filters(
            'balo_plan_grace_days',
            defined('BALO_PLAN_GRACE_DAYS') ? BALO_PLAN_GRACE_DAYS : 3
        );

        /* ---------------------------------------------
         * 0) Admin block (always wins)
         * --------------------------------------------- */
        if ((bool) get_user_meta($user_id, 'balo_block_' . $plan, true)) {
            return [
                'active'   => false,
                'state'    => 'blocked',
                'last_paid'=> null,
                'due_at'   => null,
                'reason'   => 'Blocked by admin',
            ];
        }

        /* ---------------------------------------------
         * 1) NEW SIMPLE META: *_plan_due
         * --------------------------------------------- */
        $prefix = ($plan === 'service') ? 'service_plan' : 'mentorship_plan';
        $due    = (int) get_user_meta($user_id, $prefix . '_due', true);

        if ($due > 0) {
            $grace_until = $due + ($grace_days * DAY_IN_SECONDS);

            if ($now <= $due) {
                return [
                    'active'   => true,
                    'state'    => 'active',
                    'last_paid'=> null,
                    'due_at'   => $due,
                    'reason'   => 'Within paid window (plan_due meta)',
                ];
            }

            if ($now <= $grace_until) {
                return [
                    'active'   => true,
                    'state'    => 'grace',
                    'last_paid'=> null,
                    'due_at'   => $due,
                    'reason'   => 'Within grace period (plan_due meta)',
                ];
            }

            return [
                'active'   => false,
                'state'    => 'overdue',
                'last_paid'=> null,
                'due_at'   => $due,
                'reason'   => 'Past grace period (plan_due meta)',
            ];
        }

        /* ---------------------------------------------
         * 2) HUB META: balo_plan_service / mentorship
         * --------------------------------------------- */
        $hub_key = 'balo_plan_' . $plan;
        $st      = get_user_meta($user_id, $hub_key, true);

        if (is_array($st) && isset($st['state'])) {
            $state  = (string) ($st['state'] ?? 'inactive');
            $due_at = !empty($st['next_due']) ? (int) $st['next_due'] : null;

            $active_flag = !empty($st['active']) && $state !== 'blocked';

            return [
                'active'   => $active_flag,
                'state'    => $state,
                'last_paid'=> null,
                'due_at'   => $due_at,
                'reason'   => 'From plan meta (Hub webhooks)',
            ];
        }

        /* ---------------------------------------------
         * 3) Legacy manual flag
         * --------------------------------------------- */
        if ((bool) get_user_meta($user_id, $plan . '_subscription', true)) {
            return [
                'active'   => true,
                'state'    => 'active',
                'last_paid'=> null,
                'due_at'   => null,
                'reason'   => 'Manual subscription flag active',
            ];
        }

        /* ---------------------------------------------
         * 4) Legacy fallback: balo_bookings table
         * --------------------------------------------- */
        $bookings_table = $wpdb->prefix . 'balo_bookings';
        $kinds          = ['subscription', 'subscription_' . $plan, $plan . '_subscription'];
        $ok_status      = ['paid', 'completed', 'confirmed'];

        $in_k  = implode("','", array_map('esc_sql', $kinds));
        $in_st = implode("','", array_map('esc_sql', $ok_status));

        $row = $wpdb->get_row("
            SELECT booked_at
            FROM {$bookings_table}
            WHERE student_id = " . (int) $user_id . "
              AND kind   IN ('{$in_k}')
              AND status IN ('{$in_st}')
            ORDER BY booked_at DESC
            LIMIT 1
        ", ARRAY_A);

        if (!$row || empty($row['booked_at'])) {
            return [
                'active'   => false,
                'state'    => 'inactive',
                'last_paid'=> null,
                'due_at'   => null,
                'reason'   => 'No subscription payment found',
            ];
        }

        $last_paid_ts = strtotime(get_date_from_gmt(
            gmdate('Y-m-d H:i:s', strtotime($row['booked_at']))
        ));

        $due_at      = strtotime('+' . $window_days . ' days', $last_paid_ts);
        $grace_until = strtotime('+' . $grace_days . ' days', $due_at);

        if ($now <= $due_at) {
            return [
                'active'   => true,
                'state'    => 'active',
                'last_paid'=> $last_paid_ts,
                'due_at'   => $due_at,
                'reason'   => 'Within paid window (bookings)',
            ];
        }

        if ($now <= $grace_until) {
            return [
                'active'   => true,
                'state'    => 'grace',
                'last_paid'=> $last_paid_ts,
                'due_at'   => $due_at,
                'reason'   => 'Within grace period (bookings)',
            ];
        }

        return [
            'active'   => false,
            'state'    => 'overdue',
            'last_paid'=> $last_paid_ts,
            'due_at'   => $due_at,
            'reason'   => 'Past grace period (bookings)',
        ];
    }
}


/**
 * Simple home parallax effect (front page only)
 */
add_action('wp_footer', function () {
    if (!is_front_page()) return; ?>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
      const front = document.createElement("div");
      const back  = document.createElement("div");

      front.className = "home-parallax-layer layer-front";
      back.className  = "home-parallax-layer layer-back";

      document.body.appendChild(back);
      document.body.appendChild(front);

      document.body.classList.add("parallax-active");

      window.addEventListener("scroll", () => {
        const y  = window.scrollY * 0.08;
        const y2 = window.scrollY * 0.03;

        front.style.transform = `translateY(${y}px)`;
        back.style.transform  = `translateY(${y2}px)`;
      });
    });
    </script>
<?php
}, 40);

if (!function_exists('balo_mark_plan_active_for_user')) {
    function balo_mark_plan_active_for_user($user_id, $plan, $days = 30) {
        $user_id = (int) $user_id;
        if (!$user_id) return;

        $plan   = ($plan === 'service') ? 'service' : 'mentorship';
        $prefix = ($plan === 'service') ? 'service_plan' : 'mentorship_plan';

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 24 * 60 * 60);
        }

        $days = max(1, (int) $days);
        $due  = time() + ($days * DAY_IN_SECONDS);

        update_user_meta($user_id, $prefix . '_due', $due);
        update_user_meta($user_id, $prefix . '_last_set', time());
    }
}


/**
 * ----------------------------------------------------
 * BODY CLASSES FOR TEMPLATES
 * ----------------------------------------------------
 */
add_filter('body_class', function ($classes) {
    if (is_page_template('page-dashboard.php')) $classes[] = 'db-body';
    if (is_page_template('page-profile.php'))   $classes[] = 'pf-body';
    if (is_page_template('marketplace.php'))    $classes[] = 'mk-body';
    return $classes;
}, 10, 1);
// Count unread Balo Chat messages for a user (from balo_conversations table)
if ( ! function_exists( 'balo_get_unread_chat_count' ) ) {
    function balo_get_unread_chat_count( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return 0;
        }

        $table = $wpdb->prefix . 'balo_conversations';

        // Sum unread_two where current user is user_one, and unread_one where current user is user_two
        $sql = "
            SELECT COALESCE(SUM(unread), 0) AS total_unread
            FROM (
                SELECT SUM(unread_two) AS unread
                FROM {$table}
                WHERE user_one = %d
                UNION ALL
                SELECT SUM(unread_one) AS unread
                FROM {$table}
                WHERE user_two = %d
            ) AS t
        ";

        $total = (int) $wpdb->get_var( $wpdb->prepare( $sql, $user_id, $user_id ) );
        return max( 0, $total );
    }
}


/**
 * ----------------------------------------------------
 * AVATAR + STORY HELPERS (DELEGATE TO MU PLUGIN)
 * ----------------------------------------------------
 */

/**
 * Resolve avatar attachment ID for a user (or fall back to custom logo).
 */
if (!function_exists('balo_resolve_avatar_attachment_id')) {
    function balo_resolve_avatar_attachment_id($user_id) {
        $aid = (int) get_user_meta($user_id, 'avatar_id', true);

        if (!$aid) {
            $logo_id = (int) get_theme_mod('custom_logo');
            if ($logo_id) {
                $aid = $logo_id;
            }
        }

        return $aid;
    }
}

/**
 * Does the user have at least one active story?
 * Uses balo_user_active_stories() from the Balo Stories MU-plugin.
 */
if (!function_exists('balo_user_has_story')) {
    function balo_user_has_story($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) return false;

        if (!function_exists('balo_user_active_stories')) {
            return false;
        }

        $stories = balo_user_active_stories($user_id);
        return is_array($stories) && !empty($stories);
    }
}

/**
 * Get latest story URL for a user (used for data-story-url on avatars).
 */
if (!function_exists('balo_get_latest_story_url')) {
    function balo_get_latest_story_url($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) return '';

        if (!function_exists('balo_user_active_stories')) {
            return '';
        }

        $stories = balo_user_active_stories($user_id);

        if (!is_array($stories) || empty($stories)) {
            return '';
        }

        foreach ($stories as $story) {
            if (is_array($story) && !empty($story['url'])) {
                return esc_url_raw($story['url']);
            }
        }

        return '';
    }
}

/**
 * Print a user avatar wrapped in a story-aware container.
 */
if (!function_exists('balo_print_user_avatar')) {
    function balo_print_user_avatar($user_id = null, $img_class = 'db-avatar', $wrap_class = 'db-avatar-wrap') {
        $user_id = $user_id ?: get_current_user_id();
        $user_id = (int) $user_id;
        if (!$user_id) return;

        $aid = function_exists('balo_resolve_avatar_attachment_id')
            ? balo_resolve_avatar_attachment_id($user_id)
            : 0;

        $latest_url = '';
        $has_story  = false;

        if (function_exists('balo_user_has_story') && function_exists('balo_get_latest_story_url')) {
            $has_story  = balo_user_has_story($user_id);
            $latest_url = $has_story ? balo_get_latest_story_url($user_id) : '';
        }

        $wrap_classes = $wrap_class . ($has_story ? ' has-story' : '');

        echo '<div class="' . esc_attr($wrap_classes) . '" data-user-id="' . esc_attr($user_id) . '"';
        if ($latest_url) {
            echo ' data-story-url="' . esc_url($latest_url) . '"';
        }
        echo '>';

        if ($aid) {
            echo wp_get_attachment_image(
                $aid,
                'db-avatar',
                false,
                [
                    'class'         => $img_class,
                    'loading'       => 'lazy',
                    'decoding'      => 'async',
                    'fetchpriority' => 'low',
                    'sizes'         => '(max-width: 600px) 120px, 160px',
                ]
            );
        } else {
            echo get_avatar(
                $user_id,
                160,
                '',
                esc_attr(get_the_author_meta('display_name', $user_id)),
                [
                    'class'         => $img_class,
                    'loading'       => 'lazy',
                    'decoding'      => 'async',
                    'fetchpriority' => 'low',
                ]
            );
        }

        echo '</div>';
    }
}


/**
 * ----------------------------------------------------
 * GLOBAL FRONT-END ENQUEUES (CHILD)
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    // Remove any legacy child-global handle
    if (wp_style_is('balo-child-global', 'enqueued')) {
        wp_dequeue_style('balo-child-global');
    }
    if (wp_style_is('balo-child-global', 'registered')) {
        wp_deregister_style('balo-child-global');
    }

    // Home page CSS
    if (is_front_page()) {
        $home_rel = '/assets/css/home.css';
        $home_abs = get_stylesheet_directory() . $home_rel;
        if (file_exists($home_abs)) {
            wp_enqueue_style(
                'balo-home',
                get_stylesheet_directory_uri() . $home_rel,
                ['balo-layout', 'balo-global'],
                filemtime($home_abs)
            );
        }
    }

    // Debug CSS only when WP_DEBUG is true
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $dbg_rel = '/assets/css/debug.css';
        $dbg_abs = get_stylesheet_directory() . $dbg_rel;
        if (file_exists($dbg_abs)) {
            wp_enqueue_style(
                'balo-debug',
                get_stylesheet_directory_uri() . $dbg_rel,
                ['balo-global'],
                filemtime($dbg_abs)
            );
        }
    }
}, 30);

/**
 * Child Zen Calm overrides (day/night, backgrounds, readability)
 * Runs AFTER parent global/layout CSS.
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    $rel = '/assets/css/zen-calm-fix.css';
    $abs = get_stylesheet_directory() . $rel;

    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-zen-calm-fix',
            get_stylesheet_directory_uri() . $rel,
            ['balo-global', 'balo-layout'],
            filemtime($abs)
        );
    }
}, 50);

// Add dashboard classes only on the dashboard template
function balo_add_db_classes($classes){
    if (is_page_template('page-dashboard.php')) { // adjust file name if different
        if (!in_array('db-body', $classes, true))  $classes[] = 'db-body';
        if (!in_array('zen-calm', $classes, true)) $classes[] = 'zen-calm';
    }
    return $classes;
}
add_filter('body_class', 'balo_add_db_classes');
/**
 * Enqueue CHILD FAB assets (CSS + JS) and pass config.
 */
function balo_child_enqueue_fabs_assets() {
    if (is_admin()) {
        return;
    }

    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    // ------------ FAB CSS ------------
    $fabs_css_rel = '/assets/css/fabs.css';
    $fabs_css_abs = $theme_dir . $fabs_css_rel;

    if (file_exists($fabs_css_abs)) {
        wp_enqueue_style(
            'balo-fabs',
            $theme_uri . $fabs_css_rel,
            ['balo-layout', 'balo-global'],
            filemtime($fabs_css_abs)
        );
    }

    // ------------ FAB JS ------------
    $fabs_js_rel = '/assets/js/fabs.js';
    $fabs_js_abs = $theme_dir . $fabs_js_rel;

    if (!file_exists($fabs_js_abs)) {
        return;
    }

    wp_enqueue_script(
        'balo-fabs',
        $theme_uri . $fabs_js_rel,
        [], // no jQuery needed
        filemtime($fabs_js_abs),
        true
    );

    // Pass unread state + URLs
    $uid    = get_current_user_id();
    $unread = 0;

    if ($uid && function_exists('balo_get_unread_notification_count')) {
        $unread = (int) balo_get_unread_notification_count($uid);
    }

    wp_localize_script('balo-fabs', 'BALO_FABS', [
        'isLoggedIn'   => is_user_logged_in(),
        'unread'       => $unread,
        'myAccountUrl' => site_url('/my-account/#tab-notifications'),
        'baseRight'    => 16,
        'baseBottom'   => 96,
    ]);
}
add_action('wp_enqueue_scripts', 'balo_child_enqueue_fabs_assets', 60);

/**
 * FLOATING ACTION BUTTONS (FABs) — markup in footer
 */
add_action('wp_footer', 'balo_child_side_fabs', 50);
function balo_child_side_fabs() {
    if (is_admin()) {
        return;
    }

    $is_logged_in = is_user_logged_in();
    $uid          = get_current_user_id();
    $base_unread  = 0;

    if ($is_logged_in && $uid && function_exists('balo_get_unread_notification_count')) {
        $base_unread = (int) balo_get_unread_notification_count($uid);
    }

    // Extra unread counts from chat, follows, payments...
    $extra_unread = 0;
    if ($is_logged_in && $uid) {
        $extra_unread = (int) apply_filters('balo_fab_account_extra_unread', 0, $uid);
    }

    $unread_total = max(0, $base_unread + $extra_unread);
    $account_url  = site_url('/my-account/#tab-notifications');

    // Account FAB classes
    $account_classes = 'balo-fab show balo-fab-account';
    if ($unread_total > 0) {
        $account_classes .= ' has-unread';
    }
    ?>
    <nav class="balo-side-fabs is-expanded"
         id="balo-fabs"
         aria-live="polite"
         aria-label="Quick actions"
         data-unread="<?php echo esc_attr($unread_total); ?>">

        <?php if ($is_logged_in): ?>

            <!-- Account FAB -->
            <a class="<?php echo esc_attr($account_classes); ?>"
               href="<?php echo esc_url($account_url); ?>">
                <span class="dot" aria-hidden="true"></span>
                <span class="label">Account</span>

                <?php if ($unread_total > 0): ?>
                    <span class="fab-badge"
                          data-fab-badge="account"
                          aria-label="<?php echo esc_attr($unread_total . ' unread notifications'); ?>">
                        <?php echo ($unread_total > 99) ? '99+' : $unread_total; ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Other FABs -->
            <a class="balo-fab show" href="<?php echo esc_url(home_url('/my-profile/')); ?>">
                <span class="dot" aria-hidden="true"></span> My Profile
            </a>
            <a class="balo-fab show" href="<?php echo esc_url(home_url('/marketplace/')); ?>">
                <span class="dot" aria-hidden="true"></span> Explore
            </a>
            <a class="balo-fab show" href="<?php echo esc_url(home_url('/dashboard/')); ?>">
                <span class="dot" aria-hidden="true"></span> Dashboard
            </a>
            <a class="balo-fab show" href="<?php echo esc_url(home_url('/balo-chat/')); ?>">
                <span class="dot" aria-hidden="true"></span> Balo Chat
            </a>
            <a class="balo-fab show" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                <span class="dot" aria-hidden="true"></span> Logout
            </a>

        <?php else: ?>

            <!-- Guest -->
            <a class="balo-fab show" href="<?php echo esc_url(home_url('/login/')); ?>">
                <span class="dot" aria-hidden="true"></span> Login
            </a>
            <a class="balo-fab show" href="<?php echo esc_url('https://baloservices.co.uk/registration/'); ?>">
                <span class="dot" aria-hidden="true"></span> Register
            </a>

        <?php endif; ?>

        <!-- Back to top -->
        <button class="balo-fab show" id="fab-top" type="button" aria-label="Back to top">
            <span class="dot" aria-hidden="true"></span> Top
        </button>

        <!-- FAB Toggle (Zen icons: ༄ expanded, ◐ collapsed via JS) -->
        <button class="balo-fabs-toggle" type="button" aria-label="Toggle quick menu" aria-expanded="true">
            <span class="balo-fabs-toggle-icon">༄</span>
            <span class="balo-fabs-toggle-text">●●●</span>
        </button>

    </nav>
    <?php
}

/**
 * ----------------------------------------------------
 * DASHBOARD / PROFILE / MARKETPLACE — CSS + JS
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template(['page-dashboard.php', 'page-profile.php', 'marketplace.php'])) return;

    $dash_css = get_stylesheet_directory() . '/assets/css/dashboard.css';
    if (file_exists($dash_css)) {
        wp_enqueue_style(
            'balo-dashboard',
            get_stylesheet_directory_uri() . '/assets/css/dashboard.css',
            ['balo-layout', 'balo-global'],
            filemtime($dash_css)
        );
    }

    $dash_js = get_stylesheet_directory() . '/assets/js/dashboard.js';
    if (file_exists($dash_js)) {
        wp_enqueue_script(
            'balo-dashboard',
            get_stylesheet_directory_uri() . '/assets/js/dashboard.js',
            ['jquery'],
            filemtime($dash_js),
            true
        );

                wp_localize_script('balo-dashboard', 'BALO_DASH', [
            'ajax'                 => admin_url('admin-ajax.php'),
            'nonceSaveProfile'     => wp_create_nonce('balo_save_profile'),
            'nonceDeleteListing'   => wp_create_nonce('balo_delete_listing'),
            'nonceCompleteBooking' => wp_create_nonce('balo_complete_booking'),

            // NEW – disputes
            'nonceOpenDispute'     => wp_create_nonce('balo_open_dispute'),
            'nonceReplyDispute'    => wp_create_nonce('balo_reply_dispute'),
            'nonceGetDispute'      => wp_create_nonce('balo_get_dispute'),
        ]);

    }
}, 20);


/**
 * ----------------------------------------------------
 * PROFILE PAGE JS (FOLLOW / STORY / MERIT)
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    $rel = '/assets/js/balo-profile.js';
    $abs = get_stylesheet_directory() . $rel;

    if (!file_exists($abs)) return;

    wp_enqueue_script(
        'balo-profile',
        get_stylesheet_directory_uri() . $rel,
        [],
        filemtime($abs),
        true
    );

    // For legacy profile AJAX (follow/report/etc)
    wp_localize_script('balo-profile', 'baloProfile', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('balo_profile'),
        'user_id' => get_current_user_id(),
    ]);
}, 20);


function balo_child_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $child_admin = get_stylesheet_directory() . '/admin.php';

    if (file_exists($child_admin)) {
        require $child_admin;
    } else {
        echo '<div class="wrap"><h1>Balo Admin</h1><p>Child admin.php not found.</p></div>';
    }
}

/**
 * Admin CSS/JS for Balo Admin page.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_skillswap-admin') {
        return;
    }

    $base_dir = get_stylesheet_directory() . '/assets/admin/';
    $base_uri = get_stylesheet_directory_uri() . '/assets/admin/';

    $css_path = $base_dir . 'admin.css';
    if (file_exists($css_path)) {
        wp_enqueue_style(
            'balo-admin',
            $base_uri . 'admin.css',
            [],
            filemtime($css_path)
        );
    }

    $js_path = $base_dir . 'admin.js';
    if (file_exists($js_path)) {
        wp_enqueue_script(
            'balo-admin-js',
            $base_uri . 'admin.js',
            ['jquery'],
            filemtime($js_path),
            true
        );
    }
});


/**
 * ----------------------------------------------------
 * PAGE-SPECIFIC CSS ENQUEUES
 * ----------------------------------------------------
 */

// Login
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('page-login.php')) return;

    $rel = '/assets/css/login.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-login',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout', 'balo-global'],
            filemtime($abs)
        );
    }
}, 30);

// Registration
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('page-register.php')) return;

    $rel = '/assets/css/registration.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-registration',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 30);

// My Account
add_action('wp_enqueue_scripts', function () {
    if (!(is_page_template('my-account.php') || is_page_template('page-my-account.php'))) return;

    $rel = '/assets/css/my-account.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-my-account',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 30);

// Subscription success
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('subscription-success.php')) return;

    $rel = '/assets/css/subscription-success.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-subscription-success',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 30);

// Subscription page
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('subscription.php')) return;

    $rel = '/assets/css/subscription.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-subscription',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 30);

// FAQ
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('faq.php')) return;

    $rel = '/assets/css/faq.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-faq',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 30);

// Terms of Service
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('terms-of-services.php')) return;

    $rel = '/assets/css/tos.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-tos',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 40);

// Privacy
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('privacy.php')) return;

    $rel = '/assets/css/privacy.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_enqueue_style(
            'balo-privacy',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout'],
            filemtime($abs)
        );
    }
}, 40);

// Connect Payments CSS (register only)
add_action('wp_enqueue_scripts', function () {
    $rel = '/assets/css/connect-payments.css';
    $abs = get_stylesheet_directory() . $rel;
    if (file_exists($abs)) {
        wp_register_style(
            'balo-connect-payments',
            get_stylesheet_directory_uri() . $rel,
            ['balo-layout', 'balo-global'],
            filemtime($abs)
        );
    }
}, 30);


/**
 * ----------------------------------------------------
 * BOOKING CHECKOUT (Stripe) — Marketplace + Profile
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template(['marketplace.php', 'page-profile.php'])) {
        return;
    }

    if (!wp_script_is('stripe-js', 'enqueued')) {
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
    }

    $rel = '/assets/js/booking-checkout.js';
    $abs = get_stylesheet_directory() . $rel;

    if (!file_exists($abs)) {
        return;
    }

    wp_enqueue_script(
        'balo-booking-checkout',
        get_stylesheet_directory_uri() . $rel,
        ['jquery', 'stripe-js'],
        filemtime($abs),
        true
    );

    $stripe_pub = '';
    if (defined('STRIPE_PUBLISHABLE') && STRIPE_PUBLISHABLE) {
        $stripe_pub = STRIPE_PUBLISHABLE;
    } elseif (defined('BALO_STRIPE_PUBLISHABLE') && BALO_STRIPE_PUBLISHABLE) {
        $stripe_pub = BALO_STRIPE_PUBLISHABLE;
    } elseif (defined('STRIPE_PUBLISHABLE_KEY') && STRIPE_PUBLISHABLE_KEY) {
        $stripe_pub = STRIPE_PUBLISHABLE_KEY;
    }

    wp_localize_script('balo-booking-checkout', 'BALO_PAY', [
        'endpoint'   => esc_url_raw(rest_url('balo-pay/v1/booking/checkout')),
        'nonce'      => wp_create_nonce('wp_rest'),
        'stripe_pub' => $stripe_pub,
    ]);
}, 40);


/**
 * ----------------------------------------------------
 * DEQUEUES / PERFORMANCE
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    if (!is_page(['dashboard', 'my-profile', 'marketplace'])) {
        wp_dequeue_style('balo-live-css');
        wp_dequeue_script('balo-live-js');
    }

    if (!(is_front_page() || is_page('balo'))) {
        wp_dequeue_style('balo-ai-doorkeeper');
        wp_dequeue_script('balo-ai-doorkeeper');
    }
}, 999);

add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    wp_dequeue_style('classic-theme-styles');
    wp_deregister_style('classic-theme-styles');
}, 100);


/**
 * ----------------------------------------------------
 * SUBSCRIPTION PAGE — Stripe + Helper JS
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('subscription.php')) return;

    wp_enqueue_script(
        'stripe-js',
        'https://js.stripe.com/v3/',
        [],
        null,
        true
    );

    $rel = '/assets/js/subscription.js';
    $abs = get_stylesheet_directory() . $rel;

    if (!file_exists($abs)) return;

    wp_enqueue_script(
        'balo-subscription',
        get_stylesheet_directory_uri() . $rel,
        ['stripe-js'],
        filemtime($abs),
        true
    );

    $pub = defined('BALO_STRIPE_PUBLISHABLE') ? BALO_STRIPE_PUBLISHABLE : '';

    wp_localize_script('balo-subscription', 'BALO_SUB', [
        'rest'        => esc_url_raw(rest_url('balo/v1/subscriptions/create-checkout-session')),
        'nonce'       => wp_create_nonce('wp_rest'),
        'publishable' => $pub,
    ]);
}, 40);


/**
 * ----------------------------------------------------
 * FORCE LIVE CLASSROOM ASSETS ON LIVE MEETINGS PAGE
 * ----------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    if (!(is_page(27233) || is_page('live-meetings'))) return;

    $plugin_file = WP_PLUGIN_DIR . '/balo-live-classroom/balo-live-classroom.php';
    if (!file_exists($plugin_file)) return;

    $base_url = plugin_dir_url($plugin_file);
    $base_dir = plugin_dir_path($plugin_file);

    $css_rel = 'assets/css/live.css';
    $js_rel  = 'assets/js/live.js';

    $css_path = $base_dir . $css_rel;
    $js_path  = $base_dir . $js_rel;

    $css_ver = file_exists($css_path) ? filemtime($css_path) : null;
    $js_ver  = file_exists($js_path) ? filemtime($js_path) : null;

    wp_enqueue_style(
        'balo-live-css',
        $base_url . $css_rel,
        [],
        $css_ver
    );

    wp_enqueue_script(
        'balo-live-js',
        $base_url . $js_rel,
        [],
        $js_ver,
        true
    );
}, 5);


/**
 * ----------------------------------------------------
 * AJAX: OLD PROFILE FOLLOW / REPORT (LEGACY API)
 * ----------------------------------------------------
 */
add_action('wp_ajax_balo_profile_follow_toggle', function () {
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'msg' => 'Login required'], 403);
    }

    check_ajax_referer('balo_profile');

    $viewer = get_current_user_id();
    $target = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if (!$target || $target === $viewer) {
        wp_send_json(['ok' => false, 'msg' => 'Invalid target'], 400);
    }

    $followers = get_user_meta($target, 'followers', true);
    if (!is_array($followers)) {
        $followers = [];
    }

    $key       = array_search($viewer, $followers, true);
    $following = false;

    if ($key === false) {
        $followers[] = $viewer;
        $following   = true;
    } else {
        unset($followers[$key]);
        $followers = array_values($followers);
    }

    update_user_meta($target, 'followers', $followers);
    wp_send_json(['ok' => true, 'following' => $following, 'count' => count($followers)]);
});

add_action('wp_ajax_balo_profile_report_user', function () {
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'msg' => 'Login required'], 403);
    }

    check_ajax_referer('balo_profile');

    $reportee = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $reason   = sanitize_text_field($_POST['reason'] ?? '');
    $details  = sanitize_textarea_field($_POST['details'] ?? '');

    if (!$reportee || !$reason) {
        wp_send_json(['ok' => false, 'msg' => 'Missing fields'], 400);
    }

    $current = wp_get_current_user();
    $subject = 'Balo Report: user #' . $reportee . ' (' . $reason . ')';
    $message = "Reporter: {$current->ID} {$current->user_email}\nUser: {$reportee}\nReason: {$reason}\nDetails:\n{$details}";
    @wp_mail(get_option('admin_email'), $subject, $message);

    $reports = get_user_meta($reportee, 'reports', true);
    if (!is_array($reports)) {
        $reports = [];
    }
    $reports[] = [
        'by'     => $current->ID,
        'reason' => $reason,
        'details'=> $details,
        'time'   => time()
    ];
    update_user_meta($reportee, 'reports', $reports);

    wp_send_json(['ok' => true]);
});


/**
 * ----------------------------------------------------
 * AJAX: NEW FOLLOW / UNFOLLOW (PROFILE + MARKETPLACE)
 * ----------------------------------------------------
 */
add_action('wp_ajax_balo_toggle_follow', function () {
    if (!is_user_logged_in()) {
        wp_send_json_error(['msg' => 'Login required.'], 401);
    }

    $viewer_id  = get_current_user_id();
    $target_id  = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;

    // Accept either "_ajax_nonce" or "nonce" from JS
    $nonce = '';
    if (!empty($_POST['_ajax_nonce'])) {
        $nonce = sanitize_text_field($_POST['_ajax_nonce']);
    } elseif (!empty($_POST['nonce'])) {
        $nonce = sanitize_text_field($_POST['nonce']);
    }

    if (!$target_id || $target_id === $viewer_id) {
        wp_send_json_error(['msg' => 'Invalid user.'], 400);
    }

    // Nonce must be created with wp_create_nonce('balo_follow_'.$target_id)
    if (!wp_verify_nonce($nonce, 'balo_follow_' . $target_id)) {
        wp_send_json_error(['msg' => 'Security check failed.'], 403);
    }

    $followers = get_user_meta($target_id, 'followers', true);
    if (!is_array($followers)) {
        $followers = [];
    }

    $key          = array_search($viewer_id, $followers, true);
    $is_following = false;

    if ($key === false) {
        $followers[]  = $viewer_id;
        $is_following = true;
    } else {
        unset($followers[$key]);
        $followers = array_values($followers);
    }

    update_user_meta($target_id, 'followers', $followers);

    wp_send_json_success([
        'following'        => $is_following,
        'followers_count'  => count($followers),
        'new_nonce'        => wp_create_nonce('balo_follow_' . $target_id),
    ]);
});


/**
 * ----------------------------------------------------
 * AJAX: SAVE PROFILE (EMAIL + AVATAR + BIO) – DASHBOARD
 * ----------------------------------------------------
 */
add_action('wp_ajax_balo_save_profile', 'balo_save_profile_cb');

function balo_save_profile_cb() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.'], 403);
    }

    check_ajax_referer('balo_save_profile');

    $user_id = get_current_user_id();
    $user    = get_user_by('ID', $user_id);

    if (!$user) {
        wp_send_json_error(['message' => 'User not found.'], 404);
    }

    $email_error = '';

    // Email update
    if (!empty($_POST['profile_email'])) {
        $email = sanitize_email($_POST['profile_email']);
        if ($email && is_email($email)) {
            $update = wp_update_user([
                'ID'         => $user_id,
                'user_email' => $email,
            ]);
            if (is_wp_error($update)) {
                $email_error = $update->get_error_message();
            }
        } else {
            $email_error = 'Invalid email address.';
        }
    }

    // Bio
    if (isset($_POST['bio'])) {
        $bio = sanitize_textarea_field($_POST['bio']);
        update_user_meta($user_id, 'bio', $bio);
    }

    // Avatar upload
    $avatar_url = '';

    if (!empty($_FILES['profile_image']['name'])) {
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $file = $_FILES['profile_image'];
        $overrides = [
            'test_form' => false,
            'mimes'     => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif'          => 'image/gif',
                'png'          => 'image/png',
                'webp'         => 'image/webp',
            ],
        ];

        $upload = wp_handle_upload($file, $overrides);

        if (!isset($upload['error']) && isset($upload['file'])) {
            $filetype   = wp_check_filetype($upload['file'], null);
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name(basename($upload['file'])),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file'], 0);

            if (!is_wp_error($attach_id)) {
                $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
                wp_update_attachment_metadata($attach_id, $attach_data);

                update_user_meta($user_id, 'avatar_id', $attach_id);
                $avatar_url = wp_get_attachment_image_url($attach_id, 'db-avatar');
                if ($avatar_url) {
                    update_user_meta($user_id, 'profile_image', $avatar_url);
                }
            } else {
                wp_send_json_error(['message' => 'Could not create attachment.']);
            }
        } else {
            wp_send_json_error(['message' => 'Upload error: ' . $upload['error']]);
        }
    }

    // Fallback avatar url if not newly uploaded
    if (!$avatar_url) {
        $avatar_id = (int) get_user_meta($user_id, 'avatar_id', true);
        if ($avatar_id) {
            $avatar_url = wp_get_attachment_image_url($avatar_id, 'db-avatar');
        } else {
            $profile_img = get_user_meta($user_id, 'profile_image', true);
            if ($profile_img) {
                $avatar_url = $profile_img;
            }
        }
    }

    $data = [
        'avatar' => $avatar_url,
    ];

    if (!empty($email_error)) {
        $data['email_warning'] = $email_error;
    }

    wp_send_json_success($data);
}


/**
 * Provide story config (nonce + user) to front-end JS as window.BALO_STORY.
 * MU plugin handles actual storage + AJAX.
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }

    wp_register_script('balo-story-config', false, [], null, true);
    wp_enqueue_script('balo-story-config');

    $user_id = get_current_user_id();

    wp_localize_script(
        'balo-story-config',
        'BALO_STORY',
        [
            'nonce'   => wp_create_nonce('balo_story'),
            'user_id' => $user_id,
            'ajax'    => admin_url('admin-ajax.php'),
        ]
    );
});


/**
 * ----------------------------------------------------
 * STRIPE WEBHOOK (MENTORSHIP/SERVICE SUBSCRIPTIONS)
 * ----------------------------------------------------
 */
add_action('rest_api_init', function () {
    register_rest_route(
        'balo/v1',
        '/stripe/webhook',
        [
            'methods'             => 'POST',
            'callback'            => 'balo_stripe_webhook',
            'permission_callback' => '__return_true',
        ]
    );
});


/**
 * ----------------------------------------------------
 * AJAX: DELETE LISTING (SKILLSWITCH / SERVICE / MENTORSHIP)
 * ----------------------------------------------------
 */
add_action('wp_ajax_balo_delete_listing', 'balo_delete_listing_callback');

function balo_delete_listing_callback() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Login required'], 401);
    }

    check_ajax_referer('balo_delete_listing');

    $kind = isset($_POST['kind']) ? sanitize_key($_POST['kind']) : '';
    $id   = isset($_POST['id'])   ? sanitize_text_field($_POST['id']) : '';

    if (!$id || !in_array($kind, ['skillswitch', 'service', 'mentorship'], true)) {
        wp_send_json_error(['message' => 'Invalid request'], 400);
    }

    $uid = get_current_user_id();

    switch ($kind) {
        case 'skillswitch':
            $meta_key = 'skillswitches';
            break;
        case 'service':
            $meta_key = 'service_listings';
            break;
        case 'mentorship':
            $meta_key = 'mentorship_listings';
            break;
        default:
            wp_send_json_error(['message' => 'Unknown listing type'], 400);
    }

    $items = get_user_meta($uid, $meta_key, true);
    if (!is_array($items) || empty($items)) {
        wp_send_json_error(['message' => 'No listings found'], 404);
    }

    $changed = false;

    foreach ($items as $k => $row) {
        $uniq = isset($row['unique_id']) ? (string) $row['unique_id'] : (string) $k;
        if ($uniq === $id) {
            unset($items[$k]);
            $changed = true;
        }
    }

    if (!$changed) {
        wp_send_json_error(['message' => 'Listing not found'], 404);
    }

    $items = array_values($items);
    update_user_meta($uid, $meta_key, $items);

    wp_send_json_success(['message' => 'Listing deleted']);
}


/**
 * ----------------------------------------------------
 * AJAX: EDIT LISTING (SKILLSWITCH / SERVICE / MENTORSHIP)
 * ----------------------------------------------------
 */
add_action('wp_ajax_balo_edit_listing', 'balo_edit_listing_callback');

function balo_edit_listing_callback() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Login required'], 401);
    }

    if (!check_ajax_referer('balo_edit_listing', '_ajax_nonce', false)) {
        wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $kind = isset($_POST['kind']) ? sanitize_key($_POST['kind']) : '';
    $id   = isset($_POST['id'])   ? sanitize_text_field($_POST['id']) : '';

    if (!$kind || !$id) {
        wp_send_json_error(['message' => 'Invalid request'], 400);
    }

    $uid = get_current_user_id();

    switch ($kind) {
        case 'skillswitch':
            $meta_key = 'skillswitches';
            break;
        case 'service':
            $meta_key = 'service_listings';
            break;
        case 'mentorship':
            $meta_key = 'mentorship_listings';
            break;
        default:
            wp_send_json_error(['message' => 'Unknown listing type'], 400);
    }

    $items = get_user_meta($uid, $meta_key, true);
    if (!is_array($items) || empty($items)) {
        wp_send_json_error(['message' => 'No listings found'], 404);
    }

    $found = false;

    foreach ($items as $k => &$row) {
        $uniq = isset($row['unique_id']) ? (string) $row['unique_id'] : (string) $k;
        if ($uniq !== $id) {
            continue;
        }

        if ($kind === 'skillswitch') {
            $offer   = sanitize_text_field($_POST['offer']   ?? '');
            $want    = sanitize_text_field($_POST['want']    ?? '');
            $details = sanitize_textarea_field($_POST['details'] ?? '');

            if ($offer === '' || $want === '') {
                wp_send_json_error(['message' => 'Offer and want are required.'], 400);
            }

            $row['offer']   = $offer;
            $row['want']    = $want;
            $row['details'] = $details;

        } elseif ($kind === 'service') {
            $business  = sanitize_text_field($_POST['business_name']       ?? '');
            $desc      = sanitize_textarea_field($_POST['service_description'] ?? '');
            $category  = sanitize_text_field($_POST['service_category']    ?? '');
            $areas     = sanitize_text_field($_POST['service_areas']       ?? '');
            $duration  = sanitize_text_field($_POST['service_duration']    ?? '');
            $price_raw = isset($_POST['price_raw']) ? floatval($_POST['price_raw']) : 0;

            if ($business === '' || $price_raw <= 0) {
                wp_send_json_error(['message' => 'Name and price are required.'], 400);
            }

            $row['business_name']       = $business;
            $row['service_description'] = $desc;
            $row['service_category']    = $category;
            $row['service_areas']       = $areas;
            $row['service_duration']    = $duration;
            $row['price_raw']           = $price_raw;
            $row['price']               = '£' . number_format($price_raw, 2);
            $row['service_price']       = $price_raw;

        } elseif ($kind === 'mentorship') {
            $title     = sanitize_text_field($_POST['title']           ?? '');
            $philo     = sanitize_textarea_field($_POST['philosophy']  ?? '');
            $areas     = sanitize_text_field($_POST['areas_of_expertise'] ?? '');
            $duration  = sanitize_text_field($_POST['course_duration'] ?? '');
            $price_raw = isset($_POST['price_raw']) ? floatval($_POST['price_raw']) : 0;

            if ($title === '' || $price_raw <= 0) {
                wp_send_json_error(['message' => 'Title and price are required.'], 400);
            }

            $row['title']              = $title;
            $row['philosophy']         = $philo;
            $row['areas_of_expertise'] = $areas;
            $row['course_duration']    = $duration;
            $row['price_raw']          = $price_raw;
            $row['price']              = '£' . number_format($price_raw, 2);
        }

        $found = true;
        break;
    }
    unset($row);

    if (!$found) {
        wp_send_json_error(['message' => 'Listing not found'], 404);
    }

    update_user_meta($uid, $meta_key, $items);

    wp_send_json_success(['message' => 'Listing updated']);
}


/**
 * ----------------------------------------------------
 * AJAX: GIVE MERIT POINTS
 * ----------------------------------------------------
 */
add_action('wp_ajax_balo_give_merit', function () {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Login required'], 401);
    }

    $viewer_id = get_current_user_id();
    $target_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    $nonce = '';
    if (!empty($_POST['_ajax_nonce'])) {
        $nonce = sanitize_text_field($_POST['_ajax_nonce']);
    } elseif (!empty($_POST['nonce'])) {
        $nonce = sanitize_text_field($_POST['nonce']);
    }

    if (!$target_id || $target_id === $viewer_id) {
        wp_send_json_error(['message' => 'Invalid user.'], 400);
    }

    if (!wp_verify_nonce($nonce, 'balo_give_merit_' . $target_id)) {
        wp_send_json_error(['message' => 'Security check failed.'], 403);
    }

    $current = get_user_meta($target_id, 'merit_points', true);
    if ($current === '' || $current === null) {
        $current = 0;
    }
    $current = (int) $current + 1;

    update_user_meta($target_id, 'merit_points', $current);

    wp_send_json_success([
        'message' => 'Merit given.',
        'merits'  => $current,
    ]);
});


/**
 * ===============================
 * Day / Night toggle button (front-end)
 * ===============================
 * Movable FAB with saved position
 */
add_action('wp_footer', function () {
    if (is_admin()) return; ?>
    <button
        type="button"
        class="balo-theme-toggle"
        aria-label="Toggle day or night mode"
    >
        <span class="balo-theme-toggle__icon" aria-hidden="true">🌙</span>
    </button>

    <script>
    (function () {
      const storageKey = 'baloThemeTogglePos';
      const btn = document.querySelector('.balo-theme-toggle');
      if (!btn) return;

      try {
        const saved = JSON.parse(localStorage.getItem(storageKey));
        if (saved && typeof saved.x === 'number' && typeof saved.y === 'number') {
          btn.style.position = 'fixed';
          btn.style.left = saved.x + 'px';
          btn.style.top  = saved.y + 'px';
          btn.style.right = 'auto';
          btn.style.bottom = 'auto';
        }
      } catch (e) {}

      let dragging = false;
      let offsetX = 0;
      let offsetY = 0;

      btn.__dragged = false;
      btn.addEventListener('click', function (e) {
        if (btn.__dragged) {
          btn.__dragged = false;
          e.stopImmediatePropagation();
          e.preventDefault();
        }
      }, true);

      function startDrag(e) {
        const point = e.touches ? e.touches[0] : e;
        const rect = btn.getBoundingClientRect();

        dragging = true;
        btn.__dragged = false;
        offsetX = point.clientX - rect.left;
        offsetY = point.clientY - rect.top;

        btn.style.position = 'fixed';
        btn.style.transition = 'none';

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', endDrag);
      }

      function onMove(e) {
        if (!dragging) return;
        const point = e.touches ? e.touches[0] : e;
        const x = point.clientX - offsetX;
        const y = point.clientY - offsetY;

        btn.__dragged = true;

        btn.style.left = x + 'px';
        btn.style.top  = y + 'px';
        btn.style.right  = 'auto';
        btn.style.bottom = 'auto';

        if (e.cancelable) e.preventDefault();
      }

      function endDrag() {
        if (!dragging) return;
        dragging = false;
        btn.style.transition = '';

        const x = parseFloat(btn.style.left) || 0;
        const y = parseFloat(btn.style.top)  || 0;
        try {
          localStorage.setItem(storageKey, JSON.stringify({ x: x, y: y }));
        } catch (e) {}

        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', endDrag);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', endDrag);
      }

      btn.addEventListener('mousedown', startDrag);
      btn.addEventListener('touchstart', startDrag, { passive: true });
    })();
    </script>
<?php
}, 20);

/**
 * ===============================
 * Day / Night toggle logic
 * ===============================
 */
add_action('wp_footer', function () {
    if (is_admin()) return; ?>
    <script>
    (function () {
      const storageKey = 'baloTheme';
      const root = document.documentElement;
      const body = document.body;
      const targets = [root, body].filter(Boolean);

      const btn  = document.querySelector('.balo-theme-toggle');
      const icon = btn ? btn.querySelector('.balo-theme-toggle__icon') : null;

      function applyModeClasses(mode) {
        targets.forEach(function (el) {
          el.classList.remove('balo-day', 'balo-night');
          if (mode === 'night') {
            el.classList.add('balo-night');
          } else {
            el.classList.add('balo-day');
          }
        });
      }

      function setMode(mode) {
        applyModeClasses(mode);
        if (icon) {
          icon.textContent = (mode === 'night') ? '🌙' : '☀️';
        }
        localStorage.setItem(storageKey, mode);
      }

      const saved = localStorage.getItem(storageKey);
      if (saved === 'night' || saved === 'day') {
        applyModeClasses(saved);
      }

      if (!saved) {
        const isNight = targets.some(function (el) {
          return el.classList.contains('balo-night');
        });
        const current = isNight ? 'night' : 'day';
        setMode(current);
      } else if (icon) {
        icon.textContent = (saved === 'night') ? '🌙' : '☀️';
      }

      if (!btn) return;

      btn.addEventListener('click', function () {
        const isNight = targets.some(function (el) {
          return el.classList.contains('balo-night');
        });
        const next = isNight ? 'day' : 'night';
        setMode(next);
      });
    })();
    </script>
<?php
}, 30);


/**
 * ============================================
 * Balo Email + Notifications Helpers
 * ============================================
 */
if (!function_exists('balo_build_email_html')) {
    function balo_build_email_html($heading, $message_html) {
        $site_name    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $site_url     = home_url('/');
        $year         = date_i18n('Y');
        $heading_safe = esc_html($heading);

        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id) {
            $logo_url = wp_get_attachment_image_url($logo_id, 'full');
        } else {
            $logo_url = get_theme_file_uri('assets/images/balo-logo.jpg');
        }
        if (!$logo_url) {
            $logo_url = $site_url;
        }
        $logo_url = esc_url($logo_url);

        $body = wpautop($message_html);

        ob_start(); ?>
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="UTF-8">
          <title><?php echo $heading_safe; ?></title>
        </head>
        <body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
          <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f6;padding:24px 0;">
            <tr>
              <td align="center">
                <table width="600" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.12);">
                  <tr>
                    <td align="center" style="padding:24px 24px 8px 24px;background:linear-gradient(135deg,#020617,#0f172a);">
                      <a href="<?php echo esc_url($site_url); ?>" style="text-decoration:none;">
                        <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr($site_name); ?>" width="80" height="80" style="border-radius:999px;display:block;margin:0 auto 10px auto;">
                        <div style="color:#e5e7eb;font-size:14px;letter-spacing:0.06em;text-transform:uppercase;">
                          <?php echo esc_html($site_name); ?>
                        </div>
                      </a>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:24px 24px 8px 24px;">
                      <h1 style="margin:0 0 12px 0;font-size:20px;line-height:1.4;color:#0f172a;">
                        <?php echo $heading_safe; ?>
                      </h1>
                      <div style="font-size:15px;line-height:1.7;color:#111827;">
                        <?php echo $body; ?>
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:16px 24px 24px 24px;">
                      <div style="font-size:12px;line-height:1.6;color:#6b7280;">
                        If you have any questions, you can reply to this email or contact us via the support section on our website.
                      </div>
                      <div style="font-size:12px;color:#9ca3af;margin-top:8px;">
                        &copy; <?php echo esc_html($year); ?> <?php echo esc_html($site_name); ?> &mdash;
                        <a href="<?php echo esc_url($site_url); ?>" style="color:#6b7280;text-decoration:none;"><?php echo esc_html(parse_url($site_url, PHP_URL_HOST)); ?></a>
                      </div>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('balo_email_headers')) {
    function balo_email_headers() {
        $from_name  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $from_email = get_option('admin_email');
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];
    }
}

/**
 * Add a notification for a user.
 */
if (!function_exists('balo_add_notification')) {
    function balo_add_notification($user_id, $type, $message, $meta = []) {
        $user_id = (int) $user_id;
        if (!$user_id || !$message) {
            return;
        }

        $notifications = get_user_meta($user_id, 'balo_notifications', true);
        if (!is_array($notifications)) {
            $notifications = [];
        }

        $notifications[] = [
            'type'    => sanitize_key($type),
            'message' => wp_kses_post($message),
            'meta'    => is_array($meta) ? $meta : [],
            'time'    => time(),
            'read'    => false,
        ];

        update_user_meta($user_id, 'balo_notifications', $notifications);
    }
}

/**
 * Mark all notifications for a user as read.
 */
if (!function_exists('balo_mark_notifications_read_for_user')) {
    function balo_mark_notifications_read_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) return;

        $notifications = get_user_meta($user_id, 'balo_notifications', true);
        if (!is_array($notifications) || empty($notifications)) {
            return;
        }

        $changed = false;
        foreach ($notifications as &$note) {
            if (empty($note['read'])) {
                $note['read'] = true;
                $changed = true;
            }
        }
        unset($note);

        if ($changed) {
            update_user_meta($user_id, 'balo_notifications', $notifications);
        }
    }
}

/**
 * Send booking confirmation emails to provider + student.
 */
if (!function_exists('balo_send_booking_emails')) {
    function balo_send_booking_emails($args) {
        $provider_id = (int) ($args['provider_id'] ?? 0);
        $student_id  = (int) ($args['student_id'] ?? 0);
        $booking_id  = (string) ($args['booking_id'] ?? '');
        $kind        = (string) ($args['type_label'] ?? 'booking');
        $amount      = (float) ($args['amount'] ?? 0);
        $currency    = strtoupper($args['currency'] ?? 'GBP');
        $gateway     = ucfirst($args['gateway'] ?? 'Online');

        $provider = $provider_id ? get_userdata($provider_id) : null;
        $student  = $student_id  ? get_userdata($student_id)  : null;
        $headers  = balo_email_headers();
        // Fire a booking-confirmed event so the control hub (and anything else) can log it
        do_action('balo_booking_confirmed', $args);

        if ($provider && $provider->user_email) {
            $subject = 'New ' . $kind . ' on Balo';
            $msg = sprintf(
                'Hi %1$s,<br><br>%2$s has just booked your %3$s on Balo.<br><br><strong>Booking ID:</strong> %4$s<br><strong>Amount:</strong> %5$s %6$s<br><strong>Gateway:</strong> %7$s<br><br>You can review this booking from your account.',
                esc_html($provider->display_name),
                $student ? esc_html($student->display_name) : 'A member',
                esc_html($kind),
                esc_html($booking_id),
                number_format_i18n($amount, 2),
                esc_html($currency),
                esc_html($gateway)
            );
            $html = balo_build_email_html($subject, $msg);
            wp_mail($provider->user_email, $subject, $html, $headers);
        }

        if ($student && $student->user_email) {
            $subject = 'Your ' . $kind . ' is confirmed';
            $msg = sprintf(
                'Hi %1$s,<br><br>Your %2$s has been confirmed.<br><br><strong>Booking ID:</strong> %3$s<br><strong>Amount:</strong> %4$s %5$s<br><strong>Gateway:</strong> %6$s<br><br>If you have any questions, you can message your provider via Balo.',
                esc_html($student->display_name),
                esc_html($kind),
                esc_html($booking_id),
                number_format_i18n($amount, 2),
                esc_html($currency),
                esc_html($gateway)
            );
            $html = balo_build_email_html($subject, $msg);
            wp_mail($student->user_email, $subject, $html, $headers);
        }
    }
}

/**
 * URL to the "My Subscription Status" page.
 * Change 'my-subscription-status' if your slug is different.
 */
function balo_subscription_status_url() {
    // Always point to the active status page
    return site_url('/active/');
}
/**
 * Enqueue Forgot Password assets
 */
add_action('wp_enqueue_scripts', function () {
    if (is_page_template('page-forgot-password.php') || is_page('lostpassword')) {
        wp_enqueue_style(
            'balo-forgot-css',
            get_stylesheet_directory_uri() . '/assets/css/forgot-password.css',
            [],
            filemtime(get_stylesheet_directory() . '/assets/css/forgot-password.css')
        );

        wp_enqueue_script(
            'balo-forgot-js',
            get_stylesheet_directory_uri() . '/assets/js/forgot-password.js',
            ['jquery'],
            filemtime(get_stylesheet_directory() . '/assets/js/forgot-password.js'),
            true
        );
    }
});
/**
 * Allow a logged-in user to permanently delete their own account.
 */
add_action('wp_ajax_balo_delete_my_account', 'balo_delete_my_account_handler');

function balo_delete_my_account_handler() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in to delete your account.'], 403);
    }

    $uid   = get_current_user_id();
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

    if (!$nonce || !wp_verify_nonce($nonce, 'balo_delete_account_' . $uid)) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh the page and try again.'], 403);
    }

    // Optional: cancel Stripe/PayPal plans if you have helpers:
    if (function_exists('balo_cancel_all_plans_for_user')) {
        try {
            balo_cancel_all_plans_for_user($uid);
        } catch (Throwable $e) {
            // silently ignore – we still continue with deletion
        }
    }

    $user = get_userdata($uid);
    $email = $user ? $user->user_email : '';

    // Give the user temporary permission to delete themselves
    $grant_self_delete = function ($allcaps, $caps, $args, $user_obj) use ($uid) {
        // args[0] = cap name, args[1] = user ID
        if (!empty($args[0]) && $args[0] === 'delete_user' && !empty($args[1]) && (int) $args[1] === $uid) {
            $allcaps['delete_user'] = true;
        }
        return $allcaps;
    };
    add_filter('user_has_cap', $grant_self_delete, 10, 4);

    require_once ABSPATH . 'wp-admin/includes/user.php';

    $deleted = wp_delete_user($uid); // this removes user + their usermeta

    // Remove our temporary capability filter
    remove_filter('user_has_cap', $grant_self_delete, 10);

    if (!$deleted) {
        wp_send_json_error(['message' => 'We could not delete your account. Please contact support.'], 500);
    }

    // Log the user out and clear cookies
    wp_logout();

    // Optionally: log to error_log for admin audit
    if ($email) {
        error_log('[Balo] User account deleted: ID ' . $uid . ' (' . $email . ')');
    }

    wp_send_json_success([
        'message'  => 'Your account has been deleted. We're sad to see you go 💜',
        'redirect' => home_url('/'),
    ]);
}

/**
 * Uwezo Privacy Policy Shortcode
 * Usage: [uwezo_privacy_policy]
 */
function balo_uwezo_privacy_policy_shortcode($atts) {
    // Parse shortcode attributes
    $atts = shortcode_atts([
        'show_logo' => 'yes',
        'show_header' => 'yes',
    ], $atts, 'uwezo_privacy_policy');

    // Start output buffering
    ob_start();
    ?>

    <?php if ($atts['show_header'] === 'yes') : ?>
    <header class="privacy-hero" aria-labelledby="privacy-title">
        <?php if ($atts['show_logo'] === 'yes') : ?>
        <a href="<?php echo esc_url(home_url('/')); ?>" class="site-logo" aria-label="Uwezo">
            <div class="aurora-logo">
                <img
                    src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/balo-logo.jpg' ); ?>"
                    alt="Uwezo"
                    width="160" height="160"
                    fetchpriority="high" decoding="async"
                    class="balo-logo"
                >
            </div>
        </a>
        <?php endif; ?>

        <div class="shimmer" aria-hidden="true"></div>
        <h1 id="privacy-title" class="privacy-title">Uwezo Privacy Policy</h1>
        <p class="privacy-sub">
            Your privacy matters to us. This policy explains how Uwezo collects, uses, and protects your data.
        </p>
    </header>
    <?php endif; ?>

    <main class="privacy-main" role="main">
        <article class="privacy-card">
            <section class="pv-section">
                <h2>About Uwezo</h2>
                <p><strong>Uwezo</strong> is a platform designed to empower users with skills, knowledge, and opportunities. We are committed to protecting your privacy and ensuring your data is handled responsibly.</p>
            </section>

            <section class="pv-section">
                <h2>Information We Collect</h2>
                <p>Uwezo may collect the following types of information:</p>
                <ul class="pv-list">
                    <li><strong>Account Information:</strong> Name, email address, and profile details you provide during registration.</li>
                    <li><strong>Usage Data:</strong> Information about how you interact with the app, such as features accessed and time spent.</li>
                    <li><strong>Device Information:</strong> Device type, operating system, and unique device identifiers.</li>
                    <li><strong>Location Data:</strong> Approximate location based on IP address (if permitted).</li>
                </ul>
            </section>

            <section class="pv-section">
                <h2>How We Use Your Data</h2>
                <p>We use collected data to:</p>
                <ul class="pv-list">
                    <li>Provide and improve Uwezo's features and functionality.</li>
                    <li>Personalize your experience and suggest relevant content.</li>
                    <li>Communicate important updates, notifications, and promotional content (with your consent).</li>
                    <li>Analyze usage patterns to enhance performance and user experience.</li>
                    <li>Ensure security and prevent fraudulent activity.</li>
                </ul>
            </section>

            <section class="pv-section">
                <h2>Data Sharing</h2>
                <p>Uwezo does not sell your personal information. We may share data only in the following circumstances:</p>
                <ul class="pv-list">
                    <li><strong>Service Providers:</strong> Third-party services that help us operate the app (e.g., hosting, analytics).</li>
                    <li><strong>Legal Requirements:</strong> When required by law or to protect our rights and users.</li>
                    <li><strong>Business Transfers:</strong> In the event of a merger, acquisition, or sale of assets.</li>
                </ul>
            </section>

            <section class="pv-section">
                <h2>Cookies &amp; Tracking Technologies</h2>
                <p>Uwezo uses cookies and similar technologies to enhance your experience. These help us remember your preferences, analyze traffic, and provide personalized content. You can manage cookie preferences in your device settings.</p>
            </section>

            <section class="pv-section">
                <h2>Data Security</h2>
                <p>We implement industry-standard security measures to protect your data, including encryption, secure servers, and access controls. However, no method of transmission over the internet is 100% secure.</p>
            </section>

            <section class="pv-section">
                <h2>AI &amp; Machine Learning</h2>
                <p>Uwezo may use anonymized, aggregated data to improve AI-driven features such as recommendations and content suggestions. We do <strong>not</strong> use personally identifiable information for AI training without your explicit consent.</p>
            </section>

            <section class="pv-section">
                <h2>Your Rights &amp; Choices</h2>
                <p>You have the right to:</p>
                <ul class="pv-list">
                    <li>Access and review the personal data we hold about you.</li>
                    <li>Request corrections to inaccurate or incomplete data.</li>
                    <li>Request deletion of your data (subject to legal obligations).</li>
                    <li>Opt out of promotional communications at any time.</li>
                    <li>Withdraw consent for data processing where applicable.</li>
                </ul>
                <p>To exercise these rights, contact us at
                    <a href="mailto:baloservices@proton.me">baloservices@proton.me</a>.
                </p>
                <p class="pv-note">
                    We aim to respond within <strong>2–3 business days</strong>. Please note we are closed on weekends.
                </p>
            </section>

            <section class="pv-section">
                <h2>Children's Privacy</h2>
                <p>Uwezo is not intended for users under the age of 13. We do not knowingly collect personal information from children. If we become aware of such collection, we will take steps to delete the information.</p>
            </section>

            <section class="pv-section">
                <h2>Changes to This Policy</h2>
                <p>We may update this privacy policy from time to time. Changes will be posted on this page with an updated effective date. We encourage you to review this policy periodically.</p>
            </section>

            <section class="pv-section">
                <h2>Contact Us</h2>
                <p>
                    If you have questions, concerns, or requests regarding this privacy policy or your personal data, please contact us at:
                </p>
                <p>
                    <strong>Email:</strong> <a href="mailto:baloservices@proton.me">baloservices@proton.me</a>
                </p>
                <p class="pv-note">
                    This is our official email for privacy and data-related inquiries.
                </p>
            </section>

            <section class="pv-section">
                <h2>Summary</h2>
                <ul class="pv-list">
                    <li>We collect minimal data necessary to provide Uwezo's services.</li>
                    <li>Your data is stored securely and never sold to third parties.</li>
                    <li>Cookies enhance functionality and can be managed in your settings.</li>
                    <li>Anonymized data may improve AI features, but never includes personal details.</li>
                    <li>You can access, correct, or delete your data at any time.</li>
                    <li>Contact us at <a href="mailto:baloservices@proton.me">baloservices@proton.me</a> for any privacy concerns.</li>
                </ul>
                <p class="pv-note">Thank you for trusting Uwezo. We're committed to protecting your privacy every step of the way.</p>
            </section>
        </article>
    </main>

    <?php
    // Return the buffered content
    return ob_get_clean();
}
add_shortcode('uwezo_privacy_policy', 'balo_uwezo_privacy_policy_shortcode');
