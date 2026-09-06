<?php
// Answers ProjectQuestions::confirmForget() from stdin and prints the verdict,
// so the one question before an irreversible delete can be tested without a
// terminal. Usage: php confirm_forget.php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\ProjectQuestions;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\TUI\LineEditor;

$project = new Project(
    slug: 'essai', name: 'Essai', path: '/tmp', memoryDb: '/tmp/memory.db',
    docker: new DockerConfig(enabled: false), stack: '',
    createdAt: new DateTimeImmutable(), lastUsedAt: new DateTimeImmutable(),
);

ob_start();
$confirmed = (new ProjectQuestions(new LineEditor(), new ProjectStore(new StackDetector())))->confirmForget($project, 12, 3);
$shown = (string) ob_get_clean();

echo json_encode(['confirmed' => $confirmed, 'shown' => App\TUI\Terminal::plain($shown)]);
