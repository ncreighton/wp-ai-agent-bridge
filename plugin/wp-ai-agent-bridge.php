<?php
/**
 * Plugin Name: WP AI Agent Bridge
 * Description: REST bridge for an external AI (Claude / OpenAI / Gemini) to fully set up and configure a new WordPress site: basics, menus, pages, categories, SEO, and plugin options.
 * Version: 0.2
 * Author: You
 */

if ( ! defined('ABSPATH') ) exit;

class WPAIAgentBridge {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_init', [$this, 'maybe_add_default_token']);
    }

    /**
     * Create a default token once so you can see it in the DB.
     */
    public function maybe_add_default_token() {
        if ( ! get_option('wpai_agent_token') ) {
            // generate a random token once
            $token = wp_generate_password(32, false, false);
            update_option('wpai_agent_token', $token);
        }
    }

    public function register_routes() {

        // 0. Info / site introspection
        register_rest_route('wpai/v1', '/site-info', [
            'methods'  => 'GET',
            'callback' => [$this, 'site_info'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 1. Basic setup (permalinks, timezone, site title/desc)
        register_rest_route('wpai/v1', '/basic-setup', [
            'methods'  => 'POST',
            'callback' => [$this, 'basic_setup'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 2. Categories bulk create
        register_rest_route('wpai/v1', '/categories', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_categories'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 3. Create / update page (Gutenberg or classic)
        register_rest_route('wpai/v1', '/pages', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_or_update_page'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 4. Menus create / update
        register_rest_route('wpai/v1', '/menus', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_or_update_menu'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 5. Set static homepage
        register_rest_route('wpai/v1', '/set-homepage', [
            'methods'  => 'POST',
            'callback' => [$this, 'set_homepage'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 6. Rank Math setup
        register_rest_route('wpai/v1', '/rankmath-setup', [
            'methods'  => 'POST',
            'callback' => [$this, 'rankmath_setup'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 7. Generic option setter
        register_rest_route('wpai/v1', '/set-option', [
            'methods'  => 'POST',
            'callback' => [$this, 'set_option'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 8. List plugins
        register_rest_route('wpai/v1', '/plugins', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_plugins'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 9. Install & activate plugin (optional)
        register_rest_route('wpai/v1', '/install-plugin', [
            'methods'  => 'POST',
            'callback' => [$this, 'install_plugin'],
            'permission_callback' => [$this, 'auth_check']
        ]);

        // 10. Run a full blueprint
        register_rest_route('wpai/v1', '/run-blueprint', [
            'methods'  => 'POST',
            'callback' => [$this, 'run_blueprint'],
            'permission_callback' => [$this, 'auth_check']
        ]);
    }

    /**
     * Very simple token check.
     * Send header:  x-wpai-token: <token stored in wp options>
     */
    public function auth_check( $request ) {
        $token = $request->get_header('x-wpai-token');
        $saved = get_option('wpai_agent_token', '');
        return ( !empty($saved) && !empty($token) && hash_equals($saved, $token) );
    }

    /**
     * 0. Site info – AI can call this first to see what it's dealing with.
     */
    public function site_info() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $active_plugins = get_option('active_plugins', []);
        $all_plugins = get_plugins();

        return [
            'wp_version'   => get_bloginfo('version'),
            'site_url'     => site_url(),
            'home_url'     => home_url(),
            'blogname'     => get_option('blogname'),
            'blogdescription' => get_option('blogdescription'),
            'timezone'     => get_option('timezone_string'),
            'permalink'    => get_option('permalink_structure'),
            'active_theme' => wp_get_theme()->get('Name'),
            'active_plugins' => array_values($active_plugins),
            'all_plugins'    => $all_plugins,
        ];
    }

    /**
     * 1. Basic setup
     */
    public function basic_setup( $request ) {
        $params = $request->get_json_params();

        // Permalink
        $permalink = !empty($params['permalink']) ? $params['permalink'] : '/%category%/%postname%/';
        update_option('permalink_structure', $permalink);

        // Timezone
        $timezone = !empty($params['timezone']) ? $params['timezone'] : 'America/New_York';
        update_option('timezone_string', $timezone);

        // Site name/desc
        if ( !empty($params['blogname']) ) {
            update_option('blogname', sanitize_text_field($params['blogname']));
        }
        if ( !empty($params['blogdescription']) ) {
            update_option('blogdescription', sanitize_text_field($params['blogdescription']));
        }

        flush_rewrite_rules(false);

        return [
            'success' => true,
            'message' => 'Basic setup completed.'
        ];
    }

    /**
     * 2. Bulk create categories
     * payload: { "categories": [ {"name":"Wicca","slug":"wicca"}, ... ] }
     */
    public function create_categories( $request ) {
        $params = $request->get_json_params();
        $created = [];
        if ( !empty($params['categories']) && is_array($params['categories']) ) {
            foreach ( $params['categories'] as $cat ) {
                $name = sanitize_text_field($cat['name']);
                $slug = !empty($cat['slug']) ? sanitize_title($cat['slug']) : sanitize_title($name);
                $result = wp_insert_term($name, 'category', ['slug' => $slug]);
                if ( ! is_wp_error($result) ) {
                    $created[] = $result;
                }
            }
        }
        return [
            'success' => true,
            'created' => $created
        ];
    }

    /**
     * 3. Create or update page.
     * payload: { "title": "Home", "slug": "home", "content": "<!-- wp:paragraph -->...", "status": "publish" }
     * or send "blocks" => raw block JSON
     */
    public function create_or_update_page( $request ) {
        $params = $request->get_json_params();

        if ( empty($params['title']) ) {
            return new WP_Error('missing_title', 'title is required', ['status' => 400]);
        }

        $title = sanitize_text_field($params['title']);
        $slug  = !empty($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($title);
        $status = !empty($params['status']) ? $params['status'] : 'publish';
        $content = !empty($params['content']) ? $params['content'] : '';

        // check if page exists
        $existing = get_page_by_path($slug);
        $page_data = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => $status,
            'post_type'    => 'page',
            'post_content' => $content
        ];

        if ( $existing ) {
            $page_data['ID'] = $existing->ID;
            $page_id = wp_update_post($page_data, true);
        } else {
            $page_id = wp_insert_post($page_data, true);
        }

        if ( is_wp_error($page_id) ) {
            return $page_id;
        }

        return [
            'success' => true,
            'page_id' => $page_id
        ];
    }

    /**
     * 4. Create / update menu.
     * payload:
     * {
     *   "location": "primary",
     *   "name": "Main Menu",
     *   "items": [
     *     {"title":"Home","url":"/"},
     *     {"title":"Blog","url":"/blog/"},
     *     {"title":"Contact","url":"/contact/"}
     *   ]
     * }
     */
    public function create_or_update_menu( $request ) {
        $params = $request->get_json_params();
        if ( empty($params['name']) ) {
            return new WP_Error('missing_name', 'Menu name required', ['status' => 400]);
        }

        $menu_name = sanitize_text_field($params['name']);
        $menu_id = wp_create_nav_menu($menu_name);

        if ( is_wp_error($menu_id) ) {
            // maybe it exists
            $menu = get_term_by('name', $menu_name, 'nav_menu');
            if ( $menu ) {
                $menu_id = $menu->term_id;
            } else {
                return $menu_id;
            }
        }

        // remove old items
        $old_items = wp_get_nav_menu_items($menu_id);
        if ( ! empty($old_items) ) {
            foreach ( $old_items as $item ) {
                wp_delete_post($item->ID, true);
            }
        }

        if ( !empty($params['items']) && is_array($params['items']) ) {
            foreach ( $params['items'] as $item ) {
                wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title' => sanitize_text_field($item['title']),
                    'menu-item-url'   => esc_url_raw($item['url']),
                    'menu-item-status'=> 'publish'
                ]);
            }
        }

        // assign to location
        if ( !empty($params['location']) ) {
            $locations = get_theme_mod('nav_menu_locations');
            if ( ! is_array($locations) ) $locations = [];
            $locations[ $params['location'] ] = $menu_id;
            set_theme_mod('nav_menu_locations', $locations);
        }

        return [
            'success' => true,
            'menu_id' => $menu_id
        ];
    }

    /**
     * 5. Set homepage to a given page ID or slug
     * payload: { "page_id": 123 } or { "slug": "home" }
     */
    public function set_homepage( $request ) {
        $params = $request->get_json_params();

        $page_id = 0;
        if ( !empty($params['page_id']) ) {
            $page_id = intval($params['page_id']);
        } elseif ( !empty($params['slug']) ) {
            $page = get_page_by_path( sanitize_title($params['slug']) );
            if ( $page ) $page_id = $page->ID;
        }

        if ( ! $page_id ) {
            return new WP_Error('no_page', 'No valid page provided', ['status' => 400]);
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_id);

        return [
            'success' => true,
            'message' => 'Homepage set',
            'page_id' => $page_id
        ];
    }

    /**
     * 6. Rank Math defaults
     */
    public function rankmath_setup( $request ) {
        if ( ! defined('RANK_MATH_VERSION') ) {
            return [
                'success' => false,
                'message' => 'Rank Math not active.'
            ];
        }

        $sitename = get_option('blogname');
        $tagline  = get_option('blogdescription');

        // General
        $general = get_option('rank-math-options-general', []);
        if ( ! is_array($general) ) $general = [];
        $general['breadcrumb'] = 'on';
        $general['separator']  = '»';
        $general['sitename']   = $sitename;
        update_option('rank-math-options-general', $general);

        // Titles
        $titles = get_option('rank-math-options-titles', []);
        if ( ! is_array($titles) ) $titles = [];
        $titles['homepage_title'] = $sitename . ( $tagline ? ' | ' . $tagline : '' );
        $titles['homepage_desc']  = 'Latest content from ' . $sitename . '.';
        $titles['post_title']     = '%title% | ' . $sitename;
        $titles['category_title'] = '%term% | ' . $sitename;
        update_option('rank-math-options-titles', $titles);

        return [
            'success' => true,
            'message' => 'Rank Math configured with defaults.'
        ];
    }

    /**
     * 7. Set any option
     * payload: { "option_name": "litespeed-conf", "option_value": { ... } }
     */
    public function set_option( $request ) {
        $params = $request->get_json_params();
        if ( empty($params['option_name']) ) {
            return new WP_Error('missing_option', 'option_name required', ['status' => 400]);
        }
        $name = sanitize_text_field($params['option_name']);
        $value = $params['option_value'] ?? '';
        update_option($name, $value);
        return [
            'success' => true,
            'message' => "Updated option {$name}"
        ];
    }

    /**
     * 8. List plugins
     */
    public function get_plugins() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all_plugins = get_plugins();
        $active = get_option('active_plugins', []);
        $out = [];
        foreach ( $all_plugins as $path => $data ) {
            $out[] = [
                'path'   => $path,
                'name'   => $data['Name'],
                'active' => in_array($path, $active, true),
            ];
        }
        return $out;
    }

    /**
     * 9. Install & activate plugin from wp.org
     * payload: { "slug": "classic-editor" }
     * NOTE: this depends on FS permissions on the server.
     */
    public function install_plugin( $request ) {
        $params = $request->get_json_params();
        if ( empty($params['slug']) ) {
            return new WP_Error('missing_slug', 'slug required', ['status' => 400]);
        }

        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $api = plugins_api('plugin_information', [
            'slug'   => sanitize_text_field($params['slug']),
            'fields' => ['sections' => false]
        ]);

        if ( is_wp_error($api) ) {
            return $api;
        }

        $upgrader = new Plugin_Upgrader();
        $result = $upgrader->install($api->download_link);

        if ( is_wp_error($result) ) {
            return $result;
        }

        // activate
        $activate = activate_plugin($api->slug . '/' . $api->slug . '.php');

        return [
            'success' => true,
            'message' => 'Plugin installed and attempted activation.',
            'plugin'  => $api->slug
        ];
    }

    /**
     * 10. Run a full blueprint
     * payload:
     * {
     *   "basic": { "blogname":"Smart Home Gear Reviews" },
     *   "categories": [ ... ],
     *   "pages": [ ... ],
     *   "menus": [ ... ],
     *   "homepage": { "slug":"home" },
     *   "plugins_to_configure": ["rank-math"]
     * }
     */
    public function run_blueprint( $request ) {
        $params = $request->get_json_params();
        $report = [];

        // basic
        if ( !empty($params['basic']) ) {
            $this->basic_setup( new WP_REST_Request('POST', '/wpai/v1/basic-setup') );
            $report[] = 'Basic setup run.';
        }

        // categories
        if ( !empty($params['categories']) ) {
            $req = new WP_REST_Request('POST', '/wpai/v1/categories');
            $req->set_body_params(['categories' => $params['categories']]);
            $this->create_categories($req);
            $report[] = 'Categories created.';
        }

        // pages
        if ( !empty($params['pages']) ) {
            foreach ( $params['pages'] as $p ) {
                $req = new WP_REST_Request('POST', '/wpai/v1/pages');
                $req->set_body_params($p);
                $this->create_or_update_page($req);
            }
            $report[] = 'Pages created/updated.';
        }

        // menus
        if ( !empty($params['menus']) ) {
            foreach ( $params['menus'] as $m ) {
                $req = new WP_REST_Request('POST', '/wpai/v1/menus');
                $req->set_body_params($m);
                $this->create_or_update_menu($req);
            }
            $report[] = 'Menus created/updated.';
        }

        // homepage
        if ( !empty($params['homepage']) ) {
            $req = new WP_REST_Request('POST', '/wpai/v1/set-homepage');
            $req->set_body_params($params['homepage']);
            $this->set_homepage($req);
            $report[] = 'Homepage set.';
        }

        // plugin configs
        if ( !empty($params['plugins_to_configure']) && is_array($params['plugins_to_configure']) ) {
            foreach ( $params['plugins_to_configure'] as $pl ) {
                if ( $pl === 'rank-math' ) {
                    $this->rankmath_setup( new WP_REST_Request('POST', '/wpai/v1/rankmath-setup') );
                    $report[] = 'Rank Math configured.';
                }
            }
        }

        return [
            'success' => true,
            'report'  => $report
        ];
    }

}

new WPAIAgentBridge();
