<?php
/**
    * Filename: .php-cs-fixer.dist.php
    * Purpose: Define the QMS PHP layout for first-party application and test sources.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Standardized four-space indentation, vertical braces and one statement per line.
    */
$finder = PhpCsFixer\Finder::Create()
    ->in(__DIR__)
    ->exclude(['vendor', 'assets/vendor'])
    ->notPath('~^(?:codereview|logs|uploads)/~')
    ->notPath('~(^|/)\.[^/]+/~')
    ->name('*.php');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setRules([
        '@PSR12' => true,
        'braces_position' => [
            'allow_single_line_anonymous_functions' => false,
            'allow_single_line_empty_anonymous_classes' => false,
            'anonymous_classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'anonymous_functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'control_structures_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'control_structure_continuation_position' => ['position' => 'next_line'],
        'single_line_empty_body' => false,
        'statement_indentation' => true,
        'no_multiple_statements_per_line' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'single_space_around_construct' => true,
    ])
    ->setFinder($finder);
