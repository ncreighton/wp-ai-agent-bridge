<?php
/**
 * Plugin Name: WP AI Agent Bridge
 * Description: REST bridge for an external AI (Claude / OpenAI / Gemini) to fully set up and configure a new WordPress site: basics, menus, pages, categories, SEO, and plugin options.
 * Version: 0.2
 * Author: You
 */

if ( ! defined('ABSPATH') ) exit;

class WPAIAgentBridge {

    /**
     * Tracks whether the MCP plugin has been detected during the request lifecycle.
     *
     * @var bool
     */
    private $mcp_detected = false;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_init', [$this, 'maybe_add_default_token']);
        add_action('plugins_loaded', [$this, 'bootstrap_mcp_support']);
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

    /**
     * Initialize optional WordPress MCP support (if the plugin is available).
     */
    public function bootstrap_mcp_support() {
        if ( $this->is_mcp_plugin_loaded() ) {
            $this->mcp_detected = true;
        }

        add_action('mcp_register_functions', [$this, 'register_mcp_functions'], 10, 1);
        add_filter('mcp_register_functions', [$this, 'maybe_append_mcp_functions']);
        add_filter('wordpress_mcp_functions', [$this, 'maybe_append_mcp_functions']);
    }

    /**
     * Register MCP functions using whatever registry structure the MCP plugin provides.
     *
     * @param mixed $registry Registry/context object provided by the MCP plugin.
     */
    public function register_mcp_functions( $registry ) {
        $this->mcp_detected = true;

        foreach ( $this->get_mcp_function_definitions() as $definition ) {
            $this->attempt_registry_registration($registry, $definition);
        }
    }

    /**
     * Append MCP function definitions when the plugin expects a filter that returns an array.
     *
     * @param mixed $functions Existing MCP function definitions.
     *
     * @return mixed
     */
    public function maybe_append_mcp_functions( $functions ) {
        $this->mcp_detected = true;

        if ( ! is_array($functions) ) {
            return $functions;
        }

        $definitions = $this->get_mcp_function_definitions();
        if ( empty($definitions) ) {
            return $functions;
        }

        if ( $this->is_list_array($functions) ) {
            $existing = [];
            foreach ( $functions as $item ) {
                if ( is_array($item) && isset($item['name']) ) {
                    $existing[ $item['name'] ] = true;
                } elseif ( is_object($item) && isset($item->name) ) {
                    $existing[ $item->name ] = true;
                }
            }
            foreach ( $definitions as $definition ) {
                if ( isset($existing[ $definition['name'] ]) ) {
                    continue;
                }
                $functions[] = $definition;
            }
        } else {
            foreach ( $definitions as $definition ) {
                if ( isset($functions[ $definition['name'] ]) ) {
                    continue;
                }
                $functions[ $definition['name'] ] = $definition;
            }
        }

        return $functions;
    }

    /**
     * Determine whether the MCP plugin (or a compatible bridge) appears to be loaded.
     *
     * @return bool
     */
    private function is_mcp_plugin_loaded() {
        if ( $this->mcp_detected ) {
            return true;
        }

        if ( defined('WORDPRESS_MCP_VERSION') ) {
            return true;
        }

        $classes = [
            '\\Automattic\\WordPress\\MCP\\Plugin',
            '\\Automattic\\WordPress\\MCP\\Server',
            '\\Automattic\\WP\\MCP\\Plugin',
            '\\Automattic\\WP\\MCP\\Server',
        ];

        foreach ( $classes as $class ) {
            if ( class_exists($class) ) {
                return true;
            }
        }

        if ( has_action('mcp_register_functions') || has_filter('mcp_register_functions') || has_filter('wordpress_mcp_functions') ) {
            return true;
        }

        return false;
    }

