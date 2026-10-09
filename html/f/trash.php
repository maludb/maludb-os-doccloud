<?php
declare(strict_types=1);
/** /f/{id}/trash — the folder's Trash tab: the trash screen narrowed to this folder (its managers). */
$_GET['folder'] = $_REQUEST['folder'] = $_GET['id'] ?? $_REQUEST['id'] ?? '';
require dirname(__DIR__) . '/trash/index.php';
