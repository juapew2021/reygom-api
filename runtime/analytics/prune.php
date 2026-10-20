<?php
declare(strict_types=1);
require '/var/www/html/src/Config/database.php';
$pdo=getDatabaseConnection();
$pdo->exec("DELETE FROM property_visits WHERE started_at<now()-interval '90 days'");
$pdo->exec('DELETE FROM property_visit_limits WHERE expires_at<now()');
echo "Analytics retention applied.\n";
