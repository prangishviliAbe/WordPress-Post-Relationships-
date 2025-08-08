<?php
/**
 * Plugin Name: პოსტების ურთიერთკავშირები
 * Description: WordPress-ის პლაგინი პოსტებს შორის ურთიერთკავშირების შესაქმნელად
 * Version: 1.0.0
 * Author: პრანგიშვილი აბე
 * Text Domain: post-relationships
 */

// უსაფრთხოების შემოწმება
if (!defined('ABSPATH')) {
    exit;
}

class Post_Relationships {
    
    public function __construct() {
        // AJAX და სკრიპტების hooks
        add_action('wp_ajax_search_posts', array($this, 'ajax_search_posts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // სტანდარტული hooks
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('add_meta_boxes', array($this, 'add_relationship_meta_box'));
        add_action('save_post', array($this, 'save_relationship_data'));
    }

    // პლაგინის აქტივაცია და ცხრილის შექმნა
    public function activate_plugin() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'post_relationships';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            related_post_id bigint(20) NOT NULL,
            relationship_type varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY related_post_id (related_post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // ადმინ სკრიპტების ჩატვირთვა
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        // Select2
        wp_enqueue_style(
            'select2', 
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', 
            array(), 
            '4.1.0-rc.0'
        );
        
        wp_enqueue_script(
            'select2', 
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', 
            array('jquery'), 
            '4.1.0-rc.0', 
            true
        );

        // Custom styles
        wp_enqueue_style(
            'post-relationships-admin',
            plugins_url('assets/css/admin-styles.css', __FILE__),
            array('select2'),
            '1.0.0'
        );
    }

    // AJAX პოსტების ძიება
    public function ajax_search_posts() {
        check_ajax_referer('post_relationships_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('უფლებების შემოწმება ვერ გაიარა');
        }

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            's' => $search,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        $query = new WP_Query($args);
        $results = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $results[] = array(
                    'id' => get_the_ID(),
                    'text' => get_the_title()
                );
            }
        }

        wp_reset_postdata();
        wp_send_json($results);
    }

    // ადმინ მენიუს დამატება
    public function add_admin_menu() {
        add_menu_page(
            'პოსტების კავშირები',
            'პოსტების კავშირები',
            'manage_options',
            'post-relationships',
            array($this, 'admin_page'),
            'dashicons-admin-links',
            30
        );
    }

    // მეტა ბოქსის დამატება
    public function add_relationship_meta_box() {
        add_meta_box(
            'post_relationships_box',
            'დაკავშირებული პოსტები',
            array($this, 'relationship_meta_box_content'),
            'post',
            'normal',
            'high'
        );
    }

    // მეტა ბოქსის შიგთავსი
    public function relationship_meta_box_content($post) {
        wp_nonce_field('post_relationships_nonce', 'post_relationships_nonce');
        
        $related_posts = $this->get_related_posts($post->ID);
        ?>
        <div class="post-relationships-box">
            <p>
                <label for="related-posts">აირჩიეთ დაკავშირებული პოსტები:</label>
                <select name="related_posts[]" id="related-posts" multiple="multiple" style="width: 100%;">
                    <?php
                    if ($related_posts) {
                        foreach ($related_posts as $related_post) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $related_post->ID,
                                esc_html($related_post->post_title)
                            );
                        }
                    }
                    ?>
                </select>
            </p>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#related-posts').select2({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            q: params.term,
                            action: 'search_posts',
                            nonce: '<?php echo wp_create_nonce("post_relationships_nonce"); ?>'
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'მოძებნეთ პოსტები...',
                language: {
                    inputTooShort: function() {
                        return 'გთხოვთ შეიყვანოთ მინიმუმ 2 სიმბოლო';
                    },
                    noResults: function() {
                        return 'შედეგები არ მოიძებნა';
                    },
                    searching: function() {
                        return 'მიმდინარეობს ძიება...';
                    }
                },
                templateResult: function(data) {
                    if (data.loading) {
                        return data.text;
                    }
                    return $('<div>').text(data.text);
                }
            });
        });
        </script>
        <?php
    }

    // მონაცემების შენახვა
    public function save_relationship_data($post_id) {
        if (!isset($_POST['post_relationships_nonce']) || 
            !wp_verify_nonce($_POST['post_relationships_nonce'], 'post_relationships_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // წინა კავშირების წაშლა
        $this->delete_relationships($post_id);

        // ახალი კავშირების შენახვა
        if (isset($_POST['related_posts']) && is_array($_POST['related_posts'])) {
            foreach ($_POST['related_posts'] as $related_post_id) {
                $this->add_relationship($post_id, intval($related_post_id));
            }
        }
    }

    // დაკავშირებული პოსტების მიღება
    private function get_related_posts($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_relationships';
        
        $related_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT related_post_id FROM $table_name WHERE post_id = %d",
            $post_id
        ));

        if (empty($related_ids)) {
            return array();
        }

        return get_posts(array(
            'post__in' => $related_ids,
            'post_type' => 'post',
            'posts_per_page' => -1,
            'orderby' => 'post__in'
        ));
    }

    // კავშირის დამატება
    private function add_relationship($post_id, $related_post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_relationships';
        
        return $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'related_post_id' => $related_post_id,
                'relationship_type' => 'related'
            ),
            array('%d', '%d', '%s')
        );
    }

    // კავშირების წაშლა
    private function delete_relationships($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_relationships';
        
        return $wpdb->delete(
            $table_name,
            array('post_id' => $post_id),
            array('%d')
        );
    }

    // ადმინ გვერდის შიგთავსი
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>პოსტების ურთიერთკავშირები</h1>
            <p>აქ შეგიძლიათ ნახოთ ყველა არსებული კავშირი პოსტებს შორის.</p>
            <?php
            global $wpdb;
            $table_name = $wpdb->prefix . 'post_relationships';
            $relationships = $wpdb->get_results("
                SELECT r.*, 
                       p1.post_title as source_title,
                       p2.post_title as target_title
                FROM $table_name r
                LEFT JOIN {$wpdb->posts} p1 ON r.post_id = p1.ID
                LEFT JOIN {$wpdb->posts} p2 ON r.related_post_id = p2.ID
                ORDER BY r.created_at DESC
            ");
            
            if ($relationships): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>პოსტი</th>
                            <th>დაკავშირებული პოსტი</th>
                            <th>კავშირის ტიპი</th>
                            <th>შექმნის თარიღი</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($relationships as $relation): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_post_link($relation->post_id); ?>">
                                        <?php echo esc_html($relation->source_title); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo get_edit_post_link($relation->related_post_id); ?>">
                                        <?php echo esc_html($relation->target_title); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($relation->relationship_type); ?></td>
                                <td><?php echo esc_html($relation->created_at); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>ჯერ არ არის შექმნილი არცერთი კავშირი.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}

// პლაგინის ინიციალიზაცია
new Post_Relationships();