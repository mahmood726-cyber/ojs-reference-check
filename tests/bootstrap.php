<?php

// Standalone tests: load the plugin's own classes without an OJS installation.
spl_autoload_register(function (string $class): void {
    $prefix = 'APP\\plugins\\generic\\referenceCheck\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
