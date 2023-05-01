<?php

return [
    /**
     * -----------------------------------
     * Filename
     * -----------------------------------
     */
    'filename' => '_form_request_phpdoc.php',

    /**
     * -----------------------------------
     * FormRequest class extends class
     * -----------------------------------
     */
    'form_request_extends' => '\Illuminate\Foundation\Http\FormRequest',

    /**
     * -----------------------------------
     * default behavior
     * -----------------------------------
     * false: write to external file.
     * true: write in to FormRequest class file.
     */
    'default_write' => false,

    /**
     * -----------------------------------
     * scan FormRequest class directory
     * -----------------------------------
     */
    'scan_dirs' => [
        'app/Http/Requests',
    ],
];
