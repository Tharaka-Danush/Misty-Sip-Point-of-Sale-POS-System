<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();
header('Location: '.base_url('admin/products.php').'#manage-categories');
exit;
