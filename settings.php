<?php

defined('MOODLE_INTERNAL') || die;

$ADMIN->add('accounts', new admin_externalpage('tooladvuserbulk', get_string('pluginname', 'tool_advuserbulk'), "$CFG->wwwroot/$CFG->admin/tool/advuserbulk/user_bulk.php", 'tool/advuserbulk:view'));
?>
