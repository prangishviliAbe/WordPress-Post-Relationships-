<?php
/*
Plugin Name: Post Relationship for Elementor 
Description: ორმხრივი/ერთმხრივი პოსტ-კავშირები ყველა UI CPT-ზე. Forward ბოქსი: კატეგორიებით დაჯგუფებული, collapsible ჯგუფები, ძიება, გვერდების toggle და მონიშნულის ჰაილაიტი. Reverse ბოქსი: ერთმხრივ რეჟიმში სათაური ლოგიკურად იცვლება („შვილები“ თუ current → others, თორემ „მშობლები“), ორმხრივში — „კავშირები“. აქტიური ბმულები და წაშლის ხატულა. Elementor queries და admin column.
Version: 2.41
Author: Abe Prangishvili
Text Domain: pr-rel
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/* -------------------------------------------------
   პარამეტრების გვერდი (Options API + Sanitization)
------------------------------------------------- */
add_action('admin_menu', function(){
    add_options_page(
        'კავშირების პარამეტრები',
        'პოსტების კავშირები',
        'manage_options',
        'pr-rel-settings',
        'pr_rel_render_settings_page'
    );
});

add_action('admin_init', function(){
    register_setting('pr_rel_settings','pr_rel_show_pages',[
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 1
    ]);
    register_setting('pr_rel_settings','pr_rel_highlight_color',[
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#A61832'
    ]);
    register_setting('pr_rel_settings','pr_rel_link_mode',[
        'type' => 'string',
        'sanitize_callback' => function($v){ return in_array($v,['both','oneway'],true)?$v:'both'; },
        'default' => 'both'
    ]);

    add_settings_section('pr_rel_main','პარამეტრები',null,'pr-rel-settings');

    add_settings_field('pr_rel_show_pages','გვერდების გამოჩენა', function(){
        $v = (int) get_option('pr_rel_show_pages',1);
        echo '<label><input type="checkbox" name="pr_rel_show_pages" value="1" '.checked(1,$v,false).'> ჩართული</label>';
    },'pr-rel-settings','pr_rel_main');

    add_settings_field('pr_rel_highlight_color','მონიშნულის ფერი', function(){
        $v = get_option('pr_rel_highlight_color','#A61832');
        echo '<input type="color" name="pr_rel_highlight_color" value="'.esc_attr($v).'"> ';
        echo '<span class="description">ამ ფერით გამოიკვეთება მონიშნული ელემენტები სიაში.</span>';
    },'pr-rel-settings','pr_rel_main');

    add_settings_field('pr_rel_link_mode','კავშირის რეჟიმი', function(){
        $v = get_option('pr_rel_link_mode','both');
        echo '<select name="pr_rel_link_mode">';
        echo '<option value="both" '.selected($v,'both',false).'>ორმხრივი</option>';
        echo '<option value="oneway" '.selected($v,'oneway',false).'>ერთმხრივი</option>';
        echo '</select>';
        echo '<p class="description">ერთმხრივი: A→B მხოლოდ A-ზე ინახება. B-ის რედაქტორში „მშობელი“ გამოჩნდება, ხოლო A-ზე „შვილები“.</p>';
    },'pr-rel-settings','pr_rel_main');
});

