<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

define('WP_LOAD_IMPORTERS', true);

// Force loading of admin dependencies
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if ( file_exists( $class_wp_importer ) ) {
        WP_CLI::log('Incluindo class-wp-importer.php');
        require_once $class_wp_importer;
    }
}


// Load main plugin
if ( ! class_exists( 'RS_CSV_Importer' ) ) {
    require_once __DIR__ . '/rs-csv-importer.php';
}

class RS_CSV_Importer_CLI_Command {

    /**
     * Import posts from CSV file by WPCLI.
     *
     * ## OPTIONS
     *
     * --file=<file>
     * : Absolute path to CSV file.
     *
     * ## EXAMPLES
     *
     *     wp rs-csv-importer import --file=/path/to/file.csv
     */
    public function import( $args, $assoc_args ) {
        $file = $assoc_args['file'] ?? null;

        if ( ! $file || ! file_exists( $file ) ) {
            WP_CLI::error( 'Arquivo CSV não encontrado: ' . $file );
        }

        require_once dirname(__FILE__) . '/class-rs_csv_helper.php';
        require_once dirname(__FILE__) . '/class-rscsv_import_post_helper.php';

        $importer = new RS_CSV_Importer();

        // Setting needed properties
        $importer->file = $file;
        $importer->id = 0; // There is no attachment (upload)

        $result = self::process_posts_cli( $importer );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        } else {
            WP_CLI::success( 'Importação concluída.' );
        }
    }

    /**
     * Adapted to handle CSV import via WP-CLI.
     */
    public static function process_posts_cli( $importer ) {
        $h = new RS_CSV_Helper;
        $handle = $h->fopen($importer->file, 'r');
        if ( $handle == false ) {
            return new WP_Error( 'csv_importer', 'Failed to open file.' );
        }

        $is_first = true;
        $post_statuses = get_post_stati();

        $count = 0;
        while (($data = $h->fgetcsv($handle)) !== false) {
            if ($is_first) {
                $h->parse_columns($importer, $data);
                $is_first = false;
            } else {
                $post = array();
                $is_update = false;
                $error = new WP_Error();

                // Replicate the logic from the original importer
                $post_type = $h->get_data($importer, $data, 'post_type');
                if ($post_type) {
                    if (post_type_exists($post_type)) {
                        $post['post_type'] = $post_type;
                    } else {
                        $error->add('post_type_exists', sprintf('Invalid post_type: "%s".', $post_type));
                    }
                } else {
                    WP_CLI::log('Attention: post_type not found.');
                }

                $post_id = $h->get_data($importer, $data, 'ID');
                $post_id = ($post_id) ? $post_id : $h->get_data($importer, $data, 'post_id');
                if ($post_id) {
                    $post_exist = get_post($post_id);
                    if ( is_null($post_exist) ) {
                        $post['import_id'] = $post_id;
                    } else {
                        if (!$post_type || $post_exist->post_type == $post_type ) {
                            $post['ID'] = $post_id;
                            $is_update = true;
                        } else {
                            $error->add('post_type_check', sprintf('The post_type value from your csv file does not match the existing data in the database. post_id: %d, post_type(csv): %s, post_type(db): %s', $post_id, $post_type, $post_exist->post_type));
                        }
                    }
                }

                $post_title = $h->get_data($importer, $data, 'post_title');
                if ($post_title) {
                    $post['post_title'] = $post_title;
                }

                $post_name = $h->get_data($importer, $data, 'post_name');
                if ($post_name) {
                    $post['post_name'] = $post_name;
                }

                $post_author = $h->get_data($importer, $data, 'post_author');
                if ($post_author) {
                    if (is_numeric($post_author)) {
                        $user = get_user_by('id', $post_author);
                    } else {
                        $user = get_user_by('login', $post_author);
                    }
                    if (isset($user) && is_object($user)) {
                        $post['post_author'] = $user->ID;
                        unset($user);
                    }
                }

                $user_login = $h->get_data($importer, $data, 'post_author_login');
                if ($user_login) {
                    $user = get_user_by('login', $user_login);
                    if (isset($user) && is_object($user)) {
                        $post['post_author'] = $user->ID;
                        unset($user);
                    }
                }

                $post_date = $h->get_data($importer, $data, 'post_date');
                if ($post_date) {
                    $post['post_date'] = date("Y-m-d H:i:s", strtotime($post_date));
                }
                $post_date_gmt = $h->get_data($importer, $data, 'post_date_gmt');
                if ($post_date_gmt) {
                    $post['post_date_gmt'] = date("Y-m-d H:i:s", strtotime($post_date_gmt));
                }

                $post_status = $h->get_data($importer, $data, 'post_status');
                if ($post_status) {
                    if (in_array($post_status, $post_statuses)) {
                        $post['post_status'] = $post_status;
                    }
                }

                $post_password = $h->get_data($importer, $data, 'post_password');
                if ($post_password) {
                    $post['post_password'] = $post_password;
                }

                $post_content = $h->get_data($importer, $data, 'post_content');
                if ($post_content) {
                    $post['post_content'] = $post_content;
                }

                $post_excerpt = $h->get_data($importer, $data, 'post_excerpt');
                if ($post_excerpt) {
                    $post['post_excerpt'] = $post_excerpt;
                }

                $post_parent = $h->get_data($importer, $data, 'post_parent');
                if ($post_parent) {
                    $post['post_parent'] = $post_parent;
                }

                $menu_order = $h->get_data($importer, $data, 'menu_order');
                if ($menu_order) {
                    $post['menu_order'] = $menu_order;
                }

                $comment_status = $h->get_data($importer, $data, 'comment_status');
                if ($comment_status) {
                    $post['comment_status'] = $comment_status;
                }

                $post_category = $h->get_data($importer, $data, 'post_category');
                if ($post_category) {
                    $categories = preg_split("/,+/", $post_category);
                    if ($categories) {
                        $post['post_category'] = wp_create_categories($categories);
                    }
                }

                $post_tags = $h->get_data($importer, $data, 'post_tags');
                if ($post_tags) {
                    $post['post_tags'] = $post_tags;
                }

                $post_thumbnail = $h->get_data($importer, $data, 'post_thumbnail');

                $meta = array();
                $tax = array();

                foreach ($data as $key => $value) {
                    if ($value !== false && isset($importer->column_keys[$key])) {
                        if (substr($importer->column_keys[$key], 0, 4) == 'tax_') {
                            $customtaxes = preg_split("/,+/", $value);
                            $taxname = substr($importer->column_keys[$key], 4);
                            $tax[$taxname] = array();
                            foreach ($customtaxes as $value2) {
                                $tax[$taxname][] = $value2;
                            }
                        } else {
                            $meta[$importer->column_keys[$key]] = $value;
                        }
                    }
                }

                $post = apply_filters('really_simple_csv_importer_save_post', $post, $is_update);
                $meta = apply_filters('really_simple_csv_importer_save_meta', $meta, $post, $is_update);
                $tax = apply_filters('really_simple_csv_importer_save_tax', $tax, $post, $is_update);
                $post_thumbnail = apply_filters('really_simple_csv_importer_save_thumbnail', $post_thumbnail, $post, $is_update);
                $dry_run = apply_filters('really_simple_csv_importer_dry_run', false);

                if (!$error->get_error_codes() && $dry_run == false) {
                    $class = apply_filters('really_simple_csv_importer_class', null);
                    if ($class && class_exists($class, false)) {
                        $importer_class = new $class;
                        $result = $importer_class->save_post($post, $meta, $tax, $post_thumbnail, $is_update);
                    } else {
                        $result = $importer->save_post($post, $meta, $tax, $post_thumbnail, $is_update);
                    }

                    if (is_object($result) && method_exists($result, 'isError') && $result->isError()) {
                        $error = $result->getError();
                    } else {
                        $post_object = (is_object($result) && method_exists($result, 'getPost')) ? $result->getPost() : null;
                        if (is_object($post_object)) {
                            do_action('really_simple_csv_importer_post_saved', $post_object);
                        }
                        WP_CLI::log("Imported post: " . ($post_title ?: '(no title)'));
                        $count++;
                    }
                }

                foreach ($error->get_error_messages() as $message) {
                    WP_CLI::warning($message);
                }

                // Flush memory
                wp_cache_flush();
            }
        }

        $h->fclose($handle);

        WP_CLI::success("Finished import. Imported $count posts");

        return true;
    }
}

WP_CLI::add_command('rs-csv-importer', 'RS_CSV_Importer_CLI_Command');
