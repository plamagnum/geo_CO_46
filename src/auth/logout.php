<?php
/**
 * Вихід з системи
 */

require_once __DIR__ . '/../includes/auth.php';

startSession();
sessionDestroy();
