<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_File_Browser {
    public static function list_files() {
        $dir = CMSG_Validation::validate_large_directory(); if (is_wp_error($dir)) return [];
        $allowed = ['mp4','mov','mkv','avi','webm','m4v']; $rows=[];
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue; $path = trailingslashit($dir) . $item; if (!is_file($path)) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); if (!in_array($ext, $allowed, true)) continue;
            $rows[] = ['filename'=>$item,'relative_path'=>$item,'path'=>$path,'size'=>filesize($path),'size_label'=>size_format(filesize($path),2),'modified'=>filemtime($path),'modified_label'=>date_i18n('M j, Y H:i', filemtime($path))];
        }
        usort($rows, function($a,$b){ if($a['modified']===$b['modified']) return strcmp($a['filename'],$b['filename']); return $b['modified'] <=> $a['modified']; });
        return $rows;
    }
}
