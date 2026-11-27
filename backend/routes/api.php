<?php

/*
| API routes, served under /api/v1 (see bootstrap/app.php).
|
| Each module keeps its own routes in app/Modules/<Module>/routes.php.
| This file only loads them, in alphabetical order, so a module's routes
| are found next to its controllers.
*/

$moduleRoutes = glob(app_path('Modules/*/routes.php')) ?: [];
sort($moduleRoutes);

foreach ($moduleRoutes as $routes) {
    require $routes;
}