function pr_rel_render_settings_page(){
    if(!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>კავშირების პარამეტრები</h1><form method="post" action="options.php">';
    settings_fields('pr_rel_settings');
    do_settings_sections('pr-rel-settings');
    submit_button();
    echo '</form></div>';
}

/* -------------------------------------------------
   დამხმარე ფუნქციები (სიცივე/ოპტიმიზაცია)
------------------------------------------------- */
function pr_rel_get_ui_post_types(){
    $types = get_post_types(['show_ui'=>true],'names');
    if(!is_array($types)) $types = [];
    $exclude = [
        'attachment','revision','nav_menu_item',
        'custom_css','customize_changeset','oembed_cache','user_request',
        'wp_block','wp_template','wp_template_part','wp_global_styles','wp_navigation',
        'elementor_library'
    ];
    foreach($exclude as $ex){ unset($types[$ex]); }
    return array_values($types);
}

function pr_rel_group_label_by_category($post_id){
    $post_id = (int) $post_id;
    if(!$post_id) return 'უცნობი';

    $terms = get_the_terms($post_id,'category');
    if(is_array($terms) && $terms){
        return esc_html($terms[0]->name);
    }

    $pt = get_post_type($post_id);
    if($pt){
        $taxes = get_object_taxonomies($pt, 'objects');
        foreach($taxes as $tax){
            if(empty($tax->public) || empty($tax->hierarchical)) continue;
            $tt = get_the_terms($post_id, $tax->name);
            if(is_array($tt) && $tt){
                return esc_html($tt[0]->name);
            }
        }
    }
    $obj = $pt ? get_post_type_object($pt) : null;
    return $obj ? $obj->labels->singular_name : ($pt ?: 'უცნობი');
}

function pr_rel_get_referencing_ids($target_id){
    $t = (int) $target_id;
    if(!$t) return [];
    $q = new WP_Query([
        'post_type'      => pr_rel_get_ui_post_types(),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation'=>'OR',
            ['key'=>'_related_posts','value'=>'i:'.$t.';','compare'=>'LIKE'],
            ['key'=>'_related_posts','value'=>'"'.$t.'"','compare'=>'LIKE'],
        ],
        'no_found_rows'  => true,
        'suppress_filters' => true,
        'orderby'        => 'ID',
        'order'          => 'DESC',
    ]);
    return $q->posts ?: [];
}

function pr_rel_add($src,$tgt){
    $src = (int) $src; $tgt = (int) $tgt;
    if(!$src || !$tgt) return;
    $rel = get_post_meta($src,'_related_posts',true);
    if(!is_array($rel)) $rel = [];
    if(!in_array($tgt,$rel,true)){
        $rel[] = $tgt;
        update_post_meta($src,'_related_posts',array_values(array_unique(array_map('intval',$rel))));
    }
}

function pr_rel_remove($src,$tgt){
    $src = (int) $src; $tgt = (int) $tgt;
    if(!$src || !$tgt) return;
    $rel = get_post_meta($src,'_related_posts',true);
    if(!is_array($rel)) return;
    $new = array_values(array_filter(array_map('intval',$rel), fn($r)=>$r!==$tgt));
    if($new) update_post_meta($src,'_related_posts',$new);
    else delete_post_meta($src,'_related_posts');
}

function pr_rel_detect_current_id(){
    $id = get_queried_object_id() ?: 0;
    if(!$id && isset($GLOBALS['post']->ID)) $id = (int) $GLOBALS['post']->ID;
    return (int) $id;
}

