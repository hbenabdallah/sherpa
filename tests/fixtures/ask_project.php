<?php
// Answers ProjectQuestions::ask() from stdin, as a person at the keyboard
// would, and prints the draft it made — so the questions can be tested
// without a terminal. Usage: php ask_project.php <directory>

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Project\ProjectQuestions;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\TUI\LineEditor;

ob_start();
$draft = (new ProjectQuestions(new LineEditor(), new ProjectStore(new StackDetector())))->ask($argv[1]);
ob_end_clean();

echo json_encode([
    'name'      => $draft->name,
    'path'      => $draft->path,
    'docker'    => $draft->docker->enabled,
    'container' => $draft->docker->container,
    'db'        => $draft->docker->dbContainer,
]);
