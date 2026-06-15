<?php
session_start();
session_destroy();
header("Location: /csuweb/login.php");
exit;