<?php
require_once __DIR__ . '/../includes/auth.php';

function password_meets_policy(string $password): bool {
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{6,72}$/', $password) === 1;
}

$cases = [
    'Abc123!' => true,
    'abc123!' => false,
    'ABC123!' => false,
    'Abcdef!' => false,
    'Abc123' => false,
    'Abc12345' => false,
    'Abc123!@#' => true,
];

foreach ($cases as $password => $expected) {
    $actual = password_meets_policy($password);
    echo $password . ' => ' . ($actual ? 'PASS' : 'FAIL') . "\n";
}
