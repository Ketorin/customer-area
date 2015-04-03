<?php
/*  Copyright 2013 MarvinLabs (contact@marvinlabs.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/

require_once(CUAR_INCLUDES_DIR . '/core-classes/addon.class.php');

if ( !class_exists('CUAR_LogAddOn')) :

    /**
     * Add-on to provide all the stuff required to set an owner on a post type and include that post type in the
     * customer area.
     *
     * @author Vincent Prat @ MarvinLabs
     */
    class CUAR_LogAddOn extends CUAR_AddOn
    {
        public static $TYPE_CONTENT_VIEWED = 'cuar-content-viewed';
        public static $TYPE_FILE_DOWNLOADED = 'cuar-file-download';
        public static $TYPE_OWNER_CHANGED = 'cuar-owner-changed';
        public static $META_USER_ID = 'user_id';
        public static $META_IP = 'ip';
        public static $META_PREVIOUS_OWNER = 'previous_owner';
        public static $META_CURRENT_OWNER = 'current_owner';

        /** @var CUAR_Logger The logger object */
        private $logger = null;

        public function __construct()
        {
            parent::__construct('log', '6.0.0');
        }

        public function get_addon_name()
        {
            return __('Log', 'cuar');
        }

        public function run_addon($plugin)
        {
            $this->logger = $plugin->get_logger();

            // Init the admin interface if needed
            if (is_admin())
            {
                // Menu
                add_action('cuar/core/admin/main-menu-pages', array(&$this, 'add_menu_items'), 99);
                add_action('cuar/core/admin/adminbar-menu-items', array(&$this, 'add_adminbar_menu_items'), 100);

                // Log table handling
                add_filter('cuar/core/log/table-displayable-meta', array(&$this, 'get_table_displayable_meta'), 10, 1);
                add_filter('cuar/core/log/table-meta-pill-descriptor', array(&$this, 'get_table_meta_pill'), 10, 3);
                // Settings
                add_action('cuar/core/settings/print-settings?tab=cuar_core', array(&$this, 'print_core_settings'), 20,
                    2);
                add_filter('cuar/core/settings/validate-settings?tab=cuar_core', array(&$this, 'validate_core_options'),
                    20, 3);
            }
            else
            {
            }

            // Add some event types by default
            add_filter('cuar/core/log/event-types', array(&$this, 'add_default_event_types'));

            // Owner changed
            add_action('cuar/core/ownership/after-save-owner', array(&$this, 'log_owner_updated'), 10, 4);

            // Content viewed
            add_action('cuar/core/ownership/protect-single-post/on-access-granted',
                array(&$this, 'log_content_viewed'));

            // File downloaded
            add_action('cuar/private-content/files/on-download', array(&$this, 'log_file_downloaded'), 10, 3);
            add_action('cuar/private-content/files/on-view', array(&$this, 'log_file_downloaded'), 10, 3);
        }

        /*------- ADMIN PAGE -----------------------------------------------------------------------------------------*/

        private static $LOG_PAGE_SLUG = "cuar_logs";

        /**
         * Add the menu item
         */
        public function add_menu_items($submenus)
        {
            $separator = '<span class="cuar-menu-divider"></span>';

            $submenus[] = array(
                'page_title' => __('WP Customer Area - Logs', 'cuar'),
                'title'      => $separator . __('Logs', 'cuar'),
                'slug'       => self::$LOG_PAGE_SLUG,
                'function'   => array(&$this, 'print_logs_page'),
                'capability' => 'manage_options'
            );

            return $submenus;
        }

        /**
         * Add the menu item
         */
        public function add_adminbar_menu_items($submenus)
        {
            if (current_user_can('manage_options'))
            {
                $submenus[] = array(
                    'parent' => 'customer-area',
                    'id'     => 'customer-area-logs',
                    'title'  => __('Logs', 'cuar'),
                    'href'   => admin_url('admin.php?page=' . self::$LOG_PAGE_SLUG)
                );
            }

            return $submenus;
        }

        /**
         * Display the main logs page
         */
        public function print_logs_page()
        {
            include($this->plugin->get_template_file_path(
                CUAR_INCLUDES_DIR . '/core-addons/log',
                'logs-page.template.php',
                'templates'));
        }

        /*------- LOGGING HANDLERS -----------------------------------------------------------------------------------*/

        /**
         * Add the event types we are currently supporting to the main array
         *
         * @param array $default_types the currently available types
         *
         * @return array
         */
        public function add_default_event_types($default_types)
        {
            return array_merge($default_types, array(
                self::$TYPE_CONTENT_VIEWED  => __('Content viewed', 'cuar'),
                self::$TYPE_FILE_DOWNLOADED => __('File downloaded', 'cuar'),
                self::$TYPE_OWNER_CHANGED   => __('Owner changed', 'cuar')
            ));
        }

        /**
         * Log a view for private content
         *
         * @param $post
         */
        public function log_content_viewed($post)
        {
            $should_log_event = true;
            $log_only_once = $this->only_log_first_view();
            if ($log_only_once)
            {
                $count = $this->logger->count_events($post->ID, self::$TYPE_CONTENT_VIEWED,
                    array(
                        array(
                            'key'     => self::$META_USER_ID,
                            'value'   => get_current_user_id(),
                            'compare' => '='
                        )
                    ));
                if ($count >= 1)
                {
                    $should_log_event = false;
                }
            }

            if ($should_log_event)
            {
                $this->logger->log_event(self::$TYPE_CONTENT_VIEWED,
                    $post->ID,
                    $post->post_type,
                    array(
                        self::$META_USER_ID => get_current_user_id(),
                        self::$META_IP      => $_SERVER['REMOTE_ADDR']
                    ));
            }
        }

        /**
         * Log a download for private file
         *
         * @param $post_id
         * @param $current_user_id
         * @param $pf_addon
         */
        public function log_file_downloaded($post_id, $current_user_id, $pf_addon)
        {
            $should_log_event = true;
            $log_only_once = $this->only_log_first_download();
            if ($log_only_once)
            {
                $count = $this->logger->count_events($post_id, self::$TYPE_FILE_DOWNLOADED,
                    array(
                        array(
                            'key'     => self::$META_USER_ID,
                            'value'   => get_current_user_id(),
                            'compare' => '='
                        )
                    ));
                if ($count >= 1)
                {
                    $should_log_event = false;
                }
            }

            if ($should_log_event)
            {
                $this->logger->log_event(self::$TYPE_FILE_DOWNLOADED,
                    $post_id,
                    get_post_type($post_id),
                    array(
                        self::$META_USER_ID => get_current_user_id(),
                        self::$META_IP      => $_SERVER['REMOTE_ADDR']
                    ));
            }
        }

        /**
         * Log an event when the post owner changes
         *
         * @param $post_id
         * @param $new_owner
         * @param $previous_owner
         */
        public function log_owner_updated($post_id, $post, $new_owner, $previous_owner)
        {
            // unhook this function so it doesn't loop infinitely
            remove_action('cuar/core/ownership/after-save-owner', array(&$this, 'log_owner_updated'), 10, 4);

            $this->logger->log_event(self::$TYPE_OWNER_CHANGED,
                $post_id,
                get_post_type($post_id),
                array(
                    self::$META_USER_ID        => get_current_user_id(),
                    self::$META_IP             => $_SERVER['REMOTE_ADDR'],
                    self::$META_PREVIOUS_OWNER => $previous_owner,
                    self::$META_CURRENT_OWNER  => $new_owner
                ));

            // re-hook this function
            add_action('cuar/core/ownership/after-save-owner', array(&$this, 'log_owner_updated'), 10, 4);
        }

        /*------- LOG VIEWER -----------------------------------------------------------------------------------------*/

        public function get_table_displayable_meta($meta)
        {
            return array_merge($meta, array(
                self::$META_USER_ID,
                self::$META_IP,
                self::$META_PREVIOUS_OWNER,
                self::$META_CURRENT_OWNER
            ));
        }

        public function get_table_meta_pill($pill, $meta, $item)
        {
            switch ($meta)
            {
                case self::$META_IP:
                    $pill['title'] = __('IP address', 'cuar');
                    $pill['value'] = $item->$meta;
                    break;

                case self::$META_PREVIOUS_OWNER:
                    $o = $item->$meta;
                    $pill['title'] = __('Previous owner: ', 'cuar') . $o['display_name'];
                    $pill['value'] = __('From: ', 'cuar') . substr($o['display_name'], 0, 35);
                    break;

                case self::$META_CURRENT_OWNER:
                    $o = $item->$meta;
                    $pill['title'] = __('Current owner: ', 'cuar') . $o['display_name'];
                    $pill['value'] = __('To: ', 'cuar') . substr($o['display_name'], 0, 35);
                    break;
            }
            return $pill;
        }

        /*------- SETTINGS -------------------------------------------------------------------------------------------*/


        public function only_log_first_view()
        {
            return $this->plugin->get_option(self::$OPTION_LOG_ONLY_FIRST_VIEW) == true;
        }

        public function only_log_first_download()
        {
            return $this->plugin->get_option(self::$OPTION_LOG_ONLY_FIRST_DOWNLOAD) == true;
        }

        /**
         * Set the default values for the options
         *
         * @param array $defaults
         *
         * @return array
         */
        public function set_default_options($defaults)
        {
            $defaults = parent::set_default_options($defaults);
            $defaults[self::$OPTION_LOG_ONLY_FIRST_VIEW] = true;
            $defaults[self::$OPTION_LOG_ONLY_FIRST_DOWNLOAD] = true;

            return $defaults;
        }

        /**
         * Add our fields to the settings page
         *
         * @param CUAR_Settings $cuar_settings The settings class
         */
        public function print_core_settings($cuar_settings, $options_group)
        {
            add_settings_section(
                'cuar_logs',
                __('Logs', 'cuar'),
                array(&$cuar_settings, 'print_empty_section_info'),
                CUAR_Settings::$OPTIONS_PAGE_SLUG
            );

            add_settings_field(
                self::$OPTION_LOG_ONLY_FIRST_VIEW,
                __('Content viewed', 'cuar'),
                array(&$cuar_settings, 'print_input_field'),
                CUAR_Settings::$OPTIONS_PAGE_SLUG,
                'cuar_logs',
                array(
                    'option_id' => self::$OPTION_LOG_ONLY_FIRST_VIEW,
                    'type'      => 'checkbox',
                    'after'     => __('Only log the first time a private content is viewed by a user.', 'cuar')
                        . '<p class="description">'
                        . __('If you check this box, WP Customer Area will only generate a log event the first time a user is viewing private content. There will be a single event per user and per content. Else, each time a user is viewing a private content, an event will be generated.',
                            'cuar')
                        . '</p>'
                )
            );

            add_settings_field(
                self::$OPTION_LOG_ONLY_FIRST_DOWNLOAD,
                __('File downloaded', 'cuar'),
                array(&$cuar_settings, 'print_input_field'),
                CUAR_Settings::$OPTIONS_PAGE_SLUG,
                'cuar_logs',
                array(
                    'option_id' => self::$OPTION_LOG_ONLY_FIRST_DOWNLOAD,
                    'type'      => 'checkbox',
                    'after'     => __('Only log the first time a private file is downloaded by a user.', 'cuar')
                        . '<p class="description">'
                        . __('If you check this box, WP Customer Area will only generate a log event the first time a user is downloading a private file. There will be a single event per user and per file. Else, each time a user is downloading a private file, an event will be generated.',
                            'cuar')
                        . '</p>'
                )
            );
        }

        /**
         * Validate our options
         *
         * @param array         $validated
         * @param CUAR_Settings $cuar_settings
         * @param array         $input
         *
         * @return array
         */
        public function validate_core_options($validated, $cuar_settings, $input)
        {
            $cuar_settings->validate_boolean($input, $validated, self::$OPTION_LOG_ONLY_FIRST_VIEW);
            $cuar_settings->validate_boolean($input, $validated, self::$OPTION_LOG_ONLY_FIRST_DOWNLOAD);

            return $validated;
        }

        private static $OPTION_LOG_ONLY_FIRST_VIEW = 'cuar_log_view_first_only';
        private static $OPTION_LOG_ONLY_FIRST_DOWNLOAD = 'cuar_log_download_first_only';

    }

    // Make sure the addon is loaded
    new CUAR_LogAddOn();

endif; // if (!class_exists('CUAR_LogAddOn'))
