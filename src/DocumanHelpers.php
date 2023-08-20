<?php

if(! function_exists('documan')) {
    function documan(string $disk = '') {
        return app('documan', [$disk]);
    }
}

