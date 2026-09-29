<?php

// Task-local measurement for workflow-task completion requests only.
$qualificationPath = $_SERVER['REQUEST_URI'] ?? '';
if (str_contains($qualificationPath, '/workflow-tasks/')
    && str_ends_with($qualificationPath, '/complete')) {
    register_shutdown_function(static function (): void {
        error_log('qualification_complete_peak_bytes=' . memory_get_peak_usage(true)
            . ' final_bytes=' . memory_get_usage(true));
    });
}