/* -------------------------------------------------
   ადმინის სტილები (დაბრუნებული UX)
------------------------------------------------- */
add_action('admin_enqueue_scripts', function(){
    wp_enqueue_style('dashicons');
    wp_register_style('pr-rel-admin-css', false);
    wp_enqueue_style('pr-rel-admin-css');

    $color = sanitize_hex_color(get_option('pr_rel_highlight_color','#A61832'));
    if(!$color) $color = '#A61832';

    wp_add_inline_style('pr-rel-admin-css', "
        .pr-rel-toolbar{display:flex;gap:8px;align-items:center;margin-bottom:12px;}
        .pr-rel-toolbar input[type=search]{flex:1;padding:6px 10px;border:1px solid #ccc;border-radius:4px;}
        .pr-rel-toolbar label{display:inline-flex;align-items:center;gap:6px;user-select:none;}
        .pr-rel-list{max-height:350px;overflow:auto;border:1px solid #ddd;border-radius:4px;padding:8px;background:#fff;}
        .pr-rel-group{margin-bottom:12px;}
        .pr-rel-group-title{font-size:14px;background:#f0f0f0;padding:6px 10px;margin:0;border-radius:4px;cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;}
        .pr-rel-group-title .count{color:#666;font-weight:normal;}
        .pr-rel-group-list{list-style:none;margin:4px 0 0;padding:0;}
        .pr-rel-group-list li{margin:4px 0;}
        .pr-rel-group-list label{display:flex;align-items:center;gap:6px;padding:2px 4px;border-radius:3px;transition:background .2s;}
        .pr-rel-group-list label:hover{background:#fafafa;}
        .pr-rel-collapsed .pr-rel-group-list{display:none;}
        .pr-rel-group-list label.pr-rel-selected{background-color: {$color} !important;color:#fff !important;}
        .pr-rel-group-list label.pr-rel-selected input[type='checkbox']{accent-color:#fff !important;}
        .pr-rel-delete-icon.dashicons{font-size:16px;color:#a00;cursor:pointer;vertical-align:middle;margin-left:8px;}
        .pr-rel-note{margin:6px 0;color:#666;}
        .pr-rel-remove-all{margin-top:15px;}
    ");
});

/* -------------------------------------------------
   მეტა ბოქსები
------------------------------------------------- */
add_action('add_meta_boxes', function(){
    add_meta_box('pr_forward','დაკავშირებული პოსტები','pr_rel_forward_box',null,'normal','default');
    add_meta_box('pr_reverse','ვინ უკავშირდება ამ პოსტს','pr_rel_reverse_box',null,'normal','default');
});

function pr_rel_forward_box($post){
    if(!current_user_can('edit_post',$post->ID)) return;
    wp_nonce_field('pr_rel_save','pr_rel_nonce');

    $show_pages_default = get_option('pr_rel_show_pages',1) ? true : false;

    echo '<div class="pr-rel-toolbar">';
      echo '<input type="search" id="pr-rel-search-'. (int)$post->ID .'" placeholder="ძიება...">';
      echo '<label><input type="checkbox" id="pr-rel-toggle-pages-'. (int)$post->ID .'" '.( $show_pages_default ? 'checked' : '' ).'> გვერდების ჩვენება</label>';
    echo '</div>';

    $related = get_post_meta($post->ID,'_related_posts',true);
    if(!is_array($related)) $related = [];

    $items = get_posts([
        'post_type'        => pr_rel_get_ui_post_types(),
        'posts_per_page'   => -1,
        'exclude'          => [$post->ID],
        'orderby'          => 'title',
        'order'            => 'ASC',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);

    $groups = [];
    foreach($items as $itm){
        $lbl = pr_rel_group_label_by_category($itm->ID);
        $groups[$lbl][] = $itm;
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    echo '<div id="pr-rel-list-'. (int)$post->ID .'" class="pr-rel-list">';
    foreach($groups as $lbl => $posts_arr){
        echo '<div class="pr-rel-group">';
          echo '<h4 class="pr-rel-group-title">'. esc_html($lbl) .' <span class="count">('. (int)count($posts_arr) .')</span></h4>';
          echo '<ul class="pr-rel-group-list">';
          foreach($posts_arr as $p){
              $ch = in_array($p->ID,$related,true) ? 'checked':'';
              $type_class = 'pr-rel-type-'. esc_attr($p->post_type);
              echo '<li class="pr-rel-item '. $type_class .'">';
                echo '<label>';
                  echo '<input type="checkbox" name="pr_rel_related[]" value="'. esc_attr($p->ID) .'" '. $ch .'> ';
                  echo esc_html(get_the_title($p));
                echo '</label>';
              echo '</li>';
          }
          echo '</ul>';
        echo '</div>';
    }
    echo '</div>';

    echo "<script>
    (function(){
      var list = document.getElementById('pr-rel-list-{$post->ID}');
      var inp  = document.getElementById('pr-rel-search-{$post->ID}');
      var tog  = document.getElementById('pr-rel-toggle-pages-{$post->ID}');
      if(!list||!inp||!tog) return;

      inp.addEventListener('input', function(){
        var term = this.value.toLowerCase();
        list.querySelectorAll('.pr-rel-item').forEach(function(i){
          i.style.display = i.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
      });

      var defaultShow = ".($show_pages_default?'true':'false').";
      if(!defaultShow){
        list.querySelectorAll('.pr-rel-type-page').forEach(function(i){ i.style.display='none'; });
      }

      tog.addEventListener('change', function(){
        var show = this.checked;
        list.querySelectorAll('.pr-rel-type-page').forEach(function(i){
          i.style.display = show? '' : 'none';
        });
      });

      list.querySelectorAll('input[type=checkbox]').forEach(function(cb){
        var lbl = cb.closest('label');
        function hl(){ cb.checked ? lbl.classList.add('pr-rel-selected') : lbl.classList.remove('pr-rel-selected'); }
        hl(); cb.addEventListener('change', hl);
      });

      list.querySelectorAll('.pr-rel-group-title').forEach(function(h){
        h.addEventListener('click', function(){
          this.closest('.pr-rel-group').classList.toggle('pr-rel-collapsed');
        });
      });
    })();
    </script>";
}

function pr_rel_reverse_box($post){
    if(!current_user_can('edit_post',$post->ID)) return;
    wp_nonce_field('pr_rel_save','pr_rel_nonce');

    $mode     = get_option('pr_rel_link_mode','both');
    $children = get_post_meta($post->ID,'_related_posts',true);
    if(!is_array($children)) $children = [];
    $parents  = pr_rel_get_referencing_ids($post->ID);

    $mode_label = ($mode === 'oneway') ? 'ერთმხრივი რეჟიმი' : 'ორმხრივი რეჟიმი';

    echo '<p class="pr-rel-note" style="margin-bottom:8px;color:#555;"><strong>რეჟიმი:</strong> '. esc_html($mode_label) .'</p>';
    echo '<div class="pr-rel-toolbar">';
    echo '<input type="search" id="pr-rel-rev-search-'. (int)$post->ID .'" placeholder="ძიება კავშირებში...">';
    echo '</div>';

    echo '<div id="pr-rel-reverse-'. (int)$post->ID .'" class="pr-rel-list">';

    // ერთმხრივი რეჟიმის ლოგიკა
    if($mode === 'oneway'){
        // პირველ რიგში ვამოწმებთ არის თუ არა მიმდინარე პოსტი "შვილი" (აქვს მშობელი)
        if(!empty($parents)){
            // მიმდინარე პოსტი არის "შვილი" - ვაჩვენებთ მის კავშირებს
            echo '<h4 class="pr-rel-group-title" style="color:#A61832;">კავშირი <span class="count">('.count($parents).')</span></h4>';
            $items = $parents;
        } 
        // შემდეგ ვამოწმებთ არის თუ არა ის "მშობელი" (აქვს შვილები)
        elseif(!empty($children)){
            // მიმდინარე პოსტი არის "მშობელი" - ვაჩვენებთ მის კავშირებს
            echo '<h4 class="pr-rel-group-title" style="color:#A61832;">კავშირი <span class="count">('.count($children).')</span></h4>';
            $items = $children;
        } else {
            echo '<p class="pr-rel-note">კავშირები არ მოიძებნა.</p>';
            echo '</div>';
            return;
        }
    } 
    // ორმხრივი რეჟიმის ლოგიკა
    else {
        $items = array_unique(array_merge($children,$parents));
        if(empty($items)){
            echo '<p class="pr-rel-note">კავშირები არ მოიძებნა.</p>';
            echo '</div>';
            return;
        }
        echo '<h4 class="pr-rel-group-title" style="color:#A61832;">კავშირი <span class="count">('.count($items).')</span></h4>';
    }

    echo '<ul class="pr-rel-group-list">';

    $posts = get_posts([
        'post__in' => $items,
        'post_type' => pr_rel_get_ui_post_types(),
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);

    foreach($posts as $p){
        $is_child  = in_array($p->ID,$children,true);
        $is_parent = in_array($p->ID,$parents,true);
        
        if($mode === 'oneway'){
            if(!empty($parents)){
                // შვილი ← მშობელი
                $src = $p->ID; $tgt = $post->ID; $icon = 'dashicons-arrow-left'; $tip = '←';
            } else {
                // მშობელი → შვილი
                $src = $post->ID; $tgt = $p->ID; $icon = 'dashicons-arrow-right'; $tip = '→';
            }
        } else {
            if($is_child && !$is_parent){
                $src = $post->ID; $tgt = $p->ID; $icon = 'dashicons-arrow-right'; $tip = '→';
            } elseif($is_parent && !$is_child){
                $src = $p->ID; $tgt = $post->ID; $icon = 'dashicons-arrow-left'; $tip = '←';
            } else {
                $src = $p->ID; $tgt = $post->ID; $icon = 'dashicons-admin-site-alt3'; $tip = '↔︎';
            }
        }

        $unlink = wp_nonce_url(
            add_query_arg([
                'action' => 'pr_unlink_single',
                'source_id' => $src,
                'target_id' => $tgt,
                '_wp_http_referer' => rawurlencode(admin_url('post.php?post='.$post->ID.'&action=edit')),
            ], admin_url('admin-post.php')),
            'pr_unlink_single_'.$src.'_'.$tgt
        );

        echo '<li>';
        echo '<a href="'. esc_url(get_edit_post_link($p->ID)) .'" target="_blank">'. esc_html(get_the_title($p)) .'</a>';
        echo ' <span class="dashicons '. esc_attr($icon) .'" title="'. esc_attr($tip) .'"></span>';
        echo ' <span class="pr-rel-delete-icon dashicons dashicons-trash" onclick="if(confirm(\'წავშალო კავშირი?\')) location.href=\''. esc_url($unlink) .'\';"></span>';
        echo '</li>';
    }

    echo '</ul>';
    
    // Add Remove All Connections button
    $remove_all_url = wp_nonce_url(
        add_query_arg([
            'action' => 'pr_remove_all_connections',
            'post_id' => $post->ID,
            '_wp_http_referer' => rawurlencode(admin_url('post.php?post='.$post->ID.'&action=edit')),
        ], admin_url('admin-post.php')),
        'pr_remove_all_'.$post->ID
    );
    
    echo '<div class="pr-rel-remove-all">';
    echo '<a href="'. esc_url($remove_all_url) .'" class="button button-secondary" onclick="return confirm(\'ნამდვილად გსურს ყველა კავშირის მოხსნა? ამ მოქმედების გაუქმება შეუძლებელი იქნება.\')">ყველა კავშირის მოხსნა</a>';
    echo '</div>';
    
    echo '</div>';

    echo "<script>
    (function(){
      var list = document.getElementById('pr-rel-reverse-{$post->ID}');
      var search = document.getElementById('pr-rel-rev-search-{$post->ID}');
      if(!list||!search) return;
      search.addEventListener('input', function(){
        var term = this.value.toLowerCase();
        list.querySelectorAll('li').forEach(function(li){
          li.style.display = li.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
      });
    })();
    </script>";
}


/* -------------------------------------------------
   შენახვა (Security + ორმხრივი სინქი)
------------------------------------------------- */
add_action('save_post', function($pid){
    if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if(!isset($_POST['pr_rel_nonce']) || !wp_verify_nonce($_POST['pr_rel_nonce'],'pr_rel_save')) return;
    if(!current_user_can('edit_post',$pid)) return;

    $old = get_post_meta($pid,'_related_posts',true);
    if(!is_array($old)) $old = [];

    $new = isset($_POST['pr_rel_related']) && is_array($_POST['pr_rel_related'])
        ? array_values(array_unique(array_map('intval', $_POST['pr_rel_related'])))
        : [];

    $new = array_values(array_filter($new, fn($v)=>$v!== (int)$pid));

    $mode = get_option('pr_rel_link_mode','both');

    if($mode === 'both'){
        foreach(array_diff($new,$old) as $rid) pr_rel_add($rid,$pid);
        foreach(array_diff($old,$new) as $rid) pr_rel_remove($rid,$pid);
    }

    if($new) update_post_meta($pid,'_related_posts',$new);
    else delete_post_meta($pid,'_related_posts');
});

/* -------------------------------------------------
   admin-post ქმედება (unlink) — უკან იმავე edit გვერდზე
------------------------------------------------- */
add_action('admin_post_pr_unlink_single', function(){
    $s = isset($_GET['source_id']) ? (int) $_GET['source_id'] : 0;
    $t = isset($_GET['target_id']) ? (int) $_GET['target_id'] : 0;
    if(!$s || !$t) wp_die('დაფიქსირდა შეცდომა.');
    if(!current_user_can('edit_post',$t) || !wp_verify_nonce($_GET['_wpnonce'] ?? '','pr_unlink_single_'.$s.'_'.$t)){
        wp_die('უფლებები აკლია ან nonce არასწორია.');
    }
    $mode = get_option('pr_rel_link_mode','both');
    pr_rel_remove($s,$t);
    if($mode==='both') pr_rel_remove($t,$s);
    $back = isset($_GET['_wp_http_referer']) ? esc_url_raw( wp_unslash($_GET['_wp_http_referer']) ) : admin_url('post.php?post='.$t.'&action=edit');
    wp_safe_redirect($back);
    exit;
});

/* -------------------------------------------------
   admin-post ქმედება (Remove All Connections)
------------------------------------------------- */
add_action('admin_post_pr_remove_all_connections', function(){
    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
    if(!$post_id) wp_die('Invalid request.');
    
    if(!current_user_can('edit_post', $post_id) || 
       !wp_verify_nonce($_GET['_wpnonce'] ?? '','pr_remove_all_'.$post_id)){
        wp_die('Permission denied.');
    }

    $mode = get_option('pr_rel_link_mode','both');
    $related_posts = get_post_meta($post_id, '_related_posts', true);
    if(!is_array($related_posts)) $related_posts = [];

    // Remove forward connections
    foreach($related_posts as $target_id) {
        pr_rel_remove($post_id, $target_id);
        if($mode === 'both') {
            pr_rel_remove($target_id, $post_id);
        }
    }

    // Remove reverse connections (for oneway mode)
    if($mode === 'oneway') {
        $referencing_posts = pr_rel_get_referencing_ids($post_id);
        foreach($referencing_posts as $source_id) {
            pr_rel_remove($source_id, $post_id);
        }
    }

    // Clean up
    delete_post_meta($post_id, '_related_posts');

    $back = isset($_GET['_wp_http_referer']) ? 
        esc_url_raw(wp_unslash($_GET['_wp_http_referer'])) : 
        admin_url('post.php?post='.$post_id.'&action=edit');
        
    wp_safe_redirect($back);
    exit;
});

/* -------------------------------------------------
    Elementor ინტეგრაცია (გაუმჯობესებული დახარისხება)
 ------------------------------------------------- */
 add_action('elementor/query/related_posts', function($q){
     $cid = pr_rel_detect_current_id();
     $rel = $cid ? get_post_meta($cid,'_related_posts',true) : [];
     if(!is_array($rel) || empty($rel)){ $q->set('post__in',[0]); return; }

     $rel_ids = array_map('intval',$rel);
     $q->set('post__in', $rel_ids);

     // Support custom ordering options while maintaining backward compatibility
     // Users can now specify orderby in their Elementor widget:
     // - menu_order (for manual menu order)
     // - date (for date-based ordering)
     // - title (for alphabetical ordering)
     // - modified (for last modified date)
     // - etc.
     $orderby = $q->get('orderby', 'post__in');

     // If orderby is not explicitly set, use post__in for backward compatibility
     if($orderby === 'post__in' || empty($orderby)) {
         $q->set('orderby','post__in');
     } else {
         // For other ordering options, we need to ensure related posts are included
         $q->set('post__in', $rel_ids);
         $q->set('orderby', $orderby);

         // Set order direction if specified (ASC/DESC)
         $order = $q->get('order', 'ASC');
         $q->set('order', $order);
     }
 });
 add_action('elementor/query/referenced_by', function($q){
     $cid = pr_rel_detect_current_id();
     $refs = $cid ? pr_rel_get_referencing_ids($cid) : [];
     if(empty($refs)){ $q->set('post__in',[0]); return; }

     $ref_ids = array_map('intval',$refs);
     $q->set('post__in', $ref_ids);

     // Support custom ordering options while maintaining backward compatibility
     // Same ordering options available as related_posts query
     $orderby = $q->get('orderby', 'post__in');

     // If orderby is not explicitly set, use post__in for backward compatibility
     if($orderby === 'post__in' || empty($orderby)) {
         $q->set('orderby','post__in');
     } else {
         // For other ordering options, we need to ensure referenced posts are included
         $q->set('post__in', $ref_ids);
         $q->set('orderby', $orderby);

         // Set order direction if specified (ASC/DESC)
         $order = $q->get('order', 'ASC');
         $q->set('order', $order);
     }
 });

/* -------------------------------------------------
   ადმინის სვეტი: „უკავშირდებიან“
------------------------------------------------- */
add_action('admin_init', function(){
    foreach(pr_rel_get_ui_post_types() as $type){
        add_filter("manage_edit-{$type}_columns", function($cols){
            $cols['pr_referenced_by'] = 'უკავშირდებიან';
            return $cols;
        });
        add_action("manage_{$type}_posts_custom_column", function($col,$pid){
            if($col==='pr_referenced_by'){
                $c = count(pr_rel_get_referencing_ids($pid));
                echo $c ? '<strong>'.(int)$c.'</strong>' : '—';
            }
        },10,2);
    }
});

/* -------------------------------------------------
   Polylang სინქრონიზაცია
------------------------------------------------- */
add_filter('pll_copy_post_metas', function($keys){
    if(defined('POLYLANG_VERSION')) $keys[] = '_related_posts';
    return $keys;
});