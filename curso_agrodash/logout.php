<?php
session_start();
session_destroy();
header("Location: /curso_agrodash/login");
exit;
?>
