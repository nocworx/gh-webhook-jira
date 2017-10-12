<?php

declare(strict_types = 1);

$exclude = ['vendor'];
return [
  'minimum_severity' => \Phan\Issue::SEVERITY_CRITICAL,
  'prefer_narrowed_phpdoc_param_type' => false,
  'prefer_narrowed_phpdoc_return_type' => false,
  'dead_code_detection' => true,
  'directory_list' => [
    'src',
    'web',
    'vendor'
  ],
  'exclude_analysis_directory_list' => $exclude
];
