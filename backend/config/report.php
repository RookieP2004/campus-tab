<?php

return [

    // Folder of the Application Report. In the containers docs/ is mounted
    // at /docs; on CI the repository layout puts it at ../docs/report.
    'path' => env('REPORT_PATH', base_path('../docs/report')),

];
