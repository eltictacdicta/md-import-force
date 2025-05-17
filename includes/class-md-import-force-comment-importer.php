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
            
            // Prevent empty or invalid dates from causing issues with date_query or mysql2date
            if (empty($c_arr['comment_date_gmt']) || $c_arr['comment_date_gmt'] === '0000-00-00 00:00:00') {
                // Fallback to current time if date is invalid, or log an error/skip
                // For now, let's log and potentially skip, or ensure wp_insert_comment handles it.
                // wp_insert_comment will use current_time if comment_date_gmt is empty.
                // We need a valid date for the check below.
                MD_Import_Force_Logger::log_message("MD Import Force [WARN COMMENT]: Comentario para Post ID {$post_id}, Autor {$c_arr['comment_author_email']} tiene fecha GMT inválida: {$c_arr['comment_date_gmt']}. Se usará la fecha actual para la comprobación de duplicados si es necesario.");
                // To prevent issues with mysql2date, ensure a valid format if we were to use it directly for check.
                // However, get_comments is robust enough if the date fields are simply not set or are valid.
            }

            // Check for existing comments to prevent duplicates
            $existing_comments_args = array(
                'post_id' => $post_id,
                'author_email' => $c_arr['comment_author_email'],
                'number' => 5, // Fetch a few to check content
            );

            if (!empty($c_arr['comment_date_gmt']) && $c_arr['comment_date_gmt'] !== '0000-00-00 00:00:00') {
                 $existing_comments_args['date_query'] = array(
                    array(
                        // Using mysql2date to ensure components are extracted correctly from the GMT date string
                        'year'   => mysql2date('Y', $c_arr['comment_date_gmt']),
                        'month'  => mysql2date('m', $c_arr['comment_date_gmt']),
                        'day'    => mysql2date('d', $c_arr['comment_date_gmt']),
                        'hour'   => mysql2date('H', $c_arr['comment_date_gmt']),
                        'minute' => mysql2date('i', $c_arr['comment_date_gmt']),
                        'second' => mysql2date('s', $c_arr['comment_date_gmt']),
                    ),
                );
            }
            
            $possible_duplicates = get_comments($existing_comments_args);
            $is_true_duplicate = false;

            if (!empty($possible_duplicates)) {
                foreach ($possible_duplicates as $possible_duplicate) {
                    // Normalize content for comparison: trim whitespace and convert line endings
                    $normalized_existing_content = trim(str_replace("\r\n", "\n", $possible_duplicate->comment_content));
                    $normalized_new_content = trim(str_replace("\r\n", "\n", $c_arr['comment_content']));

                    if ($normalized_existing_content === $normalized_new_content) {
                        // Additional check: if original comment ID is provided and matches, it's likely an update scenario, not a duplicate insert
                        // However, this plugin seems to always insert. So if content matches, it IS a duplicate.
                        $is_true_duplicate = true;
                        break;
                    }
                }
            }

            if ($is_true_duplicate) {
                MD_Import_Force_Logger::log_message("MD Import Force [SKIP COMMENT]: Comentario duplicado detectado para Post ID {$post_id}, Autor {$c_arr['comment_author_email']}, Fecha GMT {$c_arr['comment_date_gmt']}. Saltando.");
                continue; // Skip inserting this comment
            }

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
