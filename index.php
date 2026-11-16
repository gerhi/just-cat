<?php
// The site root has no public content; test-takers use /t/{slug}.
require __DIR__ . '/lib/bootstrap.php';
redirect(url('admin/index.php'), 302);
