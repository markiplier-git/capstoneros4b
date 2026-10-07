<?php 

require_once "csrf.php";
csrf_check();
session_unset();
session_destroy();
header("Location: ../login.php");
exit;
?>