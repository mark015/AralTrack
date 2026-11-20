<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

require_staff('admin');
Templates::download(Templates::studentSheet(), 'learner-import-template.xlsx');