    /**
     * Attempt to register an MCP function definition with an arbitrary registry implementation.
     *
     * @param mixed $registry   Registry/context supplied by the MCP plugin.
     * @param array $definition Function definition array.
     */
    private function attempt_registry_registration( $registry, array $definition ) {
        if ( is_object($registry) ) {
            if ( method_exists($registry, 'register_function') ) {
                try {
                    $registry->register_function(
                        $definition['name'],
                        $definition['callback'],
                        $definition['parameters'],
                        $definition['description'],
                        $definition['returns']
                    );
                    return;
                } catch ( \Throwable $e ) {
                    // fall back to other registration strategies
                }
            }

            if ( method_exists($registry, 'register') ) {
                try {
                    $reflection = new \ReflectionMethod($registry, 'register');
                    $param_count = $reflection->getNumberOfParameters();

                    if ( 1 === $param_count ) {
                        $definition_object = $this->maybe_build_mcp_definition_object($definition);
                        if ( $definition_object ) {
                            $registry->register($definition_object);
                            return;
                        }
                    } elseif ( 2 === $param_count ) {
                        $registry->register($definition['name'], $definition);
                        return;
                    } elseif ( 3 === $param_count ) {
                        $registry->register($definition['name'], $definition['callback'], $definition['parameters']);
                        return;
                    } elseif ( 4 <= $param_count ) {
                        $arguments = [
                            $definition['name'],
                            $definition['callback'],
                            $definition['parameters'],
                            $definition['description'],
                        ];

                        if ( $param_count >= 5 ) {
                            $arguments[] = $definition['returns'];
                        }

                        $registry->register(...$arguments);
                        return;
                    }
                } catch ( \Throwable $e ) {
                    // continue to other strategies
                }
            }

            if ( method_exists($registry, 'add') ) {
                try {
                    $registry->add($definition['name'], $definition);
                    return;
                } catch ( \Throwable $e ) {
                    // ignore
                }
            }

            if ( $registry instanceof \ArrayAccess ) {
                try {
                    $registry[ $definition['name'] ] = $definition;
                    return;
                } catch ( \Throwable $e ) {
                    // ignore
                }
            }
        }

        if ( is_callable($registry) ) {
            try {
                call_user_func($registry, $definition);
                return;
            } catch ( \Throwable $e ) {
                // ignore
            }
        }
    }

    /**
     * Attempt to build an MCP FunctionDefinition object if the class exists.
     *
     * @param array $definition Function definition array.
     *
     * @return object|null
     */
    private function maybe_build_mcp_definition_object( array $definition ) {
        $candidates = [
            '\\Automattic\\WordPress\\MCP\\Types\\FunctionDefinition',
            '\\Automattic\\WP\\MCP\\Types\\FunctionDefinition',
            '\\Automattic\\MCP\\Types\\FunctionDefinition',
        ];

        foreach ( $candidates as $class ) {
            if ( class_exists($class) && method_exists($class, 'from_array') ) {
                try {
                    return $class::from_array([
                        'name'        => $definition['name'],
                        'description' => $definition['description'],
                        'parameters'  => $definition['parameters'],
                        'returns'     => $definition['returns'],
                    ]);
                } catch ( \Throwable $e ) {
                    // ignore and try next candidate
                }
            }
        }

        return null;
    }

