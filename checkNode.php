<?php
echo shell_exec('node -v');
echo shell_exec('which node');
echo function_exists('shell_exec') ? 'shell_exec: enabled' : 'shell_exec: disabled';
?>