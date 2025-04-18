<?php
/**
 * Clase para importar comentarios
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Comment_Importer {

    public function __construct() {
        // Constructor
    }

    /**
     * Importa comentarios para un post
     */
    public function import_comments($post_id, $comments_data) {
        MD_Import_Force_Logger::log_message("MD Import Force: Importando " . count($comments_data) . " comentarios para post ID {$post_id}.");
        foreach ($comments_data as $cdata) {
            $orig_cid = intval($cdata['comment_ID'] ?? 0);
            $c_arr = [
                'comment_post_ID' => $post_id, 
                'comment_author' => $cdata['comment_author'] ?? '', 
                'comment_author_email' => $cdata['comment_author_email'] ?? '',
                'comment_author_url' => $cdata['comment_author_url'] ?? '', 
                'comment_author_IP' => $cdata['comment_author_IP'] ?? '',
                'comment_date' => $cdata['comment_date'] ?? current_time('mysql'),
                'comment_date_gmt' => $cdata['comment_date_gmt'] ?? get_gmt_from_date($cdata['comment_date'] ?? current_time('mysql')),
                'comment_content' => $cdata['comment_content'] ?? '',
                'comment_karma' => intval($cdata['comment_karma'] ?? 0),
                'comment_approved' => $cdata['comment_approved'] ?? '1',
                'comment_agent' => $cdata['comment_agent'] ?? '',
                'comment_type' => $cdata['comment_type'] ?? 'comment',
                'comment_parent' => intval($cdata['comment_parent'] ?? 0),
            ];
            
            $orig_uid = intval($cdata['user_id'] ?? 0); 
            if ($orig_uid > 0 && !empty($c_arr['comment_author_email'])) { 
                $user = get_user_by('email', $c_arr['comment_author_email']); 
                if ($user) $c_arr['user_id'] = $user->ID; 
            }
            
            $cid = wp_insert_comment($c_arr);
            
            if ($cid && !is_wp_error($cid)) { 
                if (!empty($cdata['meta_data'])) {
                    foreach ($cdata['meta_data'] as $k => $v) {
                        update_comment_meta($cid, $k, $v);
                    }
                }
            }
            else { 
                $err = is_wp_error($cid) ? $cid->get_error_message() : 'ID inválido'; 
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Error insertando comment (Orig ID: {$orig_cid}): {$err}"); 
            }
        }
    }
}