    /**
     * Retrieve MCP function definitions for this bridge.
     *
     * @return array
     */
    private function get_mcp_function_definitions() {
        return [
            [
                'name'        => 'wpai.site_info',
                'description' => 'Retrieve WordPress site information via WP AI Agent Bridge.',
                'parameters'  => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Details about the WordPress site configuration.',
                ],
                'callback'    => [$this, 'mcp_site_info'],
            ],
            [
                'name'        => 'wpai.basic_setup',
                'description' => 'Configure core WordPress settings such as permalinks, timezone, and site title.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'permalink'        => ['type' => 'string'],
                        'timezone'         => ['type' => 'string'],
                        'blogname'         => ['type' => 'string'],
                        'blogdescription'  => ['type' => 'string'],
                    ],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the setup operation.',
                ],
                'callback'    => [$this, 'mcp_basic_setup'],
            ],
            [
                'name'        => 'wpai.create_categories',
                'description' => 'Bulk create WordPress categories.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'categories' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'slug' => ['type' => 'string'],
                                ],
                                'required'   => ['name'],
                            ],
                        ],
                    ],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Status of category creation.',
                ],
                'callback'    => [$this, 'mcp_create_categories'],
            ],
            [
                'name'        => 'wpai.create_page',
                'description' => 'Create or update a WordPress page.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'slug'    => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'status'  => ['type' => 'string'],
                    ],
                    'required'   => ['title'],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the page operation.',
                ],
                'callback'    => [$this, 'mcp_create_or_update_page'],
            ],
            [
                'name'        => 'wpai.create_menu',
                'description' => 'Create or update a navigation menu.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'location' => ['type' => 'string'],
                        'name'     => ['type' => 'string'],
                        'items'    => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'url'   => ['type' => 'string'],
                                ],
                                'required'   => ['title', 'url'],
                            ],
                        ],
                    ],
                    'required'   => ['name'],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the menu operation.',
                ],
                'callback'    => [$this, 'mcp_create_or_update_menu'],
            ],
            [
                'name'        => 'wpai.set_homepage',
                'description' => 'Set the static homepage to a specific page.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id' => ['type' => 'integer'],
                        'slug'    => ['type' => 'string'],
                    ],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the homepage assignment.',
                ],
                'callback'    => [$this, 'mcp_set_homepage'],
            ],
            [
                'name'        => 'wpai.rankmath_setup',
                'description' => 'Configure Rank Math SEO defaults when the plugin is active.',
                'parameters'  => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the Rank Math configuration.',
                ],
                'callback'    => [$this, 'mcp_rankmath_setup'],
            ],
            [
                'name'        => 'wpai.set_option',
                'description' => 'Update a WordPress option with a provided value.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'option_name'  => ['type' => 'string'],
                        'option_value' => [],
                    ],
                    'required'   => ['option_name'],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the option update.',
                ],
                'callback'    => [$this, 'mcp_set_option'],
            ],
            [
                'name'        => 'wpai.get_plugins',
                'description' => 'List installed plugins with their activation state.',
                'parameters'  => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'returns'     => [
                    'type'        => 'array',
                    'description' => 'Array of plugins with metadata.',
                    'items'       => ['type' => 'object'],
                ],
                'callback'    => [$this, 'mcp_get_plugins'],
            ],
            [
                'name'        => 'wpai.install_plugin',
                'description' => 'Install and activate a plugin from WordPress.org.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string'],
                    ],
                    'required'   => ['slug'],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Result of the plugin installation.',
                ],
                'callback'    => [$this, 'mcp_install_plugin'],
            ],
            [
                'name'        => 'wpai.run_blueprint',
                'description' => 'Execute a full site blueprint covering basics, categories, pages, menus, and plugin setup.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'basic'                 => ['type' => 'object'],
                        'categories'            => ['type' => 'array'],
                        'pages'                 => ['type' => 'array'],
                        'menus'                 => ['type' => 'array'],
                        'homepage'              => ['type' => 'object'],
                        'plugins_to_configure'  => ['type' => 'array'],
                    ],
                ],
                'returns'     => [
                    'type'        => 'object',
                    'description' => 'Report of actions performed while running the blueprint.',
                ],
                'callback'    => [$this, 'mcp_run_blueprint'],
            ],
        ];
    }

    /**
     * Determine if an array is numerically indexed.
     *
     * @param array $array Array to inspect.
     *
     * @return bool
     */
    private function is_list_array( array $array ) {
        if ( empty($array) ) {
            return true;
        }

        $expected = range(0, count($array) - 1);
        return $expected === array_keys($array);
    }

    /**
     * Normalize incoming MCP arguments.
     *
     * @param mixed $args Arguments provided by the MCP runtime.
     *
     * @return array
     */
    private function normalize_mcp_arguments( $args ) {
        if ( is_array($args) ) {
            return $args;
        }

        if ( is_object($args) ) {
            $encoder = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
            $decoded = json_decode($encoder($args), true);
            if ( is_array($decoded) ) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Helper to build WP_REST_Request instances for MCP callbacks.
     *
     * @param string $method HTTP verb.
     * @param string $route  Route being simulated.
     * @param array  $params Parameters to attach.
     *
     * @return WP_REST_Request
     */
    private function build_rest_request_for_mcp( $method, $route, array $params = [] ) {
        $request = new WP_REST_Request($method, $route);

        if ( strtoupper($method) === 'GET' ) {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
        }

        return $request;
    }

    /**
     * MCP callback wrappers that reuse the existing REST implementations.
     */

    public function mcp_site_info() {
        return $this->site_info();
    }

    public function mcp_basic_setup( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/basic-setup', $params);
        return $this->basic_setup($request);
    }

    public function mcp_create_categories( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/categories', $params);
        return $this->create_categories($request);
    }

    public function mcp_create_or_update_page( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/pages', $params);
        return $this->create_or_update_page($request);
    }

    public function mcp_create_or_update_menu( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/menus', $params);
        return $this->create_or_update_menu($request);
    }

    public function mcp_set_homepage( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/set-homepage', $params);
        return $this->set_homepage($request);
    }

    public function mcp_rankmath_setup() {
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/rankmath-setup', []);
        return $this->rankmath_setup($request);
    }

    public function mcp_set_option( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/set-option', $params);
        return $this->set_option($request);
    }

    public function mcp_get_plugins() {
        return $this->get_plugins();
    }

    public function mcp_install_plugin( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/install-plugin', $params);
        return $this->install_plugin($request);
    }

    public function mcp_run_blueprint( $args = [] ) {
        $params = $this->normalize_mcp_arguments($args);
        $request = $this->build_rest_request_for_mcp('POST', '/wpai/v1/run-blueprint', $params);
        return $this->run_blueprint($request);
    }

}

new WPAIAgentBridge();
