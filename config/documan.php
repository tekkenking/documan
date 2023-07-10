<?php


return [
    'disk'  =>  '',

    'remote'   =>  [
        'host_url'  =>  '',
        'disk'      =>  ''
    ],

    'addOriginalSize' => true,

    /**
     * Only the dimensions can be changed.
     */
    'defaultImageSizes' =>  [
        'medium'        =>  ['width' => 800, 'height' => 800],
        'thumbnail'     =>  ['width' => 400, 'height' => 400],
        'small'         =>  ['width' => 120, 'height' => 120],
    ],

    'uploadDefaulImageSizes'    =>  [
        'medium'
    ],

    'allowedFileExtensions'  =>  [
        'image'             =>  ['jpg','png','jpeg', 'gif'],
        'excel'             =>  ['xlsx', 'xls', 'csv'],
        'document'          =>  ['doc', 'docx'],
        'powerpoint'        =>  ['ppt', 'pptx'],
        'pdf'               =>  ['pdf']
    ]
];
