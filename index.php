<?php
/*
Plugin Name: Alt Text Checker
Description: This plugin allows you to analyze all the images that are being used in posts and products to find all the images and show you the ones that do not have alternative text, giving you the possibility to fix it on the spot.
Version: 1.0
Author: Royler Marichal Carrazana @RoylerMarichal
Text Domain: text-alt-checker
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function cta_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cta_images';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        image_url varchar(255) NOT NULL,
        alt_text varchar(255) DEFAULT '',
        post_url varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'cta_create_table');

function cta_delete_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cta_images';

    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
}

register_deactivation_hook(__FILE__, 'cta_delete_table');

// Enqueue scripts and styles
function cta_enqueue_scripts($hook)
{
    if ($hook != 'toplevel_page_corretor-textos-alternativos') {
        return;
    }
    wp_enqueue_style('cta_styles', plugin_dir_url(__FILE__) . 'css/styles.css');
    wp_enqueue_script('cta_scripts', plugin_dir_url(__FILE__) . 'js/scripts.js', array('jquery'), null, true);
    wp_localize_script('cta_scripts', 'cta_ajax', array('ajax_url' => admin_url('admin-ajax.php')));
}

add_action('admin_enqueue_scripts', 'cta_enqueue_scripts');

function cta_add_menu()
{
    add_menu_page(
        __('Text Alt Checker', 'corretor-textos-alternativos'),
        __('Alt Text Cheker', 'corretor-textos-alternativos'),
        'manage_options',
        'corretor-textos-alternativos',
        'cta_admin_page',
        'dashicons-format-image'
    );
}

add_action('admin_menu', 'cta_add_menu');

function ilp_display_scan_tab() {
    global $wpdb;

    $query_images_in_use = "
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->prefix}posts p
        LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.meta_value
        WHERE p.post_type = 'attachment'
        AND p.post_mime_type LIKE 'image/%'
        AND (
            EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}postmeta pm2
                WHERE pm2.meta_key = '_thumbnail_id'
                AND pm2.meta_value = p.ID
            )
            OR EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}postmeta pm3
                WHERE pm3.meta_key = '_product_image_gallery'
                AND FIND_IN_SET(p.ID, pm3.meta_value)
            )
            OR EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}posts p2
                WHERE p2.post_content LIKE CONCAT('%', p.guid, '%')
            )
        )
    ";
    $total_images_in_use = $wpdb->get_var($query_images_in_use);

    $query_images_without_alt_text = "
        SELECT COUNT(ID)
        FROM {$wpdb->prefix}posts
        WHERE post_type = 'attachment'
        AND post_mime_type LIKE 'image/%'
        AND ID NOT IN (
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->prefix}posts p
            LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_wp_attachment_image_alt'
        )
    ";
    $total_images_without_alt_text = $wpdb->get_var($query_images_without_alt_text);

    ?>
    <h2>Find images</h2>
    <p>Click the button to find and save the images in use:</p>
    <p>Total images in use: <?php echo $total_images_in_use; ?></p>
    <p>Total images without alt text: <?php echo $total_images_without_alt_text; ?></p>

    <form method="post" action="">
        <input type="hidden" name="ilp_scan_images" value="1">
        <?php submit_button('Find images'); ?>
    </form>
    <?php
    if (isset($_POST['ilp_scan_images'])) {
        ilp_scan_and_save_images();
    }
}


function cta_admin_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cta_images';

    $total_images = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_images_without_alt_text = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE alt_text = ''");

?>
    <div class="wrap">
        <h1><?php _e('Text Alt Checker', 'corretor-textos-alternativos'); ?></h1>
        <h2 class="nav-tab-wrapper">
            <a href="#scan-tab" class="nav-tab nav-tab-active" data-tab="scan-tab"><?php _e('Analyze Images', 'corretor-textos-alternativos'); ?></a>
            <a href="#results-tab" class="nav-tab" data-tab="results-tab"><?php _e('Results', 'corretor-textos-alternativos'); ?></a>
        </h2>
        <div id="scan-tab-content" class="tab-content active">
            <button id="cta-scan-button" class="button   button-primary" style="margin: 10px;"><?php _e('Analisar', 'corretor-textos-alternativos'); ?></button>
            <p id="cta-scan-status"></p>
        </div>
        <div id="results-tab-content" class="tab-content">
            <p>Total images: <?php echo $total_images; ?></p>
            <p>Total images without alt text: <?php echo $total_images_without_alt_text; ?></p>
            <label>
                <input type="checkbox" id="filter-no-alt" style="margin: 10px;"> Show only images without alt text
            </label>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th><?php _e('Image', 'corretor-textos-alternativos'); ?></th>
                        <th><?php _e('Current Alternative Text', 'corretor-textos-alternativos'); ?></th>
                        <th><?php _e('Post URL', 'corretor-textos-alternativos'); ?></th>
                        <th><?php _e('New alt text', 'corretor-textos-alternativos'); ?></th>
                    </tr>
                </thead>
                <tbody id="cta-results-table-body">
                </tbody>
            </table>
            <div class="cta-pagination">
            </div>
        </div>
    </div>
<?php
}


// AJAX handler for scanning and saving images
function cta_scan_images() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cta_images';

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $posts_per_batch = 10;

    // Obtener el número total de posts, páginas y productos
    $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
    if (post_type_exists('product')) {
        $total_posts += wp_count_posts('product')->publish;
    }

    // Consulta para contar las imágenes en tu tabla personalizada
    $total_images_in_use = $wpdb->get_var("SELECT COUNT(DISTINCT image_url) FROM $table_name");

    // Consulta para contar las imágenes sin texto alternativo en tu tabla personalizada
    $total_images_without_alt_text = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE alt_text = ''");

    $args = array(
        'post_type' => array('post', 'page'),
        'post_status' => 'publish',
        'posts_per_page' => $posts_per_batch,
        'offset' => $offset
    );

    // Incluir productos solo si WooCommerce está activo
    if (post_type_exists('product')) {
        $args['post_type'][] = 'product';
    }

    $posts = get_posts($args);

    if (!empty($posts)) {
        foreach ($posts as $post) {
            if (preg_match_all('/<img[^>]+>/i', $post->post_content, $result)) {
                foreach ($result[0] as $img_tag) {
                    if (preg_match('/src="([^"]+)/i', $img_tag, $src)) {
                        $src = str_ireplace('src="', '', $src[0]);
                        $alt = '';
                        if (preg_match('/alt="([^"]+)/i', $img_tag, $alt_text)) {
                            $alt = str_ireplace('alt="', '', $alt_text[0]);
                        }

                        $existing_image = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE image_url = %s", $src));
                        if ($existing_image == 0) {
                            $wpdb->insert(
                                $table_name,
                                array(
                                    'image_url' => $src,
                                    'alt_text' => $alt,
                                    'post_url' => get_permalink($post->ID)
                                )
                            );
                        }
                    }
                }
            }

            if ($post->post_type === 'product') {
                $product_gallery_ids = get_post_meta($post->ID, '_product_image_gallery', true);
                if (!empty($product_gallery_ids)) {
                    $product_gallery_ids = explode(',', $product_gallery_ids);
                    foreach ($product_gallery_ids as $gallery_id) {
                        $image_url = wp_get_attachment_url($gallery_id);
                        if ($image_url) {
                            $alt = get_post_meta($gallery_id, '_wp_attachment_image_alt', true);
                            $existing_image = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE image_url = %s", $image_url));
                            if ($existing_image == 0) {
                                $wpdb->insert(
                                    $table_name,
                                    array(
                                        'image_url' => $image_url,
                                        'alt_text' => $alt,
                                        'post_url' => get_permalink($post->ID)
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }

        $next_offset = $offset + $posts_per_batch;
        if ($next_offset >= $total_posts) {
            wp_send_json_success(array('completed' => true, 'total_images_in_use' => $total_images_in_use, 'total_images_without_alt_text' => $total_images_without_alt_text));
        } else {
            wp_send_json_success(array('offset' => $next_offset, 'total_images_in_use' => $total_images_in_use, 'total_images_without_alt_text' => $total_images_without_alt_text));
        }
    } else {
        wp_send_json_success(array('completed' => true, 'total_images_in_use' => $total_images_in_use, 'total_images_without_alt_text' => $total_images_without_alt_text));
    }
}

add_action('wp_ajax_cta_scan_images', 'cta_scan_images');




// AJAX handler for loading results
// AJAX handler for loading results
// AJAX handler for loading results
function cta_load_results()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cta_images';
    $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
    $posts_per_page = 20;
    $filter_no_alt = isset($_POST['filter_no_alt']) ? intval($_POST['filter_no_alt']) : 0;

    $where_clause = '';
    if ($filter_no_alt) {
        $where_clause = "WHERE alt_text = ''";
    }

    $total_images = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where_clause");
    $total_pages = ceil($total_images / $posts_per_page);
    $offset = ($paged - 1) * $posts_per_page;

    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name $where_clause LIMIT %d OFFSET %d", $posts_per_page, $offset));

    ob_start();
    foreach ($results as $result) {
        echo '<tr>';
        echo '<td><img src="' . esc_url($result->image_url) . '" style="width:150px;height:150px;border-radius:5%;object-fit:cover;" /></td>';
        echo '<td>' . esc_html($result->alt_text) . '</td>';
        echo '<td><a href="' . esc_url($result->post_url) . '" target="_blank">' . esc_html($result->post_url) . '</a></td>';
        echo '<td><input type="text" class="cta-new-alt-text" data-image-id="' . esc_attr($result->id) . '" value="' . esc_attr($result->alt_text) . '" /></td>';
        echo '</tr>';
    }
    $content = ob_get_clean();

    wp_send_json_success(array('content' => $content, 'paged' => $paged, 'total_pages' => $total_pages, 'total_images' => $total_images, 'total_images_without_alt_text' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE alt_text = ''")));
}

add_action('wp_ajax_cta_load_results', 'cta_load_results');



// AJAX handler for updating alt text
// AJAX handler for updating alt text and post content
// AJAX handler for updating alt text and post content
function cta_update_alt_text()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cta_images';

    $image_id = intval($_POST['image_id']);
    $new_alt_text = sanitize_text_field($_POST['new_alt_text']);

    // Obtener la información de la imagen
    $image_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $image_id));

    if ($image_info) {
        // Actualizar la tabla personalizada
        $wpdb->update(
            $table_name,
            array('alt_text' => $new_alt_text),
            array('id' => $image_id)
        );

        // Actualizar el contenido del post
        $post_url = $image_info->post_url;
        $post_id = url_to_postid($post_url);
        $post = get_post($post_id);
        $post_content = $post->post_content;

        // Reemplazar el texto alternativo en el contenido del post
        $updated_content = preg_replace(
            '/<img([^>]*?)src="' . preg_quote($image_info->image_url, '/') . '"([^>]*?)alt="[^"]*"(.*?)>/i',
            '<img$1src="' . $image_info->image_url . '"$2alt="' . $new_alt_text . '"$3>',
            $post_content
        );

        // Actualizar el post en la base de datos
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $updated_content
        ));

        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

add_action('wp_ajax_cta_update_alt_text', 'cta_update_alt_text');
